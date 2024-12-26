<?php
declare(strict_types=1);

namespace WPSCache\Cache;

use WPSCache\Cache\Interfaces\CacheDriverInterface;

/**
 * Simplified cache manager with better organization
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
        $this->setupWordPressHooks();
    }

    private function setupWordPressHooks(): void {
        $actions = [
            'save_post',
            'comment_post',
            'switched_theme',
            'activated_plugin',
            'deactivated_plugin'
        ];

        foreach ($actions as $action) {
            add_action($action, [$this, 'clearAllCaches']);
        }
    }

    public function clearAllCaches(): bool {
        $success = true;
        
        try {
            foreach ($this->drivers as $driver) {
                try {
                    $driver->clear();
                } catch (\Exception $e) {
                    error_log('WPS Cache Error clearing ' . get_class($driver) . ': ' . $e->getMessage());
                    $success = false;
                }
            }

            // Clear WordPress core caches
            wp_cache_flush();
            $this->clearTransients();

            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            do_action('wpsc_cache_cleared', $success);

            return $success;
        } catch (\Exception $e) {
            error_log('WPS Cache Error: ' . $e->getMessage());
            return false;
        }
    }

    private function clearTransients(): void {
        global $wpdb;
        
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
    }
}