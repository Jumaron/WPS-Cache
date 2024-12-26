<?php
declare(strict_types=1);

namespace WPSCache;

use WPSCache\Cache\CacheManager;
use WPSCache\Admin\AdminPanelManager;
use WPSCache\Cache\Drivers\{HTMLCache, RedisCache, VarnishCache, MinifyCSS, MinifyJS};

final class Plugin {
    private static ?self $instance = null;
    private CacheManager $cache_manager;
    private ?AdminPanelManager $admin_panel_manager = null;

    public static function getInstance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        // Private constructor for singleton
    }

    private function setWPCache(bool $enabled): bool {
        $config_file = ABSPATH . 'wp-config.php';
        if (!file_exists($config_file)) {
            error_log('WPS Cache Error: wp-config.php not found');
            return false;
        }

        $config_content = file_get_contents($config_file);
        if ($config_content === false) {
            error_log('WPS Cache Error: Unable to read wp-config.php');
            return false;
        }

        // Check if WP_CACHE is already defined
        $wp_cache_defined = preg_match("/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;/i", $config_content);

        if ($enabled) {
            // Add or update WP_CACHE definition
            if ($wp_cache_defined) {
                $config_content = preg_replace(
                    "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;/i",
                    "define('WP_CACHE', true);",
                    $config_content
                );
            } else {
                // Add after first <?php tag
                $config_content = preg_replace(
                    '/<\?php/',
                    "<?php\ndefine('WP_CACHE', true);",
                    $config_content,
                    1
                );
            }
        } else {
            // Remove WP_CACHE definition if it exists
            if ($wp_cache_defined) {
                $config_content = preg_replace(
                    "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;\n?/i",
                    "",
                    $config_content
                );
            }
        }

        // Create backup
        $backup_file = $config_file . '.backup-' . time();
        if (!@copy($config_file, $backup_file)) {
            error_log('WPS Cache Error: Unable to create wp-config.php backup');
            return false;
        }

        // Write updated content
        if (@file_put_contents($config_file, $config_content) === false) {
            // Restore backup if write fails
            @copy($backup_file, $config_file);
            error_log('WPS Cache Error: Unable to update wp-config.php');
            return false;
        }

        return true;
    }

    public function initialize(): void {
        $this->setupConstants();
        $this->initializeCacheManager();
        $this->setupHooks();

        if (is_admin()) {
            $this->initializeAdmin();
        }

        // Initialize cache early
        add_action('plugins_loaded', [$this->cache_manager, 'initializeCache'], 5);
    }

    private function setupConstants(): void {
        $plugin_file = trailingslashit(dirname(__DIR__)) . 'wps-cache.php';

        if (!defined('WPSC_VERSION')) {
            define('WPSC_VERSION', '0.0.1');
        }
        if (!defined('WPSC_PLUGIN_FILE')) {
            define('WPSC_PLUGIN_FILE', $plugin_file);
        }
        if (!defined('WPSC_PLUGIN_DIR')) {
            define('WPSC_PLUGIN_DIR', plugin_dir_path($plugin_file));
        }
        if (!defined('WPSC_PLUGIN_URL')) {
            define('WPSC_PLUGIN_URL', plugin_dir_url($plugin_file));
        }
        if (!defined('WPSC_CACHE_DIR')) {
            define('WPSC_CACHE_DIR', WP_CONTENT_DIR . '/cache/wps-cache/');
        }
    }

    private function initializeCacheManager(): void {
        $this->cache_manager = new CacheManager();
        $settings = get_option('wpsc_settings', []);

        if ($settings['html_cache'] ?? false) {
            $this->cache_manager->addDriver(new HTMLCache());
        }

        if ($settings['redis_cache'] ?? false) {
            $redis = new RedisCache(
                $settings['redis_host'] ?? '127.0.0.1',
                (int) ($settings['redis_port'] ?? 6379),
                (int) ($settings['redis_db'] ?? 0),
                1.0,
                1.0,
                $settings['redis_password'] ?? null,
                $settings['redis_prefix'] ?? 'wpsc:'
            );
            $this->cache_manager->addDriver($redis);
        }

        if ($settings['varnish_cache'] ?? false) {
            $this->cache_manager->addDriver(new VarnishCache(
                $settings['varnish_host'] ?? '127.0.0.1',
                (int) ($settings['varnish_port'] ?? 6081)
            ));
        }

        if ($settings['css_minify'] ?? false) {
            $this->cache_manager->addDriver(new MinifyCSS());
        }

        if ($settings['js_minify'] ?? false) {
            $this->cache_manager->addDriver(new MinifyJS());
        }
    }

    private function setupHooks(): void {
        // Core cache management hooks
        add_action('wpsc_clear_cache', [$this->cache_manager, 'clearAllCaches']);
        
        // Advanced cache clearing hooks
        add_action('switch_theme', [$this->cache_manager, 'clearAllCaches']);
        add_action('customize_save', [$this->cache_manager, 'clearAllCaches']);
        add_action('activated_plugin', [$this->cache_manager, 'clearAllCaches']);
        add_action('deactivated_plugin', [$this->cache_manager, 'clearAllCaches']);
        add_action('upgrader_process_complete', [$this->cache_manager, 'clearAllCaches']);

        // Plugin lifecycle hooks
        register_activation_hook(WPSC_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WPSC_PLUGIN_FILE, [$this, 'deactivate']);
    }

    private function initializeAdmin(): void {
        $this->admin_panel_manager = new AdminPanelManager($this->cache_manager);
    }

    public function activate(): void {
        // Create necessary directories
        $directories = [
            WPSC_CACHE_DIR,
            WPSC_CACHE_DIR . 'html',
            WPSC_PLUGIN_DIR . 'includes'
        ];
            
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    
        // Create .htaccess file for security
        $htaccess_file = WPSC_CACHE_DIR . '.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "Order Deny,Allow\nDeny from all";
            @file_put_contents($htaccess_file, $htaccess_content);
        }
    
        // Enable WP_CACHE in wp-config.php
        if (!$this->setWPCache(true)) {
            error_log('WPS Cache Warning: Failed to enable WP_CACHE in wp-config.php');
        }
    
        // Copy advanced-cache.php template
        $template_file = WPSC_PLUGIN_DIR . 'includes/advanced-cache-template.php';
        $target_file = WP_CONTENT_DIR . '/advanced-cache.php';
        if (!@copy($template_file, $target_file)) {
            error_log('WPS Cache Warning: Failed to create advanced-cache.php');
        }
    

        // Set default settings if they don't exist
        if (!get_option('wpsc_settings')) {
            update_option('wpsc_settings', [
                'html_cache' => true,
                'redis_cache' => false,
                'varnish_cache' => false,
                'css_minify' => false,
                'js_minify' => false,
                'cache_lifetime' => 3600,
                'excluded_urls' => [],
                'excluded_css' => [],
                'excluded_js' => [],
                'redis_host' => '127.0.0.1',
                'redis_port' => 6379,
                'redis_db' => 0,
                'redis_password' => '',
                'redis_prefix' => 'wpsc:',
                'varnish_host' => '127.0.0.1',
                'varnish_port' => 6081,
            ]);
        }

        // Schedule cache cleanup events
        if (!wp_next_scheduled('wpsc_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wpsc_cache_cleanup');
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        // Disable WP_CACHE in wp-config.php
        if (!$this->setWPCache(false)) {
            error_log('WPS Cache Warning: Failed to disable WP_CACHE in wp-config.php');
        }

        // Clear all caches
        $this->cache_manager->clearAllCaches();

        // Remove the object cache drop-in if it exists and matches ours
        $object_cache_file = WP_CONTENT_DIR . '/object-cache.php';
        if (file_exists($object_cache_file)) {
            $our_signature = 'WPS Cache - Redis Object Cache Drop-in';
            $file_contents = file_get_contents($object_cache_file);
            if (strpos($file_contents, $our_signature) !== false) {
                @unlink($object_cache_file);
            }
        }

        // Clear scheduled events
        wp_clear_scheduled_hook('wpsc_cache_cleanup');

        // Remove advanced-cache.php if it exists and matches ours
        $advanced_cache_file = WP_CONTENT_DIR . '/advanced-cache.php';
        if (file_exists($advanced_cache_file)) {
            $our_signature = 'WPS Cache - Advanced Cache Drop-in';
            $file_contents = file_get_contents($advanced_cache_file);
            if (strpos($file_contents, $our_signature) !== false) {
                @unlink($advanced_cache_file);
            }
        }
    }

    public function getCacheManager(): CacheManager {
        return $this->cache_manager;
    }
}