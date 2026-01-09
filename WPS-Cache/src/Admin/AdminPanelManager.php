<?php

declare(strict_types=1);

namespace WPSCache\Admin;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\Settings\SettingsManager;
use WPSCache\Admin\UI\TabManager;
use WPSCache\Admin\UI\NoticeManager;

final class AdminPanelManager
{
    private const OBJECT_CACHE_TEMPLATE = "object-cache.php";

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
        add_action("admin_menu", [$this, "registerAdminMenu"]);
        add_action("admin_bar_menu", [$this, "registerAdminBarNode"], 99);
        add_action("admin_enqueue_scripts", [$this, "enqueueAssets"]);
        add_action("admin_post_wpsc_clear_cache", [$this, "handleManualClear"]);

        // Moved from ToolsManager
        add_action("wp_ajax_wpsc_get_preload_urls", [$this, "handleGetUrls"]);
        add_action("wp_ajax_wpsc_process_preload_url", [
            $this,
            "handleProcessUrl",
        ]);
        add_action("admin_post_wpsc_install_object_cache", [
            $this,
            "handleInstallObjectCache",
        ]);
        add_action("admin_post_wpsc_remove_object_cache", [
            $this,
            "handleRemoveObjectCache",
        ]);
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
                "class" => "wpsc-purge-trigger",
                "onclick" =>
                    "return confirm('" .
                    esc_js(__("Are you sure?", "wps-cache")) .
                    "');",
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
                "saving" => __("Saving...", "wps-cache"),
                "purge_confirm" => __("Are you sure?", "wps-cache"),
                "purging" => __("Purging...", "wps-cache"),
                "copied" => __("Copied!", "wps-cache"),
                "preload_start" => __("Gathering URLs...", "wps-cache"),
                "preload_loading" => __("Preloading...", "wps-cache"),
                "preload_done" => __("Done!", "wps-cache"),
                "preload_complete" => __("Preloading Complete!", "wps-cache"),
                "show_password" => __("Show password", "wps-cache"),
                "hide_password" => __("Hide password", "wps-cache"),
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

    public function handleInstallObjectCache(): void
    {
        check_admin_referer("wpsc_install_object_cache");
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }

        $source = WPSC_PLUGIN_DIR . "includes/" . self::OBJECT_CACHE_TEMPLATE;
        $destination = WP_CONTENT_DIR . "/object-cache.php";

        if (file_exists($destination)) {
            $this->redirectWithNotice("Drop-in already exists.", "error");
        }
        if (@copy($source, $destination)) {
            @chmod($destination, 0644);
            $this->redirectWithNotice(
                "Object Cache Drop-in installed.",
                "success",
            );
        } else {
            $this->redirectWithNotice("Failed to copy file.", "error");
        }
    }

    public function handleRemoveObjectCache(): void
    {
        check_admin_referer("wpsc_remove_object_cache");
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }

        $file = WP_CONTENT_DIR . "/object-cache.php";
        if (file_exists($file)) {
            @unlink($file);
            wp_cache_flush();
            $this->redirectWithNotice("Drop-in removed.", "success");
        } else {
            $this->redirectWithNotice("File not found.", "warning");
        }
    }

    private function redirectWithNotice(string $message, string $type): void
    {
        set_transient(
            "wpsc_admin_notices",
            [["message" => $message, "type" => $type]],
            60,
        );
        wp_safe_redirect(
            remove_query_arg(["action", "_wpnonce"], wp_get_referer()),
        );
        exit();
    }

    public function handleGetUrls(): void
    {
        check_ajax_referer("wpsc_ajax_nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error();
        }

        $post_types = ["page", "post"];
        if (class_exists("WooCommerce")) {
            $post_types[] = "product";
        }

        $query = new \WP_Query([
            "post_type" => $post_types,
            "post_status" => "publish",
            "posts_per_page" => 200,
            "fields" => "ids",
        ]);

        $urls = [home_url("/")];
        foreach ($query->posts as $id) {
            $urls[] = get_permalink($id);
        }
        wp_send_json_success(array_values(array_unique($urls)));
    }

    public function handleProcessUrl(): void
    {
        check_ajax_referer("wpsc_ajax_nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error();
        }

        $url = isset($_POST["url"]) ? esc_url_raw($_POST["url"]) : "";
        if (empty($url) || !str_starts_with($url, home_url("/"))) {
            wp_send_json_error("Invalid URL");
        }

        $desktopUA = "WPS-Cache-Preloader/1.0; " . home_url();
        $mobileUA =
            "Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1";

        $resD = wp_safe_remote_get($url, [
            "timeout" => 10,
            "blocking" => true,
            "sslverify" => apply_filters("https_local_ssl_verify", true),
            "headers" => ["User-Agent" => $desktopUA],
        ]);
        $resM = wp_safe_remote_get($url, [
            "timeout" => 10,
            "blocking" => true,
            "sslverify" => apply_filters("https_local_ssl_verify", true),
            "headers" => ["User-Agent" => $mobileUA],
        ]);

        $cD = is_wp_error($resD) ? 0 : wp_remote_retrieve_response_code($resD);
        $cM = is_wp_error($resM) ? 0 : wp_remote_retrieve_response_code($resM);

        if (($cD >= 200 && $cD < 300) || ($cM >= 200 && $cM < 300)) {
            wp_send_json_success("Cached (D:$cD, M:$cM)");
        } else {
            wp_send_json_error("Error D:$cD M:$cM");
        }
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
            "css_js" => "File Optimization",
            "media" => "Media Optimization",
            "cdn" => "CDN & Cloudflare",
            "database" => "Database",
            "tweaks" => "Tweaks & Cleanup",
        ];
        $pageTitle = $titles[$current_tab] ?? "Settings";
        ?>
        <div class="wpsc-wrap">
            <div class="wpsc-app-container">
                <aside class="wpsc-sidebar">
                    <div class="wpsc-brand">
                        <span class="dashicons dashicons-performance"></span>
                        <h1>WPS Cache</h1>
                    </div>
                    <?php $this->tabManager->renderSidebar($current_tab); ?>
                    <div class="wpsc-sidebar-footer">
                        <small style="color:var(--wpsc-text-muted);">Version <?php echo esc_html(
                            WPSC_VERSION,
                        ); ?></small>
                    </div>
                </aside>
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
                               <span class="dashicons dashicons-trash"></span> Purge All
                            </a>
                        </div>
                    </header>
                    <?php settings_errors("wpsc_settings"); ?>
                    <?php $this->noticeManager->renderNotices(); ?>
                    <div class="wpsc-scroll-canvas">
                        <?php switch ($current_tab) {
                            case "cache":
                                $this->settingsManager->renderCacheTab();
                                break;
                            case "css_js":
                                $this->settingsManager->renderOptimizationTab();
                                break;
                            case "media":
                                $this->settingsManager->renderMediaTab();
                                break;
                            case "cdn":
                                $this->settingsManager->renderCdnTab();
                                break;
                            case "database":
                                $this->settingsManager->renderDatabaseTab();
                                break;
                            case "tweaks":
                                $this->settingsManager->renderTweaksTab();
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
