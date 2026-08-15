<?php

declare(strict_types=1);

namespace WPSCache\Admin;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\Settings\SettingsManager;
use WPSCache\Admin\UI\TabManager;
use WPSCache\Admin\UI\NoticeManager;
use WPSCache\Maintenance\DatabaseOptimizer;
use WPSCache\Admin\Settings\FeatureSettingsManager;
use WPSCache\Infrastructure\Server\ServerConfigGenerator;

final class AdminPanelManager
{
    private SettingsManager $settingsManager;
    private TabManager $tabManager;
    private NoticeManager $noticeManager;
    private FeatureSettingsManager $featureSettings;

    public function __construct(
        CacheManager $cacheManager,
        DatabaseOptimizer $databaseOptimizer,
        NoticeManager $noticeManager,
    ) {
        $this->settingsManager = new SettingsManager($cacheManager, $databaseOptimizer);
        $this->tabManager = new TabManager();
        $this->noticeManager = $noticeManager;
        $this->featureSettings = new FeatureSettingsManager(new ServerConfigGenerator());
        $this->initializeHooks();
    }

    private function initializeHooks(): void
    {
        add_action("admin_menu", [$this, "registerAdminMenu"]);
        add_action("admin_bar_menu", [$this, "registerAdminBarNode"], 99);
        add_action("admin_enqueue_scripts", [$this, "enqueueAssets"]);
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
            "rest_url" => rest_url('wps-cache/v1/lab'),
            "rest_nonce" => wp_create_nonce('wp_rest'),
            "preload_concurrency" => max(1, min(10, (int) (((array) get_option('wpsc_settings', []))['preload_concurrency'] ?? 2))),
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
                "image_complete" => __("Image optimization complete.", "wps-cache"),
            ],
        ]);
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can("manage_options")) {
            return;
        }
        remove_all_actions("admin_notices");
        $current_tab = $this->tabManager->getCurrentTab();

        $pages = [
            "dashboard" => ["Dashboard", "A clear view of cache health and the fastest actions."],
            "cache" => ["Cache", "Configure page cache, object cache, and origin integrations."],
            "delivery" => ["Cache delivery", "Control cache identity, request safety, preload, and purging."],
            "css_js" => ["CSS & JavaScript", "Make assets smaller and change when they execute."],
            "experience" => ["Frontend optimization", "Tune critical rendering, resource hints, and browser delivery."],
            "media" => ["Media", "Improve loading behavior for images, embeds, and video."],
            "images" => ["Image optimization", "Compress, convert, adapt, and offload your media library."],
            "cdn" => ["CDN & Cloudflare", "Connect global delivery and coordinate edge-cache purges."],
            "database" => ["Database cleanup", "Schedule safe cleanup or run a targeted optimization now."],
            "monitoring" => ["Monitoring", "Track real-user performance, availability, and lab results."],
            "tweaks" => ["WordPress tweaks", "Remove optional overhead and harden common WordPress surfaces."],
            "tools" => ["Tools & diagnostics", "Preview, roll back, migrate, and inspect your configuration."],
        ];
        [$pageTitle, $pageDescription] = $pages[$current_tab] ?? ["Settings", "Configure WPS Cache."];
        ?>
        <div class="wpsc-wrap">
            <noscript><style>.wpsc-conditional[hidden]{display:block}</style></noscript>
            <div class="wpsc-app-container">
                <aside class="wpsc-sidebar" id="wpsc-sidebar" aria-label="WPS Cache navigation">
                    <div class="wpsc-brand">
                        <span class="wpsc-brand-mark" aria-hidden="true"><span class="dashicons dashicons-performance"></span></span>
                        <span class="wpsc-brand-copy">
                            <strong>WPS Cache</strong>
                            <small>Performance suite</small>
                        </span>
                    </div>
                    <?php $this->tabManager->renderSidebar($current_tab); ?>
                    <div class="wpsc-sidebar-footer">
                        <span class="wpsc-version-dot" aria-hidden="true"></span>
                        <span>WPS Cache <?php echo esc_html(WPSC_VERSION); ?></span>
                    </div>
                </aside>
                <button type="button" class="wpsc-sidebar-scrim" data-wpsc-sidebar-close aria-label="Close navigation" tabindex="-1"></button>
                <main class="wpsc-content-area">
                    <header class="wpsc-header-bar">
                        <div class="wpsc-header-leading">
                            <button type="button" class="wpsc-icon-btn wpsc-menu-toggle" data-wpsc-sidebar-toggle aria-controls="wpsc-sidebar" aria-expanded="false" aria-label="Open navigation">
                                <span class="dashicons dashicons-menu-alt3" aria-hidden="true"></span>
                            </button>
                            <div>
                                <span class="wpsc-page-eyebrow">Performance workspace</span>
                                <h2 class="wpsc-page-title"><?php echo esc_html($pageTitle); ?></h2>
                                <p class="wpsc-page-description"><?php echo esc_html($pageDescription); ?></p>
                            </div>
                        </div>
                        <div class="wpsc-actions">
                            <span class="wpsc-header-status"><span aria-hidden="true"></span>System ready</span>
                            <a href="<?php echo esc_url(
                                wp_nonce_url(
                                    admin_url(
                                        "admin-post.php?action=wpsc_clear_cache",
                                    ),
                                    "wpsc_clear_cache",
                                ),
                            ); ?>"
                               class="wpsc-btn-ghost-danger wpsc-confirm-trigger" data-loading-text="Purging…">
                               <span class="dashicons dashicons-trash" aria-hidden="true"></span> Purge cache
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
                            case "delivery":
                                $this->featureSettings->renderDeliveryTab();
                                break;
                            case "experience":
                                $this->featureSettings->renderExperienceTab();
                                break;
                            case "images":
                                $this->featureSettings->renderImagesTab();
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
                            case "monitoring":
                                $this->featureSettings->renderMonitoringTab();
                                break;
                            case "tools":
                                $this->featureSettings->renderToolsTab();
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
