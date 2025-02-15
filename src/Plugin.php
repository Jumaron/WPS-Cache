<?php
declare(strict_types=1);

namespace WPSCache;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\AdminPanelManager;
use WPSCache\Cache\Drivers\{HTMLCache, RedisCache, VarnishCache, MinifyCSS, MinifyJS};

/**
 * Main plugin class handling initialization and lifecycle management
 */
final class Plugin {
    private const DEFAULT_SETTINGS = [
        'html_cache'     => true,
        'redis_cache'    => false,
        'varnish_cache'  => false,
        'css_minify'     => false,
        'js_minify'      => false,
        'cache_lifetime' => 3600,
        'excluded_urls'  => [],
        'excluded_css'   => [],
        'excluded_js'    => [],
        'redis_host'     => '127.0.0.1',
        'redis_port'     => 6379,
        'redis_db'       => 0,
        'redis_password' => '',
        'redis_prefix'   => 'wpsc:',
        'varnish_host'   => '127.0.0.1',
        'varnish_port'   => 6081,
    ];

    private const REQUIRED_DIRECTORIES = [
        'cache'    => 'cache/wps-cache/',
        'html'     => 'cache/wps-cache/html',
        'includes' => 'includes'
    ];

    private const HTACCESS_CONTENT    = "Order Deny,Allow\nDeny from all";
    private const CACHE_CLEANUP_HOOK  = 'wpsc_cache_cleanup';

    private static ?self $instance = null;
    private CacheManager $cache_manager;
    private ?AdminPanelManager $admin_panel_manager = null;

    /**
     * Gets singleton instance
     */
    public static function getInstance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}
    private function __clone() {}

    /**
     * Initializes the plugin
     */
    public function initialize(): void {
        $this->setupConstants();
        $this->initializeCacheManager();
        $this->setupHooks();

        if (is_admin()) {
            $this->initializeAdmin();
        }

        add_action('plugins_loaded', [$this->cache_manager, 'initializeCache'], 5);
    }

    /**
     * Sets up required constants
     */
    private function setupConstants(): void {
        $plugin_file = trailingslashit(dirname(__DIR__)) . 'wps-cache.php';
        $constants = [
            'WPSC_VERSION'      => '0.0.3',
            'WPSC_PLUGIN_FILE'  => $plugin_file,
            'WPSC_PLUGIN_DIR'   => plugin_dir_path($plugin_file),
            'WPSC_PLUGIN_URL'   => plugin_dir_url($plugin_file),
            'WPSC_CACHE_DIR'    => WP_CONTENT_DIR . '/cache/wps-cache/',
        ];

        foreach ($constants as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }

    /**
     * Initializes cache manager and drivers
     */
    private function initializeCacheManager(): void {
        $this->cache_manager = new CacheManager();
        $settings = get_option('wpsc_settings', self::DEFAULT_SETTINGS);

        $this->initializeCacheDrivers($settings);
    }

    /**
     * Initializes individual cache drivers based on settings
     */
    private function initializeCacheDrivers(array $settings): void {
        // HTML Cache
        if ($settings['html_cache']) {
            $this->cache_manager->addDriver(new HTMLCache());
        }

        // Redis Cache
        if ($settings['redis_cache']) {
            $this->cache_manager->addDriver(new RedisCache(
                $settings['redis_host'],
                (int) $settings['redis_port'],
                (int) $settings['redis_db'],
                1.0,
                1.0,
                $settings['redis_password'],
                $settings['redis_prefix']
            ));
        }

        // Varnish Cache
        if ($settings['varnish_cache']) {
            $this->cache_manager->addDriver(new VarnishCache(
                $settings['varnish_host'],
                (int) $settings['varnish_port'],
                604800 // 1 week
            ));
        }

        // CSS Minification
        if ($settings['css_minify']) {
            $this->cache_manager->addDriver(new MinifyCSS());
        }

        // JS Minification
        if ($settings['js_minify']) {
            $this->cache_manager->addDriver(new MinifyJS());
        }
    }

    /**
     * Sets up WordPress hooks
     */
    private function setupHooks(): void {
        // Cache clearing hooks
        $clear_cache_hooks = [
            'wpsc_clear_cache',
            'switch_theme',
            'customize_save',
            'activated_plugin',
            'deactivated_plugin',
            'upgrader_process_complete'
        ];

        foreach ($clear_cache_hooks as $hook) {
            add_action($hook, [$this->cache_manager, 'clearAllCaches']);
        }

        // Lifecycle hooks
        register_activation_hook(WPSC_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WPSC_PLUGIN_FILE, [$this, 'deactivate']);
    }

    /**
     * Initializes admin panel
     */
    private function initializeAdmin(): void {
        $this->admin_panel_manager = new AdminPanelManager($this->cache_manager);
    }

    /**
     * Activates the plugin
     */
    public function activate(): void {
        $this->createRequiredDirectories();
        $this->createHtaccessFile();
        $this->enableWPCache();
        $this->copyAdvancedCache();
        $this->setupDefaultSettings();
        $this->scheduleCacheCleanup();
        
        flush_rewrite_rules();
    }

    /**
     * Creates required directories using the WP_Filesystem API
     */
    private function createRequiredDirectories(): void {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            WP_Filesystem();
        }
        foreach (self::REQUIRED_DIRECTORIES as $dir) {
            $path = WPSC_PLUGIN_DIR . $dir;
            if (!file_exists($path)) {
                $wp_filesystem->mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Creates .htaccess file for security
     */
    private function createHtaccessFile(): void {
        $htaccess_file = WPSC_CACHE_DIR . '.htaccess';
        if (!file_exists($htaccess_file)) {
            @file_put_contents($htaccess_file, self::HTACCESS_CONTENT);
        }
    }

    /**
     * Enables WP_CACHE constant in wp-config.php
     */
    private function enableWPCache(): void {
        if (!$this->setWPCache(true)) {
            error_log('WPS Cache Warning: Failed to enable WP_CACHE in wp-config.php');
        }
    }

    /**
     * Copies advanced-cache.php template
     */
    private function copyAdvancedCache(): void {
        $template_file = WPSC_PLUGIN_DIR . 'includes/advanced-cache-template.php';
        $target_file = WP_CONTENT_DIR . '/advanced-cache.php';
        
        if (!@copy($template_file, $target_file)) {
            error_log('WPS Cache Warning: Failed to create advanced-cache.php');
        }
    }

    /**
     * Sets up default settings
     */
    private function setupDefaultSettings(): void {
        if (!get_option('wpsc_settings')) {
            update_option('wpsc_settings', self::DEFAULT_SETTINGS);
        }
    }

    /**
     * Schedules cache cleanup
     */
    private function scheduleCacheCleanup(): void {
        if (!wp_next_scheduled(self::CACHE_CLEANUP_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CACHE_CLEANUP_HOOK);
        }
    }

    /**
     * Deactivates the plugin
     */
    public function deactivate(): void {
        $this->disableWPCache();
        $this->clearAllCaches();
        $this->removeDropIns();
        $this->clearScheduledEvents();
    }

    /**
     * Manages WP_CACHE constant in wp-config.php
     */
    private function setWPCache(bool $enabled): bool {
        $config_file = ABSPATH . 'wp-config.php';
        
        if (!$this->isConfigFileAccessible($config_file)) {
            return false;
        }

        $config_content = file_get_contents($config_file);
        if ($config_content === false) {
            error_log('WPS Cache Error: Unable to read wp-config.php');
            return false;
        }

        $updated_content = $this->updateWPCacheDefinition($config_content, $enabled);
        return $this->writeConfigFile($config_file, $updated_content);
    }

    /**
     * Checks if wp-config.php is accessible
     */
    private function isConfigFileAccessible(string $file): bool {
        if (!file_exists($file)) {
            error_log('WPS Cache Error: wp-config.php not found');
            return false;
        }
        return true;
    }

    /**
     * Updates WP_CACHE definition in config content
     */
    private function updateWPCacheDefinition(string $content, bool $enabled): string {
        $pattern = "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;/i";
        $wp_cache_defined = preg_match($pattern, $content);

        if ($enabled) {
            if ($wp_cache_defined) {
                return preg_replace($pattern, "define('WP_CACHE', true);", $content);
            }
            return preg_replace('/<\?php/', "<?php\ndefine('WP_CACHE', true);", $content, 1);
        }

        if ($wp_cache_defined) {
            return preg_replace("/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;\n?/i", "", $content);
        }

        return $content;
    }

    /**
     * Writes updated wp-config.php with backup
     */
    private function writeConfigFile(string $file, string $content): bool {
        // Create backup
        $backup_file = $file . '.backup-' . time();
        if (!@copy($file, $backup_file)) {
            error_log('WPS Cache Error: Unable to create wp-config.php backup');
            return false;
        }

        // Write updated content
        if (@file_put_contents($file, $content) === false) {
            @copy($backup_file, $file); // Restore backup if write fails
            error_log('WPS Cache Error: Unable to update wp-config.php');
            return false;
        }

        return true;
    }

    /**
     * Disables WP_CACHE constant
     */
    private function disableWPCache(): void {
        if (!$this->setWPCache(false)) {
            error_log('WPS Cache Warning: Failed to disable WP_CACHE in wp-config.php');
        }
    }

    /**
     * Clears all caches
     */
    private function clearAllCaches(): void {
        $this->cache_manager->clearAllCaches();
    }

    /**
     * Removes cache-related drop-ins
     */
    private function removeDropIns(): void {
        $this->removeObjectCache();
        $this->removeAdvancedCache();
    }

    /**
     * Removes object cache drop-in
     */
    private function removeObjectCache(): void {
        $file = WP_CONTENT_DIR . '/object-cache.php';
        $signature = 'WPS Cache - Redis Object Cache Drop-in';
        $this->removeDropIn($file, $signature);
    }

    /**
     * Removes advanced cache drop-in
     */
    private function removeAdvancedCache(): void {
        $file = WP_CONTENT_DIR . '/advanced-cache.php';
        $signature = 'WPS Cache - Advanced Cache Drop-in';
        $this->removeDropIn($file, $signature);
    }

    /**
     * Removes a drop-in file if it matches our signature using wp_delete_file()
     */
    private function removeDropIn(string $file, string $signature): void {
        if (file_exists($file)) {
            $contents = file_get_contents($file);
            if ($contents && strpos($contents, $signature) !== false) {
                wp_delete_file($file);
            }
        }
    }

    /**
     * Clears scheduled events
     */
    private function clearScheduledEvents(): void {
        wp_clear_scheduled_hook(self::CACHE_CLEANUP_HOOK);
    }

    /**
     * Gets cache manager instance
     */
    public function getCacheManager(): CacheManager {
        return $this->cache_manager;
    }
}