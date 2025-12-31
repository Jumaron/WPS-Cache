<?php

declare(strict_types=1);

namespace WPSCache\Admin\Tools;
use WPSCache\Admin\Settings\SettingsValidator;

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
        <div class="wpsc-stats-grid" style="margin-bottom: 2rem;">
            <!-- Export Section -->
            <div class="wpsc-card" style="margin-bottom: 0;">
                <div class="wpsc-card-header">
                    <h2><?php esc_html_e('Export', 'wps-cache'); ?></h2>
                </div>
                <div class="wpsc-card-body">
                    <p class="wpsc-setting-desc" style="margin-bottom: 1rem;">
                        <?php esc_html_e('Export your current cache configuration settings to a JSON file.', 'wps-cache'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wpsc_export_settings'); ?>
                        <input type="hidden" name="action" value="wpsc_export_settings">
                        <button type="submit" class="button wpsc-btn-secondary">
                            <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                            <?php esc_html_e('Export Settings', 'wps-cache'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Import Section -->
            <div class="wpsc-card" style="margin-bottom: 0;">
                <div class="wpsc-card-header">
                    <h2><?php esc_html_e('Import', 'wps-cache'); ?></h2>
                </div>
                <div class="wpsc-card-body">
                    <p class="wpsc-setting-desc" style="margin-bottom: 1rem;">
                        <?php esc_html_e('Upload a previously exported JSON configuration file.', 'wps-cache'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                        enctype="multipart/form-data" class="wpsc-import-form">
                        <?php wp_nonce_field('wpsc_import_settings'); ?>
                        <input type="hidden" name="action" value="wpsc_import_settings">

                        <div style="display: flex; gap: 0.5rem; flex-direction: column;">
                            <input type="file" name="settings_file" accept=".json" class="wpsc-input-text" style="padding: 0.4rem; width: 100%;">
                            <button type="submit" class="button wpsc-btn-primary" style="width: 100%;">
                                <?php esc_html_e('Import Settings', 'wps-cache'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Backup Management -->
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <h2><?php esc_html_e('Configuration Backups', 'wps-cache'); ?></h2>
            </div>
            <div class="wpsc-card-body">
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
                throw new \Exception(esc_html__('No settings found to export.', 'wps-cache'));
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
            check_admin_referer('wpsc_import_settings');

            if (empty($_FILES['settings_file'])) {
                throw new \Exception('No file uploaded');
            }

            $file = $_FILES['settings_file'];

            // Validate file upload error
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception($this->getFileUploadError($file['error']));
            }

            // Validate file type and size
            $this->validateUploadedFile($file);

            // Read and validate file contents
            $import_data = $this->readImportFile($file['tmp_name']);

            // Validate structure
            $raw_settings = $this->validateImportData($import_data);

            // Sentinel Fix: Strictly sanitize imported settings to prevent injection
            // Use the same validator as the settings form to ensure consistency and security.
            $validator = new SettingsValidator();
            $settings = $validator->sanitizeSettings($raw_settings);

            // Create a backup before import
            $this->createSettingsBackup();

            // Update settings
            update_option('wpsc_settings', $settings);

            return [
                'status'  => 'success',
                'message' => esc_html__('Settings imported successfully.', 'wps-cache')
            ];
        } catch (\Exception $e) {
            return [
                'status'  => 'error',
                'error'   => 'invalid',
                'message' => $e->getMessage()
            ];
        }
    }

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

    private function generateExportFilename(): string
    {
        $site_name = sanitize_title(get_bloginfo('name'));
        $date = gmdate('Y-m-d-His');
        return "wps-cache-{$site_name}-{$date}.json";
    }

    private function sendExportHeaders(string $filename): void
    {
        nocache_headers();
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
    }

    private function validateUploadedFile(array $file): void
    {
        // Check file size (5MB max)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new \Exception(esc_html__('File size exceeds maximum limit of 5MB.', 'wps-cache'));
        }

        // Check MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);

        if (!in_array($mime_type, self::ALLOWED_MIME_TYPES)) {
            // Some servers return application/octet-stream for .json files upload
            if ($mime_type !== 'application/octet-stream') {
                throw new \Exception(esc_html__('Invalid file type. Only JSON files are allowed.', 'wps-cache'));
            }
        }
    }

    private function readImportFile(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \Exception(esc_html__('Failed to read import file.', 'wps-cache'));
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(esc_html__('Invalid JSON format in import file.', 'wps-cache'));
        }

        return $data;
    }

    private function validateImportData(array $data): array
    {
        if (!isset($data['settings'], $data['version'])) {
            throw new \Exception(esc_html__('Invalid settings file format.', 'wps-cache'));
        }

        // Validate settings structure
        $required_keys = ['html_cache', 'cache_lifetime'];
        foreach ($required_keys as $key) {
            if (!isset($data['settings'][$key])) {
                throw new \Exception(sprintf(esc_html__('Missing required setting: %s', 'wps-cache'), esc_html($key)));
            }
        }

        return $data['settings'];
    }

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

    private function renderBackupManagement(): void
    {
        $backups = get_option('wpsc_settings_backups', []);

        if (empty($backups)) {
        ?>
            <p class="wpsc-setting-desc"><?php esc_html_e('No backups available.', 'wps-cache'); ?></p>
        <?php
            return;
        }
        ?>
        <table class="widefat striped" style="box-shadow: none; border: 1px solid var(--wpsc-border);">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'wps-cache'); ?></th>
                    <th><?php esc_html_e('Version', 'wps-cache'); ?></th>
                    <th><?php esc_html_e('Actions', 'wps-cache'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($backups) as $index => $backup): ?>
                    <tr>
                        <td>
                            <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $backup['timestamp'])); ?>
                        </td>
                        <td><?php echo esc_html($backup['version']); ?></td>
                        <td>
                            <button type="button" class="button wpsc-btn-secondary" disabled title="Coming soon">
                                <?php esc_html_e('Restore', 'wps-cache'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
<?php
    }

    private function getFileUploadError(int $error_code): string
    {
        return match ($error_code) {
            UPLOAD_ERR_INI_SIZE   => esc_html__('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'wps-cache'),
            UPLOAD_ERR_FORM_SIZE  => esc_html__('The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.', 'wps-cache'),
            UPLOAD_ERR_PARTIAL    => esc_html__('The uploaded file was only partially uploaded.', 'wps-cache'),
            UPLOAD_ERR_NO_FILE    => esc_html__('No file was uploaded.', 'wps-cache'),
            UPLOAD_ERR_NO_TMP_DIR => esc_html__('Missing a temporary folder.', 'wps-cache'),
            UPLOAD_ERR_CANT_WRITE => esc_html__('Failed to write file to disk.', 'wps-cache'),
            UPLOAD_ERR_EXTENSION  => esc_html__('A PHP extension stopped the file upload.', 'wps-cache'),
            default               => esc_html__('Unknown upload error.', 'wps-cache')
        };
    }
}
