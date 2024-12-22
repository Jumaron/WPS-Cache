<?php
declare(strict_types=1);

namespace WPSCache\Admin;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\Settings\SettingsManager;
use WPSCache\Admin\Analytics\AnalyticsManager;
use WPSCache\Admin\Tools\ToolsManager;
use WPSCache\Admin\UI\TabManager;
use WPSCache\Admin\UI\NoticeManager;

/**
 * Main coordinator class for the WPS Cache admin interface
 */
final class AdminPanelManager {
    private CacheManager $cache_manager;
    private SettingsManager $settings_manager;
    private AnalyticsManager $analytics_manager;
    private ToolsManager $tools_manager;
    private TabManager $tab_manager;
    private NoticeManager $notice_manager;

    public function __construct(CacheManager $cache_manager) {
        $this->cache_manager = $cache_manager;
        $this->initializeComponents();
        $this->initializeHooks();
    }

    /**
     * Initializes all admin component managers
     */
    private function initializeComponents(): void {
        $this->settings_manager = new SettingsManager($this->cache_manager);
        $this->analytics_manager = new AnalyticsManager($this->cache_manager);
        $this->tools_manager = new ToolsManager($this->cache_manager);
        $this->tab_manager = new TabManager();
        $this->notice_manager = new NoticeManager();
    }

    /**
     * Sets up WordPress admin hooks
     */
    private function initializeHooks(): void {
        // Admin menu and assets
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // Ajax handlers
        add_action('wp_ajax_wpsc_get_cache_stats', [$this->analytics_manager, 'handleAjaxGetCacheStats']);
        add_action('wp_ajax_wpsc_get_cache_metrics', [$this->analytics_manager, 'handleAjaxGetCacheMetrics']);
        add_action('wp_ajax_wpsc_preload_cache', [$this->tools_manager, 'handleAjaxPreloadCache']);

        // Admin post handlers
        add_action('admin_post_wpsc_clear_cache', [$this->tools_manager, 'handleCacheClear']);
        add_action('admin_post_wpsc_install_object_cache', [$this->tools_manager, 'handleInstallObjectCache']);
        add_action('admin_post_wpsc_remove_object_cache', [$this->tools_manager, 'handleRemoveObjectCache']);
        add_action('admin_post_wpsc_export_settings', [$this->tools_manager, 'handleExportSettings']);
        add_action('admin_post_wpsc_import_settings', [$this->tools_manager, 'handleImportSettings']);
    }

    /**
     * Adds the plugin's admin menu item
     */
    public function addAdminMenu(): void {
        add_menu_page(
            'WPS Cache',
            'WPS Cache',
            'manage_options',
            'wps-cache',
            [$this, 'renderAdminPage'],
            'dashicons-performance',
            100
        );
    }

    /**
     * Enqueues admin scripts and styles
     */
    public function enqueueAdminAssets(string $hook): void {
        if ('toplevel_page_wps-cache' !== $hook) {
            return;
        }

        // Styles
        wp_enqueue_style(
            'wpsc-admin-styles',
            WPSC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPSC_VERSION
        );

        // Scripts
        wp_enqueue_script(
            'chartjs',
            'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js',
            [],
            '3.7.0',
            true
        );

        wp_localize_script('wpsc-admin-scripts', 'wpsc_admin', $this->getJsConfig());
    }

    /**
     * Gets the JavaScript configuration array
     */
    private function getJsConfig(): array {
        return [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpsc_ajax_nonce'),
            'strings' => $this->getJsStrings(),
            'refresh_interval' => 30000,
            'chart_colors' => [
                'hits' => '#28a745',
                'misses' => '#dc3545',
                'writes' => '#17a2b8',
                'deletes' => '#ffc107'
            ]
        ];
    }

    /**
     * Gets translated strings for JavaScript
     */
    private function getJsStrings(): array {
        return [
            'confirm_clear_cache' => __('Are you sure you want to clear all caches?', 'wps-cache'),
            'confirm_install_dropin' => __('Are you sure you want to install the object cache drop-in?', 'wps-cache'),
            'confirm_remove_dropin' => __('Are you sure you want to remove the object cache drop-in?', 'wps-cache'),
            'loading' => __('Loading...', 'wps-cache'),
            'error' => __('An error occurred', 'wps-cache'),
            'success' => __('Operation completed successfully', 'wps-cache'),
            'preload_progress' => __('Preloading: %d%%', 'wps-cache'),
            'preload_complete' => __('Preloading completed', 'wps-cache'),
            'preload_error' => __('Error during preloading', 'wps-cache'),
            'export_error' => __('Error exporting settings', 'wps-cache'),
            'import_error' => __('Error importing settings', 'wps-cache'),
            'invalid_file' => __('Invalid settings file', 'wps-cache')
        ];
    }

    /**
     * Renders the main admin page
     */
    public function renderAdminPage(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $current_tab = $_GET['tab'] ?? 'settings';
        ?>
        <div class="wrap">
            <h1><?php _e('WPS Cache', 'wps-cache'); ?></h1>

            <div class="wpsc-admin-container">
                <nav class="wpsc-tabs">
                    <?php $this->tab_manager->renderTabs($current_tab); ?>
                </nav>

                <div class="wpsc-tab-content">
                    <?php $this->renderTabContent($current_tab); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the content for the current tab
     */
    private function renderTabContent(string $current_tab): void {
        switch ($current_tab) {
            case 'analytics':
                $this->analytics_manager->renderTab();
                break;
            case 'tools':
                $this->tools_manager->renderTab();
                break;
            default:
                $this->settings_manager->renderTab();
        }
    }
}