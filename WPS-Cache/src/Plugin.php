<?php

declare(strict_types=1);

namespace WPSCache;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\AdminPanelManager;
use WPSCache\Cache\Drivers\{HTMLCache, RedisCache, VarnishCache, MinifyCSS, MinifyJS};
use WPSCache\Server\ServerConfigManager;
use WPSCache\Cron\CronManager;
use WPSCache\Optimization\SpeculativeLoader;

/**
 * Main plugin class handling initialization, DI container, and lifecycle management.
 */
final class Plugin
{
    public const DEFAULT_SETTINGS = [
        'html_cache'       => true,
        'redis_cache'      => false,
        'varnish_cache'    => false,
        'css_minify'       => false,
        'css_async'        => false,
        'js_minify'        => false,
        'js_defer'         => false,
        'js_delay'         => false,

        // Speculative Loading (SOTA)
        'speculative_loading' => false, // Feature Toggle
        'speculation_mode'    => 'prerender', // 'prefetch' or 'prerender'

        'enable_metrics'   => true,
        'metrics_retention' => 14,
        'preload_interval' => 'daily',
        'preload_urls'     => [],
        'cache_lifetime'   => 3600,
        'excluded_urls'    => [],
        'excluded_css'     => [],
        'excluded_js'      => [],
        'redis_host'       => '127.0.0.1',
        'redis_port'       => 6379,
        'redis_db'         => 0,
        'redis_password'   => '',
        'redis_prefix'     => 'wpsc:',
        'varnish_host'     => '127.0.0.1',
        'varnish_port'     => 6081,
    ];

    private const REQUIRED_DIRECTORIES = [
        'cache'    => 'cache/wps-cache/',
        'html'     => 'cache/wps-cache/html',
        'includes' => 'includes'
    ];

    private const HTACCESS_CONTENT = "Order Deny,Allow\nDeny from all";
    private const CACHE_CLEANUP_HOOK = 'wpsc_cache_cleanup';

    private static ?self $instance = null;

    // Service Container Properties
    private ?CacheManager $cacheManager = null;
    private ?ServerConfigManager $serverManager = null;
    private ?AdminPanelManager $adminPanelManager = null;
    private ?CronManager $cronManager = null;
    private ?SpeculativeLoader $speculativeLoader = null; // Added

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}
    private function __clone() {}

    public function initialize(): void
    {
        $this->setupConstants();

        // Initialize Core Services
        $this->cacheManager = new CacheManager();
        $this->serverManager = new ServerConfigManager();
        $this->cronManager = new CronManager();

        // Load Settings
        $settings = get_option('wpsc_settings', self::DEFAULT_SETTINGS);
        $settings = array_merge(self::DEFAULT_SETTINGS, is_array($settings) ? $settings : []);

        $this->initializeCacheDrivers($settings);
        $this->setupHooks();

        // Initialize Optimization Services
        $this->speculativeLoader = new SpeculativeLoader($settings);
        $this->speculativeLoader->initialize();

        // Initialize Cron Listener
        $this->cronManager->initialize();

        if (is_admin()) {
            $this->adminPanelManager = new AdminPanelManager($this->cacheManager);
        }

        // Initialize Cache Logic (Late binding)
        add_action('plugins_loaded', [$this->cacheManager, 'initializeCache'], 5);

        // Listen for settings updates to refresh .htaccess
        add_action('wpscac_settings_updated', [$this, 'refreshServerConfig']);
    }

    private function setupConstants(): void
    {
        if (!defined('WPSC_VERSION')) {
            define('WPSC_VERSION', \WPSCache\VERSION);
        }
        if (!defined('WPSC_PLUGIN_FILE')) {
            define('WPSC_PLUGIN_FILE', \WPSCache\FILE);
        }
        if (!defined('WPSC_PLUGIN_DIR')) {
            define('WPSC_PLUGIN_DIR', plugin_dir_path(\WPSCache\FILE));
        }
        if (!defined('WPSC_PLUGIN_URL')) {
            define('WPSC_PLUGIN_URL', plugin_dir_url(\WPSCache\FILE));
        }
        if (!defined('WPSC_CACHE_DIR')) {
            define('WPSC_CACHE_DIR', WP_CONTENT_DIR . '/cache/wps-cache/');
        }
    }

    private function initializeCacheDrivers(array $settings): void
    {
        if ($settings['html_cache']) {
            $this->cacheManager->addDriver(new HTMLCache());
        }

        if ($settings['redis_cache']) {
            $this->cacheManager->addDriver(new RedisCache(
                (string) $settings['redis_host'],
                (int) $settings['redis_port'],
                (int) $settings['redis_db'],
                1.0,
                1.0,
                (string) $settings['redis_password'],
                (string) $settings['redis_prefix']
            ));
        }

        if ($settings['varnish_cache']) {
            $this->cacheManager->addDriver(new VarnishCache(
                (string) $settings['varnish_host'],
                (int) $settings['varnish_port'],
                604800
            ));
        }

        if ($settings['css_minify']) {
            $this->cacheManager->addDriver(new MinifyCSS());
        }

        if ($settings['js_minify']) {
            $this->cacheManager->addDriver(new MinifyJS());
        }
    }

    private function setupHooks(): void
    {
        $clear_hooks = [
            'wpsc_clear_cache',
            'switch_theme',
            'customize_save',
            'activated_plugin',
            'deactivated_plugin',
            'upgrader_process_complete'
        ];

        foreach ($clear_hooks as $hook) {
            add_action($hook, [$this->cacheManager, 'clearAllCaches']);
        }

        register_activation_hook(WPSC_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WPSC_PLUGIN_FILE, [$this, 'deactivate']);
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
        wp_clear_scheduled_hook('wpsc_scheduled_preload');
        $this->serverManager->removeConfiguration();
    }

    public function refreshServerConfig(array $settings): void
    {
        if ($settings['html_cache'] ?? false) {
            $this->serverManager->applyConfiguration();
        } else {
            $this->serverManager->removeConfiguration();
        }
    }

    private function createRequiredDirectories(): void
    {
        foreach (self::REQUIRED_DIRECTORIES as $dir) {
            $path = WPSC_PLUGIN_DIR . $dir;
            if (!file_exists($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
                error_log("WPS Cache: Failed to create directory: $path");
            }
        }
    }

    private function secureCacheDirectory(): void
    {
        $htaccess = WPSC_CACHE_DIR . '.htaccess';
        if (!file_exists($htaccess)) {
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
        $config_file = ABSPATH . 'wp-config.php';
        if (!file_exists($config_file) || !is_writable($config_file)) return false;

        $fp = fopen($config_file, 'r+');
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        $content = fread($fp, filesize($config_file));
        $pattern = "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;/i";

        if ($enable) {
            if (preg_match($pattern, $content)) {
                $new_content = preg_replace($pattern, "define('WP_CACHE', true);", $content);
            } else {
                $new_content = preg_replace('/^<\?php/m', "<?php\r\ndefine('WP_CACHE', true);", $content, 1);
            }
        } else {
            $new_content = preg_replace("/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;\s*/i", "", $content);
        }

        if ($new_content !== null && $new_content !== $content) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $new_content);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    private function installAdvancedCache(): void
    {
        $src = WPSC_PLUGIN_DIR . 'includes/advanced-cache-template.php';
        $dest = WP_CONTENT_DIR . '/advanced-cache.php';

        if (file_exists($src) && !file_exists($dest)) {
            @copy($src, $dest);
        }
    }

    private function removeDropIns(): void
    {
        $files = [
            WP_CONTENT_DIR . '/advanced-cache.php',
            WP_CONTENT_DIR . '/object-cache.php'
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                if (str_contains($content, 'WPS-Cache') || str_contains($content, 'WPS Cache')) {
                    @unlink($file);
                }
            }
        }
    }

    private function setupDefaultSettings(): void
    {
        if (get_option('wpsc_settings') === false) {
            update_option('wpsc_settings', self::DEFAULT_SETTINGS);
        }
    }

    private function scheduleMaintenance(): void
    {
        if (!wp_next_scheduled(self::CACHE_CLEANUP_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CACHE_CLEANUP_HOOK);
        }
    }

    public function getCacheManager(): CacheManager
    {
        return $this->cacheManager;
    }
}
