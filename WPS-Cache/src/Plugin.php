<?php

declare(strict_types=1);

namespace WPSCache;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\AdminPanelManager;
use WPSCache\Cache\Drivers\{
    HTMLCache,
    RedisCache,
    VarnishCache,
    MinifyCSS,
    MinifyJS,
};
use WPSCache\Server\ServerConfigManager;
use WPSCache\Cron\CronManager;
use WPSCache\Optimization\SpeculativeLoader;
use WPSCache\Optimization\DatabaseOptimizer;
use WPSCache\Optimization\BloatOptimizer;
use WPSCache\Optimization\CdnManager;
use WPSCache\Compatibility\CommerceManager;

final class Plugin
{
    public const DEFAULT_SETTINGS = [
        "html_cache" => true,
        "redis_cache" => false,
        "varnish_cache" => false,
        "css_minify" => false,
        "remove_unused_css" => false,
        "js_minify" => false,
        "js_defer" => false,
        "js_delay" => false,
        "speculative_loading" => false,
        "speculation_mode" => "prerender",
        "media_lazy_load" => true,
        "media_lazy_load_iframes" => true,
        "media_lazy_load_exclude_count" => 3,
        "media_add_dimensions" => false,
        "media_youtube_facade" => false,
        "font_localize_google" => true,
        "font_display_swap" => true,

        // CDN & Cloudflare
        "cdn_enable" => false,
        "cdn_url" => "",
        "cf_enable" => false,
        "cf_api_token" => "",
        "cf_zone_id" => "",

        // Bloat
        "bloat_disable_emojis" => true,
        "bloat_disable_embeds" => true,
        "bloat_disable_xmlrpc" => true,
        "bloat_hide_wp_version" => true,
        "bloat_remove_wlw_rsd" => true,
        "bloat_remove_shortlink" => true,
        "bloat_disable_rss" => false,
        "bloat_disable_self_pingbacks" => true,
        "bloat_remove_jquery_migrate" => true,
        "bloat_remove_dashicons" => true,
        "bloat_remove_query_strings" => true,
        "heartbeat_frequency" => 60,
        "heartbeat_disable_admin" => false,
        "heartbeat_disable_dashboard" => false,
        "heartbeat_disable_editor" => false,
        "heartbeat_disable_frontend" => true,

        "enable_metrics" => true,
        "metrics_retention" => 14,
        "preload_interval" => "daily",
        "preload_urls" => [],
        "cache_lifetime" => 3600,
        "excluded_urls" => [],
        "excluded_css" => [],
        "excluded_js" => [],
        "redis_host" => "127.0.0.1",
        "redis_port" => 6379,
        "redis_db" => 0,
        "redis_password" => "",
        "redis_prefix" => "wpsc:",
        "varnish_host" => "127.0.0.1",
        "varnish_port" => 6081,
        "db_schedule" => "disabled",
        "db_clean_revisions" => true,
        "db_clean_auto_drafts" => true,
        "db_clean_trashed_posts" => true,
        "db_clean_spam_comments" => true,
        "db_clean_trashed_comments" => true,
        "db_clean_expired_transients" => true,
        "db_clean_all_transients" => false,
        "db_clean_optimize_tables" => true,

        // Compatibility
        "woo_support" => true,
    ];

    private const REQUIRED_DIRECTORIES = [
        "cache" => "cache/wps-cache/",
        "html" => "cache/wps-cache/html",
        "includes" => "includes",
    ];

    // Sentinel Fix: Explicitly allow safe static assets but strictly block PHP
    // This replaces the broken "Deny from all" which blocked fonts/css/js from being served.
    private const HTACCESS_CONTENT = "Order Deny,Allow\nDeny from all\n<FilesMatch \"\.(css|js|html|xml|txt|map|woff|woff2|ttf|otf|eot|svg|webp|png|jpg|jpeg|gif|avif)$\">\n    Allow from all\n</FilesMatch>";
    private const CACHE_CLEANUP_HOOK = "wpsc_cache_cleanup";
    private const DB_CLEANUP_HOOK = "wpsc_db_cleanup";

    private static ?self $instance = null;

    private ?CacheManager $cacheManager = null;
    private ?ServerConfigManager $serverManager = null;
    private ?AdminPanelManager $adminPanelManager = null;
    private ?CronManager $cronManager = null;
    private ?SpeculativeLoader $speculativeLoader = null;
    private ?DatabaseOptimizer $databaseOptimizer = null;
    private ?BloatOptimizer $bloatOptimizer = null;
    private ?CdnManager $cdnManager = null;
    private ?CommerceManager $commerceManager = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}
    private function __clone() {}

    public function initialize(): void
    {
        $this->setupConstants();

        $this->cacheManager = new CacheManager();
        $this->serverManager = new ServerConfigManager();
        $this->cronManager = new CronManager();

        $settings = get_option("wpsc_settings", self::DEFAULT_SETTINGS);
        $settings = array_merge(
            self::DEFAULT_SETTINGS,
            is_array($settings) ? $settings : [],
        );

        $this->commerceManager = new CommerceManager($settings);

        $this->initializeCacheDrivers($settings);
        $this->setupHooks();

        $this->speculativeLoader = new SpeculativeLoader($settings);
        $this->speculativeLoader->initialize();

        $this->databaseOptimizer = new DatabaseOptimizer($settings);

        $this->bloatOptimizer = new BloatOptimizer($settings);
        $this->bloatOptimizer->initialize();

        $this->cdnManager = new CdnManager($settings);
        $this->cdnManager->initialize();

        $this->cronManager->initialize();

        if (is_admin()) {
            $this->adminPanelManager = new AdminPanelManager(
                $this->cacheManager,
            );
        }

        add_action(
            "plugins_loaded",
            [$this->cacheManager, "initializeCache"],
            5,
        );
        add_action("wpscac_settings_updated", [$this, "refreshServerConfig"]);

        add_action("wpscac_settings_updated", [$this, "updateDbSchedule"]);
        add_action(self::DB_CLEANUP_HOOK, [
            $this->databaseOptimizer,
            "runScheduledCleanup",
        ]);
        add_action("wp_ajax_wpsc_manual_db_cleanup", [
            $this,
            "handleManualDbCleanup",
        ]);
    }

    private function setupConstants(): void
    {
        if (!defined("WPSC_VERSION")) {
            define("WPSC_VERSION", \WPSCache\VERSION);
        }
        if (!defined("WPSC_PLUGIN_FILE")) {
            define("WPSC_PLUGIN_FILE", \WPSCache\FILE);
        }
        if (!defined("WPSC_PLUGIN_DIR")) {
            define("WPSC_PLUGIN_DIR", plugin_dir_path(\WPSCache\FILE));
        }
        if (!defined("WPSC_PLUGIN_URL")) {
            define("WPSC_PLUGIN_URL", plugin_dir_url(\WPSCache\FILE));
        }
        if (!defined("WPSC_CACHE_DIR")) {
            define("WPSC_CACHE_DIR", WP_CONTENT_DIR . "/cache/wps-cache/");
        }
    }

    private function initializeCacheDrivers(array $settings): void
    {
        if ($settings["html_cache"]) {
            $this->cacheManager->addDriver(
                new HTMLCache($this->commerceManager),
            );
        }
        if ($settings["redis_cache"]) {
            $this->cacheManager->addDriver(
                new RedisCache(
                    (string) $settings["redis_host"],
                    (int) $settings["redis_port"],
                    (int) $settings["redis_db"],
                    1.0,
                    1.0,
                    (string) $settings["redis_password"],
                    (string) $settings["redis_prefix"],
                ),
            );
        }
        if ($settings["varnish_cache"]) {
            $this->cacheManager->addDriver(
                new VarnishCache(
                    (string) $settings["varnish_host"],
                    (int) $settings["varnish_port"],
                    604800,
                ),
            );
        }
        if ($settings["css_minify"]) {
            $this->cacheManager->addDriver(new MinifyCSS());
        }
        if ($settings["js_minify"]) {
            $this->cacheManager->addDriver(new MinifyJS());
        }
    }
    private function setupHooks(): void
    {
        add_action("send_headers", [$this->serverManager, "sendSecurityHeaders"]);

        $clear_hooks = [
            "wpsc_clear_cache",
            "switch_theme",
            "customize_save",
            "activated_plugin",
            "deactivated_plugin",
            "upgrader_process_complete",
        ];
        foreach ($clear_hooks as $hook) {
            add_action($hook, [$this->cacheManager, "clearAllCaches"]);
        }
        register_activation_hook(WPSC_PLUGIN_FILE, [$this, "activate"]);
        register_deactivation_hook(WPSC_PLUGIN_FILE, [$this, "deactivate"]);
    }
    public function activate(): void
    {
        $this->createRequiredDirectories();
        $this->secureCacheDirectory();
        $this->enableWPCacheConstant();
        $this->installAdvancedCache();
        $this->setupDefaultSettings();
        $this->scheduleMaintenance();
        $this->serverManager->applyConfiguration();
        flush_rewrite_rules();
    }
    public function deactivate(): void
    {
        $this->disableWPCacheConstant();
        $this->cacheManager->clearAllCaches();
        $this->removeDropIns();
        wp_clear_scheduled_hook(self::CACHE_CLEANUP_HOOK);
        wp_clear_scheduled_hook("wpsc_scheduled_preload");
        wp_clear_scheduled_hook(self::DB_CLEANUP_HOOK);
        $this->serverManager->removeConfiguration();
    }
    public function updateDbSchedule(array $settings): void
    {
        $interval = $settings["db_schedule"] ?? "disabled";
        wp_clear_scheduled_hook(self::DB_CLEANUP_HOOK);
        if ($interval !== "disabled") {
            $time = strtotime("tomorrow 00:00:00");
            wp_schedule_event($time, $interval, self::DB_CLEANUP_HOOK);
        }
    }
    public function handleManualDbCleanup(): void
    {
        try {
            check_ajax_referer("wpsc_ajax_nonce");
            if (!current_user_can("manage_options")) {
                wp_send_json_error();
            }
            $items = $_POST["items"] ?? [];

            // Sentinel Fix: Strict Input Validation to prevent TypeErrors
            if (!is_array($items)) {
                wp_send_json_error("Invalid input format");
            }

            // Sentinel Fix: Sanitize Input (Defense in Depth)
            $items = array_map("sanitize_key", array_filter($items, "is_string"));

            if (empty($items)) {
                wp_send_json_error("No items selected");
            }
            $count = $this->databaseOptimizer->processCleanup($items);
            wp_send_json_success("Cleaned $count categories of items.");
        } catch (\Throwable $e) {
            // Sentinel Fix: Prevent Info Leak
            // Catch unexpected errors to prevent stack trace exposure in JSON response
            error_log("WPS-Cache DB Cleanup Error: " . $e->getMessage());
            wp_send_json_error("An unexpected error occurred during cleanup.");
        }
    }
    public function refreshServerConfig(array $settings): void
    {
        if ($settings["html_cache"] ?? false) {
            $this->serverManager->applyConfiguration();
        } else {
            $this->serverManager->removeConfiguration();
        }
    }
    public function getDatabaseOptimizer(): DatabaseOptimizer
    {
        return $this->databaseOptimizer;
    }
    private function createRequiredDirectories(): void
    {
        foreach (self::REQUIRED_DIRECTORIES as $dir) {
            $path = WPSC_PLUGIN_DIR . $dir;
            if (
                !file_exists($path) &&
                !mkdir($path, 0755, true) &&
                !is_dir($path)
            ) {
                error_log("WPS Cache: Failed: $path");
            }
        }
    }
    private function secureCacheDirectory(): void
    {
        $htaccess = WPSC_CACHE_DIR . ".htaccess";

        // Sentinel Fix: Update .htaccess if it is missing OR if it contains the old restrictive/broken rule.
        // We check for "Deny from all" without the whitelist to detect the old version.
        $shouldUpdate = false;
        if (!file_exists($htaccess)) {
            $shouldUpdate = true;
        } else {
            $content = @file_get_contents($htaccess);
            if ($content && str_contains($content, "Deny from all") && !str_contains($content, "<FilesMatch")) {
                $shouldUpdate = true;
            }
        }

        if ($shouldUpdate) {
            @file_put_contents($htaccess, self::HTACCESS_CONTENT);
        }
    }
    private function enableWPCacheConstant(): void
    {
        $this->toggleWPCache(true);
    }
    private function disableWPCacheConstant(): void
    {
        $this->toggleWPCache(false);
    }
    private function toggleWPCache(bool $enable): bool
    {
        $config_file = ABSPATH . "wp-config.php";
        if (!file_exists($config_file) || !is_writable($config_file)) {
            return false;
        }
        $fp = fopen($config_file, "r+");
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }
        $content = fread($fp, filesize($config_file));
        $pattern =
            "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;/i";
        if ($enable) {
            if (preg_match($pattern, $content)) {
                $new = preg_replace(
                    $pattern,
                    "define('WP_CACHE', true);",
                    $content,
                );
            } else {
                $new = preg_replace(
                    "/^<\?php/m",
                    "<?php\r\ndefine('WP_CACHE', true);",
                    $content,
                    1,
                );
            }
        } else {
            $new = preg_replace(
                "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;\s*/i",
                "",
                $content,
            );
        }
        if ($new !== null && $new !== $content) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $new);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    private function installAdvancedCache(): void
    {
        $src = WPSC_PLUGIN_DIR . "includes/advanced-cache-template.php";
        $dest = WP_CONTENT_DIR . "/advanced-cache.php";
        if (file_exists($src) && !file_exists($dest)) {
            @copy($src, $dest);
        }
    }
    private function removeDropIns(): void
    {
        $files = [
            WP_CONTENT_DIR . "/advanced-cache.php",
            WP_CONTENT_DIR . "/object-cache.php",
        ];
        foreach ($files as $file) {
            if (
                file_exists($file) &&
                (str_contains(file_get_contents($file), "WPS-Cache") ||
                    str_contains(file_get_contents($file), "WPS Cache"))
            ) {
                @unlink($file);
            }
        }
    }
    private function setupDefaultSettings(): void
    {
        if (get_option("wpsc_settings") === false) {
            update_option("wpsc_settings", self::DEFAULT_SETTINGS);
        }
    }
    private function scheduleMaintenance(): void
    {
        if (!wp_next_scheduled(self::CACHE_CLEANUP_HOOK)) {
            wp_schedule_event(time(), "daily", self::CACHE_CLEANUP_HOOK);
        }
    }
    public function getCacheManager(): CacheManager
    {
        return $this->cacheManager;
    }
}
