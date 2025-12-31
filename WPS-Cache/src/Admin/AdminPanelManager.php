<?php

declare(strict_types=1);

namespace WPSCache\Admin;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\Settings\SettingsManager;
use WPSCache\Admin\Analytics\AnalyticsManager;
use WPSCache\Admin\Tools\ToolsManager;
use WPSCache\Admin\UI\TabManager;
use WPSCache\Admin\UI\NoticeManager;

final class AdminPanelManager
{
    private CacheManager $cache_manager;
    private SettingsManager $settings_manager;
    private AnalyticsManager $analytics_manager;
    private ToolsManager $tools_manager;
    private TabManager $tab_manager;
    private NoticeManager $notice_manager;

    public function __construct(CacheManager $cache_manager)
    {
        $this->cache_manager = $cache_manager;
        $this->initializeComponents();
        $this->initializeHooks();
    }

    private function initializeComponents(): void
    {
        $this->settings_manager = new SettingsManager($this->cache_manager);
        $this->analytics_manager = new AnalyticsManager($this->cache_manager);
        $this->tools_manager = new ToolsManager($this->cache_manager);
        $this->tab_manager = new TabManager();
        $this->notice_manager = new NoticeManager();
    }

    private function initializeHooks(): void
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // Ajax & Post Handlers
        add_action('wp_ajax_wpsc_get_cache_stats', [$this->analytics_manager, 'handleAjaxGetCacheStats']);
        add_action('wp_ajax_wpsc_get_cache_metrics', [$this->analytics_manager, 'handleAjaxGetCacheMetrics']);
        add_action('wp_ajax_wpsc_preload_cache', [$this->tools_manager, 'handleAjaxPreloadCache']);

        add_action('admin_post_wpsc_clear_cache', [$this->tools_manager, 'handleCacheClear']);
        add_action('admin_post_wpsc_install_object_cache', [$this->tools_manager, 'handleInstallObjectCache']);
        add_action('admin_post_wpsc_remove_object_cache', [$this->tools_manager, 'handleRemoveObjectCache']);
        add_action('admin_post_wpsc_export_settings', [$this->tools_manager, 'handleExportSettings']);
        add_action('admin_post_wpsc_import_settings', [$this->tools_manager, 'handleImportSettings']);
    }

    public function addAdminMenu(): void
    {
        add_menu_page('WPS Cache', 'WPS Cache', 'manage_options', 'wps-cache', [$this, 'renderAdminPage'], 'dashicons-performance', 100);
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if ('toplevel_page_wps-cache' !== $hook) return;

        // Use time() during dev to bust cache, WPSC_VERSION in prod
        $ver = defined('WP_DEBUG') && WP_DEBUG ? time() : WPSC_VERSION;

        wp_enqueue_style('wpsc-admin-styles', WPSC_PLUGIN_URL . 'assets/css/admin.css', [], $ver);
        wp_enqueue_script('wpsc-admin-scripts', WPSC_PLUGIN_URL . 'assets/js/admin.js', [], $ver, true);
        wp_localize_script('wpsc-admin-scripts', 'wpsc_admin', $this->getJsConfig());
    }

    private function getJsConfig(): array
    {
        return [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpsc_ajax_nonce'),
            'strings' => [
                'preload_progress' => __('Preloading: %d%%', 'wps-cache'),
                'preload_complete' => __('Preloading completed', 'wps-cache'),
                'preload_error' => __('Error during preloading', 'wps-cache'),
            ],
            'refresh_interval' => 30000,
        ];
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) return;

        // CRITICAL FIX: Remove default WP notices to prevent duplication and layout breakage
        remove_all_actions('admin_notices');

        $current_tab = $this->tab_manager->getCurrentTab();
?>
        <div class="wpsc-wrap">
            <div class="wpsc-header">
                <div class="wpsc-logo">
                    <h1><span class="dashicons dashicons-performance" style="font-size: 30px; width: 30px; height: 30px;"></span> WPS Cache <span class="wpsc-version">v<?php echo WPSC_VERSION; ?></span></h1>
                </div>
                <div class="wpsc-header-actions">
                    <a href="#" class="wpsc-btn-secondary">Documentation</a>
                </div>
            </div>

            <!-- Custom Notice Area -->
            <div style="padding-top: 20px;">
                <?php $this->notice_manager->displayNotices(); ?>
            </div>

            <div class="wpsc-layout">
                <?php $this->tab_manager->renderSidebar($current_tab); ?>

                <div class="wpsc-content">
                    <?php $this->renderTabContent($current_tab); ?>
                </div>
            </div>
        </div>
<?php
    }

    private function renderTabContent(string $current_tab): void
    {
        switch ($current_tab) {
            case 'analytics':
                $this->analytics_manager->renderTab();
                break;
            case 'tools':
                $this->tools_manager->renderTab();
                break;
            case 'cache':
                $this->settings_manager->renderCacheTab();
                break;
            case 'css_js':
                $this->settings_manager->renderCssJsTab();
                break;
            default:
                $this->settings_manager->renderDashboardTab();
        }
    }
}
