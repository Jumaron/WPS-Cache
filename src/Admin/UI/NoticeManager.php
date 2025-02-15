<?php
declare(strict_types=1);

namespace WPSCache\Admin\UI;

/**
 * Manages admin notices and messages
 */
class NoticeManager {
    /**
     * Transient key for storing notices
     */
    private const NOTICES_TRANSIENT = 'wpsc_admin_notices';

    /**
     * Notice types and their CSS classes
     */
    private const NOTICE_TYPES = [
        'info'    => 'notice-info',
        'success' => 'notice-success',
        'warning' => 'notice-warning',
        'error'   => 'notice-error'
    ];

    public function __construct() {
        add_action('admin_notices', [$this, 'displayNotices']);
    }

    /**
     * Displays all queued admin notices
     */
    public function displayNotices(): void {
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page !== 'WPS-Cache') {
            return;
        }

        $this->displayQueuedNotices();
        $this->displayStatusNotices();
        $this->displaySystemNotices();
    }

    /**
     * Adds a notice to the queue
     *
     * @param string $message Notice message
     * @param string $type Notice type (info|success|warning|error)
     * @param bool $dismissible Whether notice is dismissible
     * @param array $args Additional arguments
     */
    public function addNotice(
        string $message,
        string $type = 'info',
        bool $dismissible = true,
        array $args = []
    ): void {
        if (!isset(self::NOTICE_TYPES[$type])) {
            $type = 'info';
        }

        $notices = get_transient(self::NOTICES_TRANSIENT) ?: [];
        $notices[] = [
            'message'     => $message,
            'type'        => $type,
            'dismissible' => $dismissible,
            'args'        => $args
        ];

        set_transient(self::NOTICES_TRANSIENT, $notices, HOUR_IN_SECONDS);
    }

    /**
     * Displays queued notices and clears the queue
     */
    private function displayQueuedNotices(): void {
        $notices = get_transient(self::NOTICES_TRANSIENT);
        if (!is_array($notices)) {
            return;
        }

        foreach ($notices as $notice) {
            $this->renderNotice(
                $notice['message'],
                $notice['type'],
                $notice['dismissible'],
                $notice['args']
            );
        }

        delete_transient(self::NOTICES_TRANSIENT);
    }

    /**
     * Displays status-based notices
     */
    private function displayStatusNotices(): void {
        $this->displayCacheNotices();
        $this->displayObjectCacheNotices();
        $this->displaySettingsNotices();
        $this->displayImportExportNotices();
    }

    /**
     * Displays system-related notices
     */
    private function displaySystemNotices(): void {
        $this->displayCompatibilityNotices();
        $this->displayConfigurationWarnings();
        $this->displayPerformanceNotices();
    }

    /**
     * Displays cache-related notices
     */
    private function displayCacheNotices(): void {
        if (isset($_GET['cache_cleared'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_cache_cleared')) {
                return;
            }

            if ($_GET['cache_cleared'] === 'success') {
                $this->renderNotice(
                    __('All caches have been successfully cleared!', 'WPS-Cache'),
                    'success'
                );
            } else {
                $this->renderNotice(
                    __('There was an error clearing some caches. Please check the error log.', 'WPS-Cache'),
                    'error'
                );
            }
        }
    }

    /**
     * Displays object cache-related notices
     */
    private function displayObjectCacheNotices(): void {
        if (isset($_GET['object_cache_installed'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_dropin_installed')) {
                return;
            }

            switch ($_GET['object_cache_installed']) {
                case 'success':
                    $this->renderNotice(
                        __('Object cache drop-in installed successfully!', 'WPS-Cache'),
                        'success'
                    );
                    break;
                case 'error_exists':
                    $this->renderNotice(
                        __('Object cache drop-in already exists.', 'WPS-Cache'),
                        'error'
                    );
                    break;
                case 'error_copy':
                    $this->renderNotice(
                        __('Error installing object cache drop-in. Please check file permissions.', 'WPS-Cache'),
                        'error'
                    );
                    break;
            }
        }

        if (isset($_GET['object_cache_removed'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_dropin_removed')) {
                return;
            }

            switch ($_GET['object_cache_removed']) {
                case 'success':
                    $this->renderNotice(
                        __('Object cache drop-in removed successfully!', 'WPS-Cache'),
                        'success'
                    );
                    break;
                case 'error_remove':
                    $this->renderNotice(
                        __('Error removing object cache drop-in. Please check file permissions.', 'WPS-Cache'),
                        'error'
                    );
                    break;
            }
        }
    }

    /**
     * Displays settings-related notices
     */
    private function displaySettingsNotices(): void {
        if (isset($_GET['settings_updated'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_settings_update')) {
                return;
            }
            $this->renderNotice(
                __('Settings updated successfully!', 'WPS-Cache'),
                'success'
            );
        }

        if (isset($_GET['settings_error'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_settings_update')) {
                return;
            }
            $this->renderNotice(
                __('Error updating settings. Please try again.', 'WPS-Cache'),
                'error'
            );
        }
    }

    /**
     * Displays import/export-related notices
     */
    private function displayImportExportNotices(): void {
        if (isset($_GET['import_error'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_import_export')) {
                return;
            }
            $message = match ($_GET['import_error']) {
                'upload'  => __('Error uploading settings file.', 'WPS-Cache'),
                'invalid' => __('Invalid settings file format.', 'WPS-Cache'),
                default   => __('Unknown error importing settings.', 'WPS-Cache')
            };
            $this->renderNotice($message, 'error');
        }

        if (isset($_GET['settings_imported'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_import_export')) {
                return;
            }
            $this->renderNotice(
                __('Settings imported successfully!', 'WPS-Cache'),
                'success'
            );
        }
    }

    private function displayCompatibilityNotices(): void {
        // PHP version check
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            /* translators: %s: current PHP version */
            $message = __('WPS Cache requires PHP 7.4 or higher. Your current PHP version is %s.', 'WPS-Cache');
            $this->renderNotice(
                sprintf(
                    $message,
                    PHP_VERSION
                ),
                'error'
            );
        }

        // Check for conflicting plugins
        $conflicting_plugins = $this->getConflictingPlugins();
        if (!empty($conflicting_plugins)) {
            /* translators: %s: list of conflicting plugin names */
            $message = __('The following plugins may conflict with WPS Cache: %s', 'WPS-Cache');
            $this->renderNotice(
                sprintf(
                    $message,
                    implode(', ', $conflicting_plugins)
                ),
                'warning'
            );
        }

        // WordPress version check
        global $wp_version;
        if (version_compare($wp_version, '5.6', '<')) {
            /* translators: %s: current WordPress version */
            $message = __('WPS Cache recommends WordPress 5.6 or higher. Your current version is %s.', 'WPS-Cache');
            $this->renderNotice(
                sprintf(
                    $message,
                    $wp_version
                ),
                'warning'
            );
        }
    }

    /**
     * Displays configuration warnings
     */
    private function displayConfigurationWarnings(): void {
        $settings = get_option('wpsc_settings', []);

        // Redis extension check
        if (($settings['redis_cache'] ?? false) && !extension_loaded('redis')) {
            $this->renderNotice(
                __('Redis cache is enabled but the Redis PHP extension is not installed.', 'WPS-Cache'),
                'error'
            );
        }

        // Object cache dropin check
        if (($settings['redis_cache'] ?? false) && !file_exists(WP_CONTENT_DIR . '/object-cache.php')) {
            $this->renderNotice(
                __('Redis cache is enabled but the object cache drop-in is not installed.', 'WPS-Cache'),
                'warning'
            );
        }

        // Varnish configuration check
        if (($settings['varnish_cache'] ?? false) && !function_exists('curl_init')) {
            $this->renderNotice(
                __('Varnish cache is enabled but the PHP cURL extension is not installed.', 'WPS-Cache'),
                'warning'
            );
        }

        // Cache directory permissions using WP_Filesystem
        $cache_dir = WPSC_CACHE_DIR;
        if (!function_exists('WP_Filesystem')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        WP_Filesystem();
        global $wp_filesystem;
        if (!$wp_filesystem->is_writable($cache_dir)) {
            /* translators: %s: cache directory path */
            $message = __('The cache directory %s is not writable. Please check the permissions.', 'WPS-Cache');
            $this->renderNotice(
                sprintf(
                    $message,
                    '<code>' . esc_html($cache_dir) . '</code>'
                ),
                'error'
            );
        }
    }

    /**
     * Displays performance-related notices
     */
    private function displayPerformanceNotices(): void {
        // OPcache check
        if (!function_exists('opcache_get_status')) {
            $this->renderNotice(
                __('OPcache is not enabled. Enabling it can significantly improve performance.', 'WPS-Cache'),
                'info',
                true,
                ['documentation_url' => 'https://www.php.net/manual/en/book.opcache.php']
            );
        }

        // Memory limit check
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($memory_limit < 64 * MB_IN_BYTES) {
            $this->renderNotice(
                __('PHP memory limit is low. Consider increasing it to at least 64MB for better performance.', 'WPS-Cache'),
                'warning'
            );
        }
    }

    /**
     * Gets list of potentially conflicting plugins
     */
    private function getConflictingPlugins(): array {
        $conflicting_plugins = [];
        $known_conflicts = [
            'wp-super-cache/wp-cache.php'            => 'WP Super Cache',
            'w3-total-cache/w3-total-cache.php'        => 'W3 Total Cache',
            'wp-fastest-cache/wpFastestCache.php'        => 'WP Fastest Cache',
            'litespeed-cache/litespeed-cache.php'        => 'LiteSpeed Cache'
        ];

        foreach ($known_conflicts as $plugin_path => $plugin_name) {
            if (is_plugin_active($plugin_path)) {
                $conflicting_plugins[] = $plugin_name;
            }
        }

        return $conflicting_plugins;
    }

    /**
     * Renders a notice
     */
    private function renderNotice(
        string $message,
        string $type = 'info',
        bool $dismissible = true,
        array $args = []
    ): void {
        $classes = ['notice', self::NOTICE_TYPES[$type] ?? 'notice-info'];
        
        if ($dismissible) {
            $classes[] = 'is-dismissible';
        }
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>">
            <p><?php echo wp_kses_post($message); ?></p>
            
            <?php if (!empty($args['documentation_url'])): ?>
                <p>
                    <a href="<?php echo esc_url($args['documentation_url']); ?>" 
                       target="_blank" 
                       rel="noopener noreferrer">
                        <?php esc_html_e('Learn more', 'WPS-Cache'); ?> ›
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Clears all notices
     */
    public function clearNotices(): void {
        delete_transient(self::NOTICES_TRANSIENT);
    }

    /**
     * Gets count of current notices
     */
    public function getNoticeCount(): int {
        $notices = get_transient(self::NOTICES_TRANSIENT);
        return is_array($notices) ? count($notices) : 0;
    }

    /**
     * Checks if there are any notices
     */
    public function hasNotices(): bool {
        return $this->getNoticeCount() > 0;
    }

    /**
     * Gets all current notices without displaying them
     */
    public function getNotices(): array {
        return get_transient(self::NOTICES_TRANSIENT) ?: [];
    }
}
