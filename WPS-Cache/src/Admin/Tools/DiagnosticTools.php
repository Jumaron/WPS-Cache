<?php

declare(strict_types=1);

namespace WPSCache\Admin\Tools;

/**
 * Handles system diagnostics and debugging tools
 */
class DiagnosticTools
{
    /**
     * Renders the diagnostics interface
     */
    public function renderDiagnostics(): void
    {
?>
        <!-- System Info Card -->
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <h2><?php esc_html_e('System Information', 'wps-cache'); ?></h2>
            </div>
            <div class="wpsc-card-body">
                <textarea readonly class="wpsc-textarea" rows="10" style="font-family: monospace; font-size: 12px; width: 100%; background: #1f2937; color: #e5e7eb; border: none; padding: 1rem; border-radius: 6px;">
<?php echo esc_textarea($this->getFormattedDiagnosticInfo()); ?>
                </textarea>
                <div style="margin-top: 1rem;">
                    <button type="button" class="button wpsc-btn-secondary" onclick="navigator.clipboard.writeText(this.parentElement.previousElementSibling.value); alert('Copied to clipboard!');">
                        <span class="dashicons dashicons-clipboard" style="vertical-align: middle;"></span> Copy to Clipboard
                    </button>
                </div>
            </div>
        </div>

        <!-- Connectivity Tests Card -->
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <h2><?php esc_html_e('Connectivity Tests', 'wps-cache'); ?></h2>
            </div>
            <div class="wpsc-card-body">
                <p class="wpsc-setting-desc" style="margin-bottom: 1rem;">Check connections to external services and file permissions.</p>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="button wpsc-btn-secondary" id="wpsc-test-redis">Test Redis</button>
                    <button type="button" class="button wpsc-btn-secondary" id="wpsc-test-varnish">Test Varnish</button>
                    <button type="button" class="button wpsc-btn-secondary" id="wpsc-test-permissions">Test Permissions</button>
                </div>
                <div id="wpsc-test-results" style="display: none; margin-top: 15px; padding: 15px; background: #f3f4f6; border-radius: 6px; border: 1px solid #e5e7eb;"></div>
            </div>
        </div>

        <!-- Error Log Card -->
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <h2><?php esc_html_e('Error Log', 'wps-cache'); ?></h2>
            </div>
            <div class="wpsc-card-body">
                <?php $this->renderErrorLog(); ?>
            </div>
        </div>

        <!-- Debug Controls Card -->
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <h2><?php esc_html_e('Debug Controls', 'wps-cache'); ?></h2>
            </div>
            <div class="wpsc-card-body">
                <?php $this->renderDebugControls(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renders error log viewer
     */
    private function renderErrorLog(): void
    {
        $log_file = WP_CONTENT_DIR . '/wps-cache-debug.log';

        if (file_exists($log_file)) {
            $content = file_get_contents($log_file);
            // Limit to last 50 lines to prevent memory issues
            $lines = explode("\n", $content);
            $content = implode("\n", array_slice($lines, -50));
        ?>
            <textarea readonly class="wpsc-textarea" rows="10" style="font-family: monospace; font-size: 12px; width: 100%; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 1rem; margin-bottom: 1rem; border-radius: 6px;">
<?php echo esc_textarea($content); ?>
            </textarea>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="button wpsc-btn-secondary" id="wpsc-download-log">
                    <span class="dashicons dashicons-download" style="vertical-align: middle;"></span> Download
                </button>
                <button type="button" class="button wpsc-btn-secondary" id="wpsc-clear-log" style="color: var(--wpsc-danger);">
                    <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span> Clear Log
                </button>
            </div>
        <?php
        } else {
        ?>
            <div class="wpsc-notice success" style="margin: 0;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-yes"></span>
                    <span><?php esc_html_e('No error log file found (wps-cache-debug.log). This usually means no errors have occurred.', 'wps-cache'); ?></span>
                </div>
            </div>
        <?php
        }
    }

    /**
     * Renders debug control interface
     */
    private function renderDebugControls(): void
    {
        $debug_enabled = (bool) get_option('wpsc_debug_mode', false);
        ?>
        <div class="wpsc-setting-row" style="border: none; padding: 0;">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label"><?php esc_html_e('Enable Debug Mode', 'wps-cache'); ?></label>
                <p class="wpsc-setting-desc"><?php esc_html_e('Enables detailed logging to wp-content/wps-cache-debug.log.', 'wps-cache'); ?></p>
            </div>
            <div class="wpsc-setting-control">
                <label class="wpsc-switch">
                    <input type="checkbox" id="wpsc-enable-debug" name="wpsc_debug_mode" value="1" <?php checked($debug_enabled); ?>>
                    <span class="wpsc-slider"></span>
                </label>
            </div>
        </div>

        <div style="margin-top: 1rem; border-top: 1px solid #f3f4f6; padding-top: 1rem;">
            <button type="button" class="button wpsc-btn-primary" id="wpsc-generate-report">
                <?php esc_html_e('Generate Support Report', 'wps-cache'); ?>
            </button>
        </div>
<?php
    }

    /**
     * Gets formatted diagnostic information
     */
    private function getFormattedDiagnosticInfo(): string
    {
        $info   = $this->getDiagnosticInfo();
        $output = '';

        foreach ($info as $section => $data) {
            $output .= "=== {$section} ===\n";
            foreach ($data as $key => $value) {
                $output .= sprintf("%-25s: %s\n", $key, $value);
            }
            $output .= "\n";
        }

        return $output;
    }

    /**
     * Gets diagnostic information
     */
    public function getDiagnosticInfo(): array
    {
        return [
            'System'         => $this->getSystemInfo(),
            'WordPress'      => $this->getWordPressInfo(),
            'Cache'          => $this->getCacheInfo(),
            'Server'         => $this->getServerInfo(),
            'PHP'            => $this->getPHPInfo(),
            'Database'       => $this->getDatabaseInfo(),
            'Active Plugins' => $this->getActivePlugins(),
        ];
    }

    private function getSystemInfo(): array
    {
        return [
            'OS'                 => PHP_OS,
            'Architecture'       => PHP_INT_SIZE * 8 . 'bit',
            'Memory Limit'       => ini_get('memory_limit'),
            'Max Execution Time' => ini_get('max_execution_time') . 's',
            'Time Zone'          => date_default_timezone_get(),
            'System Time'        => current_time('mysql'),
        ];
    }

    private function getWordPressInfo(): array
    {
        global $wp_version;

        return [
            'Version'         => $wp_version,
            'Site URL'        => get_site_url(),
            'Home URL'        => get_home_url(),
            'Is Multisite'    => is_multisite() ? 'Yes' : 'No',
            'Theme'           => wp_get_theme()->get('Name'),
            'Theme Version'   => wp_get_theme()->get('Version'),
            'WP_DEBUG'        => defined('WP_DEBUG') && WP_DEBUG ? 'Yes' : 'No',
            'WP Memory Limit' => WP_MEMORY_LIMIT,
        ];
    }

    private function getCacheInfo(): array
    {
        if (!function_exists('WP_Filesystem')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        WP_Filesystem();
        global $wp_filesystem;

        $cache_dir = defined('WPSC_CACHE_DIR') ? WPSC_CACHE_DIR : WP_CONTENT_DIR . '/cache/wps-cache/';
        $cache_writable = $wp_filesystem->is_writable($cache_dir) ? 'Yes' : 'No';

        return [
            'Plugin Version'      => WPSC_VERSION,
            'Cache Directory'     => $cache_dir,
            'Cache Writable'      => $cache_writable,
            'Object Cache Dropin' => file_exists(WP_CONTENT_DIR . '/object-cache.php') ? 'Installed' : 'Not Installed',
            'Redis Extension'     => extension_loaded('redis') ? 'Yes' : 'No',
            'OPcache Status'      => function_exists('opcache_get_status') && opcache_get_status() !== false ? 'Active' : 'Inactive',
        ];
    }

    private function getServerInfo(): array
    {
        return [
            'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'SSL/TLS'         => is_ssl() ? 'Yes' : 'No',
            'Server IP'       => $_SERVER['SERVER_ADDR'] ?? 'Unknown',
            'Document Root'   => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
        ];
    }

    private function getPHPInfo(): array
    {
        return [
            'Version'          => PHP_VERSION,
            'SAPI'             => php_sapi_name(),
            'Max Input Vars'   => ini_get('max_input_vars'),
            'Post Max Size'    => ini_get('post_max_size'),
            'Upload Max Size'  => ini_get('upload_max_filesize'),
        ];
    }

    private function getDatabaseInfo(): array
    {
        global $wpdb;
        return [
            'Version'      => $wpdb->db_version(),
            'Charset'      => $wpdb->charset,
            'Collate'      => $wpdb->collate,
            'Table Prefix' => $wpdb->prefix,
        ];
    }

    private function getActivePlugins(): array
    {
        $active_plugins = get_option('active_plugins');
        $plugin_info    = [];

        foreach ($active_plugins as $plugin) {
            if (file_exists(WP_PLUGIN_DIR . '/' . $plugin)) {
                $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
                $plugin_info[$data['Name']] = $data['Version'];
            }
        }

        return $plugin_info;
    }
}
