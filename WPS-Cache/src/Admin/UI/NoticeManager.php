<?php

declare(strict_types=1);

namespace WPSCache\Admin\UI;

/**
 * Manages admin notices and messages
 */
class NoticeManager
{
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

    public function __construct()
    {
        // INTENTIONALLY LEFT EMPTY
        // We removed add_action('admin_notices') to prevent double rendering.
        // The displayNotices() method is now called manually in AdminPanelManager.
    }

    /**
     * Displays all queued admin notices
     */
    public function displayNotices(): void
    {
        $this->displayQueuedNotices();
        $this->displayStatusNotices();
        $this->displaySystemNotices();
    }

    /**
     * Adds a notice to the queue
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
    private function displayQueuedNotices(): void
    {
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
    private function displayStatusNotices(): void
    {
        $this->displayCacheNotices();
        $this->displayObjectCacheNotices();
        $this->displaySettingsNotices();
        $this->displayImportExportNotices();
    }

    /**
     * Displays system-related notices
     */
    private function displaySystemNotices(): void
    {
        $this->displayCompatibilityNotices();
        $this->displayConfigurationWarnings();
        $this->displayPerformanceNotices();
    }

    /**
     * Displays cache-related notices
     */
    private function displayCacheNotices(): void
    {
        if (isset($_GET['cache_cleared'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_cache_cleared')) {
                return;
            }

            if ($_GET['cache_cleared'] === 'success') {
                $this->renderNotice(
                    __('All caches have been successfully cleared!', 'wps-cache'),
                    'success'
                );
            } else {
                $this->renderNotice(
                    __('There was an error clearing some caches. Please check the error log.', 'wps-cache'),
                    'error'
                );
            }
        }
    }

    /**
     * Displays object cache-related notices
     */
    private function displayObjectCacheNotices(): void
    {
        if (isset($_GET['object_cache_installed'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_dropin_installed')) {
                return;
            }

            switch ($_GET['object_cache_installed']) {
                case 'success':
                    $this->renderNotice(
                        __('Object cache drop-in installed successfully!', 'wps-cache'),
                        'success'
                    );
                    break;
                case 'error_exists':
                    $this->renderNotice(
                        __('Object cache drop-in already exists.', 'wps-cache'),
                        'error'
                    );
                    break;
                case 'error_copy':
                    $this->renderNotice(
                        __('Error installing object cache drop-in. Please check file permissions.', 'wps-cache'),
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
                        __('Object cache drop-in removed successfully!', 'wps-cache'),
                        'success'
                    );
                    break;
                case 'error_remove':
                    $this->renderNotice(
                        __('Error removing object cache drop-in. Please check file permissions.', 'wps-cache'),
                        'error'
                    );
                    break;
            }
        }
    }

    /**
     * Displays settings-related notices
     */
    private function displaySettingsNotices(): void
    {
        if (isset($_GET['settings_updated'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_settings_update')) {
                return;
            }
            $this->renderNotice(
                __('Settings updated successfully!', 'wps-cache'),
                'success'
            );
        }

        if (isset($_GET['settings_error'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_settings_update')) {
                return;
            }
            $this->renderNotice(
                __('Error updating settings. Please try again.', 'wps-cache'),
                'error'
            );
        }
    }

    /**
     * Displays import/export-related notices
     */
    private function displayImportExportNotices(): void
    {
        if (isset($_GET['import_error'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_import_export')) {
                return;
            }
            $message = match ($_GET['import_error']) {
                'upload'  => __('Error uploading settings file.', 'wps-cache'),
                'invalid' => __('Invalid settings file format.', 'wps-cache'),
                default   => __('Unknown error importing settings.', 'wps-cache')
            };
            $this->renderNotice($message, 'error');
        }

        if (isset($_GET['settings_imported'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wpsc_import_export')) {
                return;
            }
            $this->renderNotice(
                __('Settings imported successfully!', 'wps-cache'),
                'success'
            );
        }
    }

    private function displayCompatibilityNotices(): void
    {
        // PHP version check
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $message = __('WPS Cache requires PHP 7.4 or higher. Your current PHP version is %s.', 'wps-cache');
            $this->renderNotice(sprintf($message, PHP_VERSION), 'error');
        }

        // Check for conflicting plugins
        $conflicting_plugins = $this->getConflictingPlugins();
        if (!empty($conflicting_plugins)) {
            $message = __('The following plugins may conflict with WPS Cache: %s', 'wps-cache');
            $this->renderNotice(sprintf($message, implode(', ', $conflicting_plugins)), 'warning');
        }
    }

    private function displayConfigurationWarnings(): void
    {
        $settings = get_option('wpsc_settings', []);

        if (($settings['redis_cache'] ?? false) && !extension_loaded('redis')) {
            $this->renderNotice(__('Redis cache is enabled but the Redis PHP extension is not installed.', 'wps-cache'), 'error');
        }

        if (($settings['redis_cache'] ?? false) && !file_exists(WP_CONTENT_DIR . '/object-cache.php')) {
            $this->renderNotice(__('Redis cache is enabled but the object cache drop-in is not installed.', 'wps-cache'), 'warning');
        }
    }

    private function displayPerformanceNotices(): void
    {
        if (!function_exists('opcache_get_status')) {
            $this->renderNotice(__('OPcache is not enabled. Enabling it can significantly improve performance.', 'wps-cache'), 'info');
        }
    }

    private function getConflictingPlugins(): array
    {
        $conflicting_plugins = [];
        $known_conflicts = [
            'wp-super-cache/wp-cache.php'     => 'WP Super Cache',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
            'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache'
        ];

        foreach ($known_conflicts as $plugin_path => $plugin_name) {
            if (is_plugin_active($plugin_path)) {
                $conflicting_plugins[] = $plugin_name;
            }
        }

        return $conflicting_plugins;
    }

    /**
     * Renders a notice using the new CSS classes
     */
    private function renderNotice(
        string $message,
        string $type = 'info',
        bool $dismissible = true,
        array $args = []
    ): void {
        // Map types to dashicons
        $icon_map = [
            'success' => 'dashicons-yes-alt',
            'error'   => 'dashicons-warning',
            'warning' => 'dashicons-flag',
            'info'    => 'dashicons-info',
        ];

        $css_class = self::NOTICE_TYPES[$type] ?? 'notice-info';
        $icon = $icon_map[$type] ?? 'dashicons-info';
?>
        <div class="wpsc-notice <?php echo esc_attr($css_class); ?>">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                <span><?php echo wp_kses_post($message); ?></span>
            </div>
            <?php if ($dismissible): ?>
                <button type="button" class="notice-dismiss" style="position: static; text-decoration: none; margin: 0;">
                    <span class="screen-reader-text">Dismiss</span>
                </button>
            <?php endif; ?>
        </div>
<?php
    }

    public function clearNotices(): void
    {
        delete_transient(self::NOTICES_TRANSIENT);
    }
}
