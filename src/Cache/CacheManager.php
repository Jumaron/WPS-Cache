<?php
declare(strict_types=1);

namespace WPSCache\Cache;

use WPSCache\Cache\Drivers\CacheDriverInterface;

/**
 * Cache management class handling multiple cache drivers
 */
final class CacheManager {
    /** @var array<CacheDriverInterface> */
    private array $drivers = [];
    private bool $initialized = false;

    public function addDriver(CacheDriverInterface $driver): void {
        $this->drivers[] = $driver;
    }

    public function initializeCache(): void {
        if ($this->initialized) {
            return;
        }

        foreach ($this->drivers as $driver) {
            try {
                $driver->initialize();
            } catch (\Exception $e) {
                error_log('WPS Cache Error: ' . $e->getMessage());
            }
        }

        $this->initialized = true;
        
        // Add cache cleanup on common WordPress actions
        add_action('save_post', [$this, 'clearAllCaches']);
        add_action('comment_post', [$this, 'clearAllCaches']);
        add_action('switched_theme', [$this, 'clearAllCaches']);
        add_action('activated_plugin', [$this, 'clearAllCaches']);
        add_action('deactivated_plugin', [$this, 'clearAllCaches']);
    }

    public function clearAllCaches(): bool {
        $success = true;
        $cleared_drivers = [];
        
        try {
            // Clear all registered cache drivers
            foreach ($this->drivers as $driver) {
                try {
                    $driver->clear();
                    $cleared_drivers[] = get_class($driver);
                } catch (\Exception $e) {
                    error_log('WPS Cache Error clearing ' . get_class($driver) . ': ' . $e->getMessage());
                    $success = false;
                }
            }

            // Clear WordPress core caches
            wp_cache_flush();
            
            // Clear page cache files
            $this->clearPageCache();

            // Clear object cache transients
            $this->clearTransients();

            // Clear opcode cache if available
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            // Trigger cache clear action for extensions
            do_action('wpsc_cache_cleared', $success, $cleared_drivers);

            return $success;
        } catch (\Exception $e) {
            error_log('WPS Cache Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets a specific cache driver by type
     */
    public function getDriver(string $type): ?CacheDriverInterface {
        foreach ($this->drivers as $driver) {
            if (str_contains(strtolower(get_class($driver)), strtolower($type))) {
                return $driver;
            }
        }
        return null;
    }

    /**
     * Clears only the HTML cache
     */
    public function clearHtmlCache(): bool {
        try {
            $this->clearPageCache();
            return true;
        } catch (\Exception $e) {
            error_log('WPS Cache Error clearing HTML cache: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clears only the Redis cache
     */
    public function clearRedisCache(): bool {
        try {
            $redis_driver = $this->getDriver('redis');
            if ($redis_driver) {
                $redis_driver->clear();
                return true;
            }
            return false;
        } catch (\Exception $e) {
            error_log('WPS Cache Error clearing Redis cache: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clears only the Varnish cache
     */
    public function clearVarnishCache(): bool {
        try {
            $varnish_driver = $this->getDriver('varnish');
            if ($varnish_driver) {
                $varnish_driver->clear();
                return true;
            }
            return false;
        } catch (\Exception $e) {
            error_log('WPS Cache Error clearing Varnish cache: ' . $e->getMessage());
            return false;
        }
    }

    private function clearPageCache(): void {
        $cache_dir = WPSC_CACHE_DIR . 'html/';
        if (is_dir($cache_dir)) {
            $files = glob($cache_dir . '*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }
    }

    private function clearTransients(): void {
        global $wpdb;
        
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
        wp_cache_flush();
    }
}