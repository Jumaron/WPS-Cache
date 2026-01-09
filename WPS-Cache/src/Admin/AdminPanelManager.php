<?php

declare(strict_types=1);

namespace WPSCache\Admin;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\Settings\SettingsManager;
use WPSCache\Admin\UI\TabManager;
use WPSCache\Admin\UI\NoticeManager;
use WPSCache\Admin\Tools\ToolsManager;
use WPSCache\Admin\Analytics\AnalyticsManager;

final class AdminPanelManager
{
    private CacheManager $cacheManager;
    private SettingsManager $settingsManager;
    private TabManager $tabManager;
    private NoticeManager $noticeManager;
    private ToolsManager $toolsManager;
    private AnalyticsManager $analyticsManager;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
        $this->settingsManager = new SettingsManager($cacheManager);
        $this->tabManager = new TabManager();
        $this->noticeManager = new NoticeManager();
        $this->toolsManager = new ToolsManager();
        $this->analyticsManager = new AnalyticsManager($cacheManager);
        $this->initializeHooks();
    }

    private function initializeHooks(): void
    {
        add_action("admin_menu", [$this, "registerAdminMenu"]);
        add_action("admin_enqueue_scripts", [$this, "enqueueAssets"]);
        add_action("admin_post_wpsc_clear_cache", [$this, "handleManualClear"]);
    }

    public function registerAdminMenu(): void
    {
        add_menu_page(
            "WPS Cache",
            "WPS Cache",
            "manage_options",
            "wps-cache",
            [$this, "renderAdminPage"],
            "dashicons-performance",
            100,
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== "toplevel_page_wps-cache") {
            return;
        }
        wp_enqueue_style(
            "wpsc-admin-css",
            WPSC_PLUGIN_URL . "assets/css/admin.css",
            [],
            WPSC_VERSION,
        );
        wp_enqueue_script(
            "wpsc-admin-js",
            WPSC_PLUGIN_URL . "assets/js/admin.js",
            [],
            WPSC_VERSION,
            true,
        );
        wp_localize_script("wpsc-admin-js", "wpsc_admin", [
            "ajax_url" => admin_url("admin-ajax.php"),
            "nonce" => wp_create_nonce("wpsc_ajax_nonce"),
            "strings" => [
                "saving" => __("Saving...", "wps-cache"),
                "purge_confirm" => __("Are you sure?", "wps-cache"),
                "copied" => __("Copied!", "wps-cache"),
            ],
        ]);
    }

    public function handleManualClear(): void
    {
        if (!current_user_can("manage_options")) {
            return;
        }
        check_admin_referer("wpsc_clear_cache");
        $this->cacheManager->clearAllCaches();
        $this->noticeManager->add("Cache cleared.", "success");
        wp_safe_redirect(remove_query_arg("wpsc_cleared", wp_get_referer()));
        exit();
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can("manage_options")) {
            return;
        }
        remove_all_actions("admin_notices");
        $current_tab = $this->tabManager->getCurrentTab();

        $titles = [
            "dashboard" => "Dashboard",
            "cache" => "Cache Rules",
            "media" => "Media Optimization",
            "cdn" => "CDN & Cloudflare",
            "css_js" => "File Optimization",
            "tweaks" => "Tweaks & Cleanup",
            "database" => "Database",
            "analytics" => "Analytics",
            "tools" => "Tools",
            "advanced" => "Advanced",
        ];
        $pageTitle = $titles[$current_tab] ?? "Settings";
        ?>

        <div class="wpsc-wrap">
            <div class="wpsc-app-container">
                <!-- 1. Left Sidebar -->
                <aside class="wpsc-sidebar">
                    <div class="wpsc-brand">
                        <span class="dashicons dashicons-performance"></span>
                        <h1>WPS Cache</h1>
                    </div>

                    <nav class="wpsc-nav">
                        <?php $this->tabManager->renderSidebar($current_tab); ?>
                    </nav>

                    <div class="wpsc-sidebar-footer">
                        <small style="color:var(--wpsc-text-muted);">Version <?php echo esc_html(
                            WPSC_VERSION,
                        ); ?></small>
                    </div>
                </aside>

                <!-- 2. Main Content -->
                <main class="wpsc-content-area">
                    <header class="wpsc-header-bar">
                        <h2 class="wpsc-page-title"><?php echo esc_html(
                            $pageTitle,
                        ); ?></h2>
                        <div class="wpsc-actions">
                            <a href="<?php echo esc_url(
                                wp_nonce_url(
                                    admin_url(
                                        "admin-post.php?action=wpsc_clear_cache",
                                    ),
                                    "wpsc_clear_cache",
                                ),
                            ); ?>"
                               class="wpsc-btn-ghost-danger wpsc-confirm-trigger">
                               <span class="dashicons dashicons-trash" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span> Purge All
                            </a>
                        </div>
                    </header>

                    <!-- Notices Area (Now handled inside NoticeManager so no gap if empty) -->
                    <?php settings_errors("wpsc_settings"); ?>
                    <?php $this->noticeManager->renderNotices(); ?>

                    <!-- Scrollable Settings Canvas -->
                    <div class="wpsc-scroll-canvas">
                        <?php switch ($current_tab) {
                            case "cache":
                                $this->settingsManager->renderCacheTab();
                                break;
                            case "media":
                                $this->settingsManager->renderMediaTab();
                                break;
                            case "cdn":
                                $this->settingsManager->renderCdnTab();
                                break;
                            case "css_js":
                                $this->settingsManager->renderOptimizationTab();
                                break;
                            case "tweaks":
                                $this->settingsManager->renderTweaksTab();
                                break;
                            case "database":
                                $this->settingsManager->renderDatabaseTab();
                                break;
                            case "advanced":
                                $this->settingsManager->renderAdvancedTab();
                                break;
                            case "tools":
                                $this->toolsManager->render();
                                break;
                            case "analytics":
                                $this->analyticsManager->render();
                                break;
                            default:
                                $this->settingsManager->renderDashboardTab();
                                break;
                        } ?>
                    </div>
                </main>
            </div>
        </div>
        <?php
    }
}
