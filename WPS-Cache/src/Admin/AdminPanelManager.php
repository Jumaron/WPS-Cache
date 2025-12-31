<?php

declare(strict_types=1);

namespace WPSCache\Admin;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\Settings\SettingsManager;
use WPSCache\Admin\UI\TabManager;
use WPSCache\Admin\UI\NoticeManager;

final class AdminPanelManager
{
    private CacheManager $cacheManager;
    private SettingsManager $settingsManager;
    private TabManager $tabManager;
    private NoticeManager $noticeManager;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
        $this->settingsManager = new SettingsManager($cacheManager);
        $this->tabManager = new TabManager();
        $this->noticeManager = new NoticeManager();

        $this->initializeHooks();
    }

    private function initializeHooks(): void
    {
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_bar_menu', [$this, 'registerAdminBarNode'], 99);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_wpsc_clear_cache', [$this, 'handleManualClear']);
    }

    public function registerAdminMenu(): void
    {
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

    public function registerAdminBarNode(\WP_Admin_Bar $wp_admin_bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wp_admin_bar->add_node([
            'id'    => 'wpsc-toolbar',
            'title' => 'WPS Cache',
            'href'  => admin_url('admin.php?page=wps-cache'),
        ]);

        $purge_url = wp_nonce_url(admin_url('admin-post.php?action=wpsc_clear_cache'), 'wpsc_clear_cache');

        $wp_admin_bar->add_node([
            'parent' => 'wpsc-toolbar',
            'id'     => 'wpsc-purge',
            'title'  => 'Purge All Caches',
            'href'   => $purge_url,
        ]);
    }

    public function enqueueAssets(string $hook): void
    {
        // Strictly only load on our page
        if ($hook !== 'toplevel_page_wps-cache') {
            return;
        }

        // Cache busting using version
        wp_enqueue_style(
            'wpsc-admin-css',
            WPSC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPSC_VERSION
        );

        wp_enqueue_script(
            'wpsc-admin-js',
            WPSC_PLUGIN_URL . 'assets/js/admin.js',
            [],
            WPSC_VERSION,
            true
        );

        // Localize vars for JS
        wp_localize_script('wpsc-admin-js', 'wpsc_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wpsc_ajax_nonce'),
            'strings'  => [
                'preload_error' => __('Error starting preload', 'wps-cache'),
                'preload_complete' => __('Preloading Complete!', 'wps-cache')
            ]
        ]);
    }

    public function handleManualClear(): void
    {
        if (!current_user_can('manage_options')) return;
        check_admin_referer('wpsc_clear_cache');

        $this->cacheManager->clearAllCaches();

        // Use our NoticeManager for flash message
        $this->noticeManager->add('All caches have been purged successfully.', 'success');

        wp_redirect(remove_query_arg('wpsc_cleared', wp_get_referer()));
        exit;
    }

    /**
     * Renders the Main Admin Interface matching admin.css structure
     */
    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) return;

        $current_tab = $this->tabManager->getCurrentTab();
?>
        <!-- Main Wrapper -->
        <div class="wpsc-wrap">

            <!-- Header -->
            <div class="wpsc-header">
                <div class="wpsc-logo">
                    <h1>
                        <span class="dashicons dashicons-performance" style="font-size: 28px; width: 28px; height: 28px;"></span>
                        WPS Cache
                        <span class="wpsc-version">v<?php echo esc_html(WPSC_VERSION); ?></span>
                    </h1>
                </div>
                <div class="wpsc-actions">
                    <a href="https://github.com/Jumaron/WPS-Cache" target="_blank" class="wpsc-btn-secondary">Documentation</a>
                </div>
            </div>

            <!-- Layout: Sidebar + Content -->
            <div class="wpsc-layout">

                <!-- Sidebar -->
                <div class="wpsc-sidebar">
                    <?php $this->tabManager->renderSidebar($current_tab); ?>
                </div>

                <!-- Main Content -->
                <div class="wpsc-content">
                    <!-- Flash Notices -->
                    <?php settings_errors('wpsc_settings'); ?>
                    <?php $this->noticeManager->renderNotices(); ?>

                    <!-- Tab Content -->
                    <div class="wpsc-tab-content">
                        <?php
                        switch ($current_tab) {
                            case 'cache':
                                $this->settingsManager->renderCacheTab();
                                break;
                            case 'css_js':
                                $this->settingsManager->renderOptimizationTab();
                                break;
                            case 'advanced':
                                $this->settingsManager->renderAdvancedTab();
                                break;
                            case 'tools':
                                // If you have a separate ToolsManager, call it here
                                // For now we assume settingsManager handles simple tools or we instantiate ToolsManager
                                (new \WPSCache\Admin\Tools\ToolsManager())->render();
                                break;
                            case 'analytics':
                                (new \WPSCache\Admin\Analytics\AnalyticsManager($this->cacheManager))->render();
                                break;
                            default:
                                $this->settingsManager->renderDashboardTab();
                                break;
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
<?php
    }
}
