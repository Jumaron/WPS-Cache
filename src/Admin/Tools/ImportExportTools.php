<?php

declare(strict_types=1);

namespace WPSCache\Admin\Tools;

/**
 * Handles settings import and export functionality
 */
class ImportExportTools
{
    private const ALLOWED_MIME_TYPES = [
        'application/json',
        'text/plain'
    ];

    /**
     * Renders import/export interface
     */
    public function renderImportExport(): void
    {
?>
        <div class="wpsc-import-export">
            <!-- Export Section -->
            <div class="wpsc-tool-box">
                <h4><?php esc_html_e('Export Settings', 'WPS-Cache'); ?></h4>
                <p class="description">
                    <?php esc_html_e('Export your current cache configuration settings.', 'WPS-Cache'); ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wpsc_export_settings'); ?>
                    <input type="hidden" name="action" value="wpsc_export_settings">
                    <button type="submit" class="button button-secondary">
                        <?php esc_html_e('Export Settings', 'WPS-Cache'); ?>
                    </button>
                </form>
            </div>

            <!-- Import Section -->
            <div class="wpsc-tool-box">
                <h4><?php esc_html_e('Import Settings', 'WPS-Cache'); ?></h4>
                <p class="description">
                    <?php esc_html_e('Import cache configuration settings from a file.', 'WPS-Cache'); ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                    enctype="multipart/form-data" class="wpsc-import-form">
                    <?php wp_nonce_field('wpsc_import_settings'); ?>
                    <input type="hidden" name="action" value="wpsc_import_settings">
                    <input type="file" name="settings_file" accept=".json"
                        class="wpsc-file-input">
                    <button type="submit" class="button button-secondary">
                        <?php esc_html_e('Import Settings', 'WPS-Cache'); ?>
                    </button>
                </form>
            </div>

            <!-- Backup Management -->
            <div class="wpsc-tool-box">
                <h4><?php esc_html_e('Backup Management', 'WPS-Cache'); ?></h4>
                <?php $this->renderBackupManagement(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handles settings export
     */
    public function exportSettings(): void
    {
        try {
            $settings = get_option('wpsc_settings');
            if (!$settings) {
                throw new \Exception(esc_html__('No settings found to export.', 'WPS-Cache'));
            }

            $export_data = $this->prepareExportData($settings);
            $filename = $this->generateExportFilename();

            $this->sendExportHeaders($filename);
            echo json_encode($export_data, JSON_PRETTY_PRINT);
            exit;
        } catch (\Exception $e) {
            wp_die(esc_html($e->getMessage()));
        }
    }

    /**
     * Handles settings import
     */
    public function importSettings(): array
    {
        try {
            // Verify nonce for security
            check_admin_referer('wpsc_import_settings');

            // Check if the file is provided in $_FILES
            if (empty($_FILES['settings_file'])) {
                throw new \Exception('No file uploaded');
            }

            $file = [
                'name'     => isset($_FILES['settings_file']['name'])
                    ? sanitize_file_name(wp_unslash($_FILES['settings_file']['name']))
                    : '',
                'type'     => isset($_FILES['settings_file']['type'])
                    ? sanitize_text_field(wp_unslash($_FILES['settings_file']['type']))
                    : '',
                'tmp_name' => isset($_FILES['settings_file']['tmp_name'])
                    ? sanitize_text_field(wp_unslash($_FILES['settings_file']['tmp_name']))
                    : '',
                'error'    => isset($_FILES['settings_file']['error'])
                    ? absint($_FILES['settings_file']['error'])
                    : UPLOAD_ERR_NO_FILE,
                'size'     => isset($_FILES['settings_file']['size'])
                    ? absint($_FILES['settings_file']['size'])
                    : 0,
            ];


            // Validate file upload error
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception($this->getFileUploadError($file['error']));
            }

            // Validate file type and size
            $this->validateUploadedFile($file);

            // Read and validate file contents
            $import_data = $this->readImportFile($file['tmp_name']);

            // Validate and sanitize settings
            $settings = $this->validateImportData($import_data);

            // Create a backup before import
            $this->createSettingsBackup();

            // Update settings
            update_option('wpsc_settings', $settings);

            return [
                'status'  => 'success',
                'message' => esc_html__('Settings imported successfully.', 'WPS-Cache')
            ];
        } catch (\Exception $e) {
            return [
                'status'  => 'error',
                'error'   => 'invalid',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Prepares data for export
     */
    private function prepareExportData(array $settings): array
    {
        return [
            'settings'       => $settings,
            'version'        => WPSC_VERSION,
            'timestamp'      => current_time('timestamp'),
            'site_url'       => get_site_url(),
            'wp_version'     => get_bloginfo('version'),
            'is_multisite'   => is_multisite(),
            'active_plugins' => get_option('active_plugins'),
        ];
    }

    /**
     * Generates export filename
     */
    private function generateExportFilename(): string
    {
        $site_name = sanitize_title(get_bloginfo('name'));
        // Use gmdate() to avoid runtime timezone changes affecting date display
        $date = gmdate('Y-m-d-His');
        return "WPS-Cache-{$site_name}-{$date}.json";
    }

    /**
     * Sends export HTTP headers
     */
    private function sendExportHeaders(string $filename): void
    {
        nocache_headers();
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
    }

    /**
     * Validates uploaded file
     */
    private function validateUploadedFile(array $file): void
    {
        // Check file size (5MB max)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new \Exception(esc_html__('File size exceeds maximum limit of 5MB.', 'WPS-Cache'));
        }

        // Check MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);

        if (!in_array($mime_type, self::ALLOWED_MIME_TYPES)) {
            throw new \Exception(esc_html__('Invalid file type. Only JSON files are allowed.', 'WPS-Cache'));
        }
    }

    /**
     * Reads and decodes import file
     */
    private function readImportFile(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \Exception(esc_html__('Failed to read import file.', 'WPS-Cache'));
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(esc_html__('Invalid JSON format in import file.', 'WPS-Cache'));
        }

        return $data;
    }

    /**
     * Validates import data structure and content
     */
    private function validateImportData(array $data): array
    {
        if (!isset($data['settings'], $data['version'])) {
            throw new \Exception(esc_html__('Invalid settings file format.', 'WPS-Cache'));
        }

        // Validate settings structure
        $required_keys = ['html_cache', 'redis_cache', 'varnish_cache', 'cache_lifetime'];
        foreach ($required_keys as $key) {
            if (!isset($data['settings'][$key])) {
                throw new \Exception(sprintf(
                    /* translators: %s: Name of the missing required setting */
                    esc_html__('Missing required setting: %s', 'WPS-Cache'),
                    esc_html($key)
                ));
            }
        }

        // Version compatibility check
        if (version_compare($data['version'], WPSC_VERSION, '>')) {
            throw new \Exception(esc_html__('Settings file is from a newer version of the plugin.', 'WPS-Cache'));
        }

        return $data['settings'];
    }

    /**
     * Creates a backup of current settings
     */
    private function createSettingsBackup(): void
    {
        $current_settings = get_option('wpsc_settings');
        $backups = get_option('wpsc_settings_backups', []);

        // Add new backup
        $backups[] = [
            'timestamp' => current_time('timestamp'),
            'settings'  => $current_settings,
            'version'   => WPSC_VERSION
        ];

        // Keep only last 5 backups
        $backups = array_slice($backups, -5);

        update_option('wpsc_settings_backups', $backups);
    }

    /**
     * Renders backup management interface
     */
    private function renderBackupManagement(): void
    {
        $backups = get_option('wpsc_settings_backups', []);

        if (empty($backups)) {
        ?>
            <p class="description">
                <?php esc_html_e('No backups available.', 'WPS-Cache'); ?>
            </p>
        <?php
            return;
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'WPS-Cache'); ?></th>
                    <th><?php esc_html_e('Version', 'WPS-Cache'); ?></th>
                    <th><?php esc_html_e('Actions', 'WPS-Cache'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($backups) as $index => $backup): ?>
                    <tr>
                        <td>
                            <?php echo esc_html(
                                wp_date(
                                    get_option('date_format') . ' ' . get_option('time_format'),
                                    $backup['timestamp']
                                )
                            ); ?>
                        </td>
                        <td><?php echo esc_html($backup['version']); ?></td>
                        <td>
                            <button type="button" class="button button-small wpsc-restore-backup"
                                data-backup="<?php echo esc_attr($index); ?>">
                                <?php esc_html_e('Restore', 'WPS-Cache'); ?>
                            </button>
                            <button type="button" class="button button-small wpsc-download-backup"
                                data-backup="<?php echo esc_attr($index); ?>">
                                <?php esc_html_e('Download', 'WPS-Cache'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
<?php
    }

    /**
     * Gets file upload error message
     */
    private function getFileUploadError(int $error_code): string
    {
        return match ($error_code) {
            UPLOAD_ERR_INI_SIZE   => esc_html__('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'WPS-Cache'),
            UPLOAD_ERR_FORM_SIZE  => esc_html__('The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.', 'WPS-Cache'),
            UPLOAD_ERR_PARTIAL    => esc_html__('The uploaded file was only partially uploaded.', 'WPS-Cache'),
            UPLOAD_ERR_NO_FILE    => esc_html__('No file was uploaded.', 'WPS-Cache'),
            UPLOAD_ERR_NO_TMP_DIR => esc_html__('Missing a temporary folder.', 'WPS-Cache'),
            UPLOAD_ERR_CANT_WRITE => esc_html__('Failed to write file to disk.', 'WPS-Cache'),
            UPLOAD_ERR_EXTENSION  => esc_html__('A PHP extension stopped the file upload.', 'WPS-Cache'),
            default               => esc_html__('Unknown upload error.', 'WPS-Cache')
        };
    }
}
