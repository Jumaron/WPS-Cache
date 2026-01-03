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
        add_action("admin_bar_menu", [$this, "registerAdminBarNode"], 99);
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

    public function registerAdminBarNode(\WP_Admin_Bar $wp_admin_bar): void
    {
        if (!current_user_can("manage_options")) {
            return;
        }
        $wp_admin_bar->add_node([
            "id" => "wpsc-toolbar",
            "title" => "WPS Cache",
            "href" => admin_url("admin.php?page=wps-cache"),
        ]);
        $purge_url = wp_nonce_url(
            admin_url("admin-post.php?action=wpsc_clear_cache"),
            "wpsc_clear_cache",
        );
        $wp_admin_bar->add_node([
            "parent" => "wpsc-toolbar",
            "id" => "wpsc-purge",
            "title" => "Purge All Caches",
            "href" => $purge_url,
            "meta" => [
                "onclick" => "return confirm('" . esc_js(__("Are you sure you want to purge all caches?", "wps-cache")) . "');",
            ],
        ]);
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
                "preload_start" => "Gathering URLs...",
                "preload_loading" => "Preloading...",
                "preload_done" => "Done!",
                "preload_complete" => "Preloading Complete!",
                "copied" => __("Copied!", "wps-cache"),
                "copied_announcement" => __("Copied to clipboard!", "wps-cache"),
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
        $this->noticeManager->add(
            "All caches have been purged successfully.",
            "success",
        );
        wp_redirect(remove_query_arg("wpsc_cleared", wp_get_referer()));
        exit();
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can("manage_options")) {
            return;
        }
        remove_all_actions("admin_notices");
        $current_tab = $this->tabManager->getCurrentTab();
        ?>
        <div class="wpsc-wrap">
            <div class="wpsc-header">
                <div class="wpsc-logo">
                    <h1><span class="dashicons dashicons-performance" aria-hidden="true"
                            style="font-size: 28px; width: 28px; height: 28px;"></span> WPS Cache <span
                            class="wpsc-version">v<?php echo esc_html(
                                WPSC_VERSION,
                            ); ?></span>
                    </h1>
                </div>
                <div class="wpsc-actions"><a href="#" class="wpsc-btn-secondary">Documentation</a></div>
            </div>
            <div class="wpsc-layout">
                <div class="wpsc-sidebar">
                    <?php $this->tabManager->renderSidebar($current_tab); ?>
                </div>
                <div class="wpsc-content">
                    <div class="wpsc-notices-area">
                        <?php settings_errors("wpsc_settings"); ?>
                        <?php $this->noticeManager->renderNotices(); ?>
                    </div>
                    <div class="wpsc-tab-content">
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
                </div>
            </div>
        </div>
        <?php
    }
}
?>
