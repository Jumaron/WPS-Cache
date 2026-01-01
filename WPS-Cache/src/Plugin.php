<?php

declare(strict_types=1);

namespace WPSCache;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\AdminPanelManager;
use WPSCache\Cache\Drivers\{HTMLCache, RedisCache, VarnishCache, MinifyCSS, MinifyJS};
use WPSCache\Server\ServerConfigManager;
use WPSCache\Cron\CronManager; // Added for Preloading

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
        'css_async'        => false, // Added
        'js_minify'        => false,
        'js_defer'         => false, // Added
        'js_delay'         => false, // Added
        'enable_metrics'   => true,  // Added
        'metrics_retention'=> 14,    // Added
        'preload_interval' => 'daily', // Added
        'preload_urls'     => [],    // Added
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

    // Sentinel: Whitelist public assets while blocking everything else (e.g. .php, .log)
    private const HTACCESS_CONTENT = "Order Deny,Allow\nDeny from all\n<FilesMatch \"\.(css|js|html|xml|txt)$\">\n    Order Allow,Deny\n    Allow from all\n</FilesMatch>";
    private const CACHE_CLEANUP_HOOK = 'wpsc_cache_cleanup';

    private static ?self $instance = null;

    // Service Container Properties
    private ?CacheManager $cacheManager = null;
    private ?ServerConfigManager $serverManager = null;
    private ?AdminPanelManager $adminPanelManager = null;
    private ?CronManager $cronManager = null; // Added

    /**
     * Singleton Accessor
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}
    private function __clone() {}

    /**
     * Bootstraps the plugin.
     */
    public function initialize(): void
    {
        $this->setupConstants();

        // Initialize Core Services
        $this->cacheManager = new CacheManager();
        $this->serverManager = new ServerConfigManager();
        $this->cronManager = new CronManager(); // Init Cron

        // Load Settings
        $settings = get_option('wpsc_settings', self::DEFAULT_SETTINGS);

        // Ensure settings array has all defaults
        $settings = array_merge(self::DEFAULT_SETTINGS, is_array($settings) ? $settings : []);

        $this->initializeCacheDrivers($settings);
        $this->setupHooks();

        // Initialize Cron Listener
        $this->cronManager->initialize();

        if (is_admin()) {
            $this->adminPanelManager = new AdminPanelManager($this->cacheManager);
        }

        // Initialize Cache Logic (Late binding)
        add_action('plugins_loaded', [$this->cacheManager, 'initializeCache'], 5);
        add_action('wpscac_settings_updated', [$this, 'refreshServerConfig']);

        // Pass settings update to CronManager as well
        add_action('wpscac_settings_updated', [$this->cronManager, 'updateSchedule']);
    }

    /**
     * Defines plugin-wide constants.
     */
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

    /**
     * Registers active drivers with the CacheManager.
     */
    private function initializeCacheDrivers(array $settings): void
    {
        // 1. HTML Cache (Disk)
        if ($settings['html_cache']) {
            $this->cacheManager->addDriver(new HTMLCache());
        }

        // 2. Redis Object Cache
        if ($settings['redis_cache']) {
            $this->cacheManager->addDriver(new RedisCache(
                (string) $settings['redis_host'],
                (int) $settings['redis_port'],
                (int) $settings['redis_db'],
                1.0, // timeout
                1.0, // read_timeout
                (string) $settings['redis_password'],
                (string) $settings['redis_prefix']
            ));
        }

        // 3. Varnish HTTP Cache
        if ($settings['varnish_cache']) {
            $this->cacheManager->addDriver(new VarnishCache(
                (string) $settings['varnish_host'],
                (int) $settings['varnish_port'],
                604800 // 1 week default
            ));
        }

        // 4. Asset Minification (Optimization Drivers)
        if ($settings['css_minify']) {
            $this->cacheManager->addDriver(new MinifyCSS());
        }

        if ($settings['js_minify']) {
            $this->cacheManager->addDriver(new MinifyJS());
        }
    }

    private function setupHooks(): void
    {
        // Auto-clear hooks
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

    /**
     * Activation Logic
     */
    public function activate(): void
    {
        $this->createRequiredDirectories();
        $this->secureCacheDirectory();
        $this->enableWPCacheConstant();
        $this->installAdvancedCache();
        $this->setupDefaultSettings();
        $this->scheduleMaintenance();

        // Apply .htaccess rules immediately
        $this->serverManager->applyConfiguration();

        // Flush permalinks
        flush_rewrite_rules();
    }

    /**
     * Deactivation Logic
     */
    public function deactivate(): void
    {
        $this->disableWPCacheConstant();
        $this->cacheManager->clearAllCaches();
        $this->removeDropIns();

        wp_clear_scheduled_hook(self::CACHE_CLEANUP_HOOK);
        wp_clear_scheduled_hook('wpsc_scheduled_preload'); // Clear Preload Cron

        // Remove .htaccess rules
        $this->serverManager->removeConfiguration();
    }

    /**
     * Refreshes server config on settings save.
     */
    public function refreshServerConfig(array $settings): void
    {
        if (!$this->serverManager) {
            return;
        }

        if ($settings['html_cache'] ?? false) {
            $this->serverManager->applyConfiguration();
        } else {
            $this->serverManager->removeConfiguration();
        }
    }

    /**
     * Native PHP directory creation (faster/more reliable than WP_Filesystem for cache dirs).
     */
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
        if (!$this->toggleWPCache(true)) {
            error_log('WPS Cache: Failed to enable WP_CACHE in wp-config.php');
        }
    }

    private function disableWPCacheConstant(): void
    {
        if (!$this->toggleWPCache(false)) {
            error_log('WPS Cache: Failed to disable WP_CACHE in wp-config.php');
        }
    }

    /**
     * Atomic wp-config.php modification.
     */
    private function toggleWPCache(bool $enable): bool
    {
        $config_file = ABSPATH . 'wp-config.php';

        if (!file_exists($config_file) || !is_writable($config_file)) {
            return false;
        }

        // Lock file access
        $fp = fopen($config_file, 'r+');
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        $content = fread($fp, filesize($config_file));
        if ($content === false) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        $pattern = "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;/i";

        if ($enable) {
            if (preg_match($pattern, $content)) {
                $new_content = preg_replace($pattern, "define('WP_CACHE', true);", $content);
            } else {
                // Insert after <?php
                $new_content = preg_replace('/^<\?php/m', "<?php\r\ndefine('WP_CACHE', true);", $content, 1);
            }
        } else {
            // Remove the line entirely
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
                // Only delete if it belongs to us
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

    // Accessors for Service Container
    public function getCacheManager(): CacheManager
    {
        return $this->cacheManager;
    }

    public function getServerManager(): ServerConfigManager
    {
        return $this->serverManager;
    }
}
