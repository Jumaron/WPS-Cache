<?php
declare(strict_types=1);

namespace WPSCache\Admin\Tools;

/**
 * Handles settings import and export functionality
 */
class ImportExportTools {
    private const ALLOWED_MIME_TYPES = [
        'application/json',
        'text/plain'
    ];

    /**
     * Renders import/export interface
     */
    public function renderImportExport(): void {
        ?>
        <div class="wpsc-import-export">
            <!-- Export Section -->
            <div class="wpsc-tool-box">
                <h4><?php _e('Export Settings', 'wps-cache'); ?></h4>
                <p class="description">
                    <?php _e('Export your current cache configuration settings.', 'wps-cache'); ?>
                </p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('wpsc_export_settings'); ?>
                    <input type="hidden" name="action" value="wpsc_export_settings">
                    <button type="submit" class="button button-secondary">
                        <?php _e('Export Settings', 'wps-cache'); ?>
                    </button>
                </form>
            </div>

            <!-- Import Section -->
            <div class="wpsc-tool-box">
                <h4><?php _e('Import Settings', 'wps-cache'); ?></h4>
                <p class="description">
                    <?php _e('Import cache configuration settings from a file.', 'wps-cache'); ?>
                </p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" 
                      enctype="multipart/form-data" class="wpsc-import-form">
                    <?php wp_nonce_field('wpsc_import_settings'); ?>
                    <input type="hidden" name="action" value="wpsc_import_settings">
                    <input type="file" name="settings_file" accept=".json"
                           class="wpsc-file-input">
                    <button type="submit" class="button button-secondary">
                        <?php _e('Import Settings', 'wps-cache'); ?>
                    </button>
                </form>
            </div>

            <!-- Backup Management -->
            <div class="wpsc-tool-box">
                <h4><?php _e('Backup Management', 'wps-cache'); ?></h4>
                <?php $this->renderBackupManagement(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handles settings export
     */
    public function exportSettings(): void {
        try {
            $settings = get_option('wpsc_settings');
            if (!$settings) {
                throw new \Exception(__('No settings found to export.', 'wps-cache'));
            }

            $export_data = $this->prepareExportData($settings);
            $filename = $this->generateExportFilename();

            $this->sendExportHeaders($filename);
            echo json_encode($export_data, JSON_PRETTY_PRINT);
            exit;

        } catch (\Exception $e) {
            wp_die($e->getMessage());
        }
    }

    /**
     * Handles settings import
     */
    public function importSettings(): array {
        try {
            if (!isset($_FILES['settings_file'])) {
                throw new \Exception('No file uploaded');
            }

            $file = $_FILES['settings_file'];
            
            // Validate file upload
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception($this->getFileUploadError($file['error']));
            }

            // Validate file type
            $this->validateUploadedFile($file);

            // Read and validate file contents
            $import_data = $this->readImportFile($file['tmp_name']);
            
            // Validate and sanitize settings
            $settings = $this->validateImportData($import_data);

            // Create backup before import
            $this->createSettingsBackup();

            // Update settings
            update_option('wpsc_settings', $settings);

            return [
                'status' => 'success',
                'message' => __('Settings imported successfully.', 'wps-cache')
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => 'invalid',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Prepares data for export
     */
    private function prepareExportData(array $settings): array {
        return [
            'settings' => $settings,
            'version' => WPSC_VERSION,
            'timestamp' => current_time('timestamp'),
            'site_url' => get_site_url(),
            'wp_version' => get_bloginfo('version'),
            'is_multisite' => is_multisite(),
            'active_plugins' => get_option('active_plugins'),
        ];
    }

    /**
     * Generates export filename
     */
    private function generateExportFilename(): string {
        $site_name = sanitize_title(get_bloginfo('name'));
        $date = date('Y-m-d-His');
        return "wps-cache-{$site_name}-{$date}.json";
    }

    /**
     * Sends export HTTP headers
     */
    private function sendExportHeaders(string $filename): void {
        nocache_headers();
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
    }

    /**
     * Validates uploaded file
     */
    private function validateUploadedFile(array $file): void {
        // Check file size (5MB max)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new \Exception(__('File size exceeds maximum limit of 5MB.', 'wps-cache'));
        }

        // Check MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);
        
        if (!in_array($mime_type, self::ALLOWED_MIME_TYPES)) {
            throw new \Exception(__('Invalid file type. Only JSON files are allowed.', 'wps-cache'));
        }
    }

    /**
     * Reads and decodes import file
     */
    private function readImportFile(string $file): array {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \Exception(__('Failed to read import file.', 'wps-cache'));
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(__('Invalid JSON format in import file.', 'wps-cache'));
        }

        return $data;
    }

    /**
     * Validates import data structure and content
     */
    private function validateImportData(array $data): array {
        if (!isset($data['settings'], $data['version'])) {
            throw new \Exception(__('Invalid settings file format.', 'wps-cache'));
        }

        // Validate settings structure
        $required_keys = ['html_cache', 'redis_cache', 'varnish_cache', 'cache_lifetime'];
        foreach ($required_keys as $key) {
            if (!isset($data['settings'][$key])) {
                throw new \Exception(sprintf(
                    __('Missing required setting: %s', 'wps-cache'),
                    $key
                ));
            }
        }

        // Version compatibility check
        if (version_compare($data['version'], WPSC_VERSION, '>')) {
            throw new \Exception(__('Settings file is from a newer version of the plugin.', 'wps-cache'));
        }

        return $data['settings'];
    }

    /**
     * Creates a backup of current settings
     */
    private function createSettingsBackup(): void {
        $current_settings = get_option('wpsc_settings');
        $backups = get_option('wpsc_settings_backups', []);
        
        // Add new backup
        $backups[] = [
            'timestamp' => current_time('timestamp'),
            'settings' => $current_settings,
            'version' => WPSC_VERSION
        ];

        // Keep only last 5 backups
        $backups = array_slice($backups, -5);
        
        update_option('wpsc_settings_backups', $backups);
    }

    /**
     * Renders backup management interface
     */
    private function renderBackupManagement(): void {
        $backups = get_option('wpsc_settings_backups', []);
        
        if (empty($backups)) {
            ?>
            <p class="description">
                <?php _e('No backups available.', 'wps-cache'); ?>
            </p>
            <?php
            return;
        }

        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e('Date', 'wps-cache'); ?></th>
                    <th><?php _e('Version', 'wps-cache'); ?></th>
                    <th><?php _e('Actions', 'wps-cache'); ?></th>
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
                                <?php _e('Restore', 'wps-cache'); ?>
                            </button>
                            <button type="button" class="button button-small wpsc-download-backup"
                                    data-backup="<?php echo esc_attr($index); ?>">
                                <?php _e('Download', 'wps-cache'); ?>
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
    private function getFileUploadError(int $error_code): string {
        return match($error_code) {
            UPLOAD_ERR_INI_SIZE => __('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'wps-cache'),
            UPLOAD_ERR_FORM_SIZE => __('The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.', 'wps-cache'),
            UPLOAD_ERR_PARTIAL => __('The uploaded file was only partially uploaded.', 'wps-cache'),
            UPLOAD_ERR_NO_FILE => __('No file was uploaded.', 'wps-cache'),
            UPLOAD_ERR_NO_TMP_DIR => __('Missing a temporary folder.', 'wps-cache'),
            UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'wps-cache'),
            UPLOAD_ERR_EXTENSION => __('A PHP extension stopped the file upload.', 'wps-cache'),
            default => __('Unknown upload error.', 'wps-cache')
        };
    }
}