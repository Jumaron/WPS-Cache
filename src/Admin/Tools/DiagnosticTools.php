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
        <div class="wpsc-diagnostics-container">
            <p class="description">
                <?php esc_html_e('System information and diagnostic details for troubleshooting.', 'wps-cache'); ?>
            </p>

            <!-- System Information -->
            <div class="wpsc-diagnostic-section">
                <h4><?php esc_html_e('System Information', 'wps-cache'); ?></h4>
                <textarea readonly class="large-text code" rows="10">
<?php echo esc_textarea($this->getFormattedDiagnosticInfo()); ?>
                </textarea>
            </div>

            <!-- Cache Test Tools -->
            <div class="wpsc-diagnostic-section">
                <h4><?php esc_html_e('Cache Tests', 'wps-cache'); ?></h4>
                <?php $this->renderCacheTests(); ?>
            </div>

            <!-- Error Log Viewer -->
            <div class="wpsc-diagnostic-section">
                <h4><?php esc_html_e('Error Log', 'wps-cache'); ?></h4>
                <?php $this->renderErrorLog(); ?>
            </div>

            <!-- Debug Controls -->
            <div class="wpsc-diagnostic-section">
                <h4><?php esc_html_e('Debug Controls', 'wps-cache'); ?></h4>
                <?php $this->renderDebugControls(); ?>
            </div>
        </div>
    <?php
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
     * Gets system information
     */
    private function getSystemInfo(): array
    {
        return [
            'OS'                 => PHP_OS,
            'Architecture'       => PHP_INT_SIZE * 8 . 'bit',
            'Memory Limit'       => ini_get('memory_limit'),
            'Max Execution Time' => ini_get('max_execution_time') . 's',
            'Max Input Vars'     => ini_get('max_input_vars'),
            'Post Max Size'      => ini_get('post_max_size'),
            'Upload Max Size'    => ini_get('upload_max_filesize'),
            'Time Zone'          => date_default_timezone_get(),
            'System Time'        => current_time('mysql'),
            'Temp Directory'     => sys_get_temp_dir(),
        ];
    }

    /**
     * Gets WordPress information
     */
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
            'WP_DEBUG_LOG'    => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'Yes' : 'No',
            'SCRIPT_DEBUG'    => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? 'Yes' : 'No',
            'WP Memory Limit' => WP_MEMORY_LIMIT,
        ];
    }

    /**
     * Gets cache information
     */
    private function getCacheInfo(): array
    {
        // Load WP_Filesystem if not already available
        if (!function_exists('WP_Filesystem')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        WP_Filesystem();
        global $wp_filesystem;
        $cache_writable = $wp_filesystem->is_writable(WPSC_CACHE_DIR) ? 'Yes' : 'No';

        return [
            'Plugin Version'      => WPSC_VERSION,
            'Cache Directory'     => WPSC_CACHE_DIR,
            'Cache Writable'      => $cache_writable,
            'Object Cache Dropin' => file_exists(WP_CONTENT_DIR . '/object-cache.php') ? 'Installed' : 'Not Installed',
            'Redis Extension'     => extension_loaded('redis') ? 'Yes' : 'No',
            'Memcache Extension'  => extension_loaded('memcache') ? 'Yes' : 'No',
            'Memcached Extension' => extension_loaded('memcached') ? 'Yes' : 'No',
            'OPcache Status'      => function_exists('opcache_get_status') && opcache_get_status() !== false ? 'Active' : 'Inactive',
            'Object Caching'      => wp_using_ext_object_cache() ? 'Yes' : 'No',
        ];
    }

    /**
     * Gets server information
     */
    private function getServerInfo(): array
    {
        return [
            'Server Software' => isset($_SERVER['SERVER_SOFTWARE'])
                ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))
                : 'Unknown',
            'Server Protocol' => isset($_SERVER['SERVER_PROTOCOL'])
                ? sanitize_text_field(wp_unslash($_SERVER['SERVER_PROTOCOL']))
                : 'Unknown',
            'SSL/TLS'         => is_ssl() ? 'Yes' : 'No',
            'HTTPS'           => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'Yes' : 'No',
            'Server IP'       => isset($_SERVER['SERVER_ADDR'])
                ? sanitize_text_field(wp_unslash($_SERVER['SERVER_ADDR']))
                : 'Unknown',
            'Server Port'     => isset($_SERVER['SERVER_PORT'])
                ? sanitize_text_field(wp_unslash($_SERVER['SERVER_PORT']))
                : 'Unknown',
            'Document Root'   => isset($_SERVER['DOCUMENT_ROOT'])
                ? sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT']))
                : 'Unknown',
            'Save Path'       => session_save_path(),
        ];
    }

    /**
     * Gets PHP information
     */
    private function getPHPInfo(): array
    {
        return [
            'Version'          => PHP_VERSION,
            'SAPI'             => php_sapi_name(),
            'Extensions'       => implode(', ', get_loaded_extensions()),
            'Zend Version'     => zend_version(),
            'OPcache Enabled'  => function_exists('opcache_get_status') ? 'Yes' : 'No',
            'Max Input Time'   => ini_get('max_input_time'),
            'Display Errors'   => ini_get('display_errors'),
            'Error Reporting'  => $this->getErrorReportingLevel(),
            'Output Buffering' => ini_get('output_buffering'),
            'PHP User'         => get_current_user(),
        ];
    }

    /**
     * Gets database information
     */
    private function getDatabaseInfo(): array
    {
        global $wpdb;

        // Cache the list of tables for one hour.
        $tables = wp_cache_get('wpsc_database_tables', 'diagnostic');
        if ($tables === false) {
            $tables = $wpdb->get_col("SHOW TABLES");
            wp_cache_set('wpsc_database_tables', $tables, 'diagnostic', 3600);
        }

        // Cache the database version for one hour.
        $db_version = wp_cache_get('wpsc_db_version', 'diagnostic');
        if ($db_version === false) {
            $db_version = $wpdb->get_var("SELECT VERSION()");
            wp_cache_set('wpsc_db_version', $db_version, 'diagnostic', 3600);
        }

        return [
            'Version'      => $db_version,
            'Database'     => $wpdb->dbname,
            'Charset'      => $wpdb->charset,
            'Collate'      => $wpdb->collate,
            'Table Prefix' => $wpdb->prefix,
            'DB Host'      => $wpdb->dbhost,
            'Tables'       => implode(', ', $tables),
        ];
    }

    /**
     * Gets active plugins information
     */
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

    /**
     * Gets error reporting level as string
     */
    private function getErrorReportingLevel(): string
    {
        // To avoid full path disclosure, do not reveal error reporting settings
        return 'Hidden';
    }

    /**
     * Renders cache test interface
     */
    private function renderCacheTests(): void
    {
    ?>
        <div class="wpsc-cache-tests">
            <button type="button" class="button" id="wpsc-test-redis">
                <?php esc_html_e('Test Redis Connection', 'wps-cache'); ?>
            </button>
            <button type="button" class="button" id="wpsc-test-varnish">
                <?php esc_html_e('Test Varnish Connection', 'wps-cache'); ?>
            </button>
            <button type="button" class="button" id="wpsc-test-permissions">
                <?php esc_html_e('Test File Permissions', 'wps-cache'); ?>
            </button>

            <div id="wpsc-test-results" class="wpsc-test-results" style="display: none;">
                <h5><?php esc_html_e('Test Results', 'wps-cache'); ?></h5>
                <pre class="wpsc-test-output"></pre>
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
    ?>
        <div class="wpsc-error-log">
            <?php if (file_exists($log_file)): ?>
                <textarea readonly class="large-text code" rows="10">
                    <?php echo esc_textarea(file_get_contents($log_file)); ?>
                </textarea>
                <p>
                    <button type="button" class="button" id="wpsc-clear-log">
                        <?php esc_html_e('Clear Log', 'wps-cache'); ?>
                    </button>
                    <button type="button" class="button" id="wpsc-download-log">
                        <?php esc_html_e('Download Log', 'wps-cache'); ?>
                    </button>
                </p>
            <?php else: ?>
                <p class="description">
                    <?php esc_html_e('No error log file found.', 'wps-cache'); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Renders debug control interface
     */
    private function renderDebugControls(): void
    {
    ?>
        <div class="wpsc-debug-controls">
            <label>
                <input type="checkbox" id="wpsc-enable-debug"
                    <?php checked(get_option('wpsc_debug_mode')); ?>>
                <?php esc_html_e('Enable Debug Mode', 'wps-cache'); ?>
            </label>
            <p class="description">
                <?php esc_html_e('Enables detailed logging for troubleshooting.', 'wps-cache'); ?>
            </p>

            <button type="button" class="button" id="wpsc-generate-report">
                <?php esc_html_e('Generate Debug Report', 'wps-cache'); ?>
            </button>
            <p class="description">
                <?php esc_html_e('Creates a comprehensive debug report for support.', 'wps-cache'); ?>
            </p>
        </div>
<?php
    }
}
