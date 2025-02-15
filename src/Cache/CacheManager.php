<?php
declare(strict_types=1);

namespace WPSCache\Cache;

use WPSCache\Cache\Drivers\CacheDriverInterface;
use Throwable;

/**
 * Cache management class handling multiple cache drivers
 */
final class CacheManager {
    private const CACHE_CLEANUP_HOOKS = [
        'save_post',
        'comment_post',
        'switched_theme',
        'activated_plugin',
        'deactivated_plugin'
    ];

    private const TRANSIENT_PATTERNS = [
        '_transient_%',
        '_site_transient_%'
    ];

    /** @var array<CacheDriverInterface> */
    private array $drivers = [];
    private bool $initialized = false;
    private array $clearLog = [];

    /**
     * Adds a cache driver to the manager
     */
    public function addDriver(CacheDriverInterface $driver): void {
        $this->drivers[] = $driver;
    }

    /**
     * Initializes all cache drivers and sets up hooks
     */
    public function initializeCache(): void {
        if ($this->initialized) {
            return;
        }

        $this->initializeDrivers();
        $this->setupCacheHooks();
        $this->initialized = true;
    }

    /**
     * Initializes individual cache drivers
     */
    private function initializeDrivers(): void {
        foreach ($this->drivers as $driver) {
            try {
                $driver->initialize();
            } catch (Throwable $e) {
                $this->logError(sprintf(
                    'Failed to initialize driver %s: %s',
                    get_class($driver),
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Sets up WordPress cache cleanup hooks
     */
    private function setupCacheHooks(): void {
        foreach (self::CACHE_CLEANUP_HOOKS as $hook) {
            add_action($hook, [$this, 'clearAllCaches']);
        }
    }

    /**
     * Clears all caches including drivers, WordPress core, and opcache
     */
    public function clearAllCaches(): bool {
        $this->clearLog = [];
        $success = true;

        try {
            // Clear registered drivers
            $this->clearDriverCaches();

            // Clear WordPress caches
            $this->clearWordPressCaches();

            // Clear opcache if available
            $this->clearOpCache();

            // Notify extensions
            $this->notifyExtensions();

            return $success && empty($this->clearLog);
        } catch (Throwable $e) {
            $this->logError('Failed to clear all caches: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clears all registered driver caches
     */
    private function clearDriverCaches(): void {
        foreach ($this->drivers as $driver) {
            try {
                $driver->clear();
            } catch (Throwable $e) {
                $driver_class = get_class($driver);
                $this->logError("Failed to clear {$driver_class}: {$e->getMessage()}");
                $this->clearLog[$driver_class] = $e->getMessage();
            }
        }
    }

    /**
     * Clears WordPress core caches
     */
    private function clearWordPressCaches(): void {
        try {
            // Clear core cache
            wp_cache_flush();

            // Clear page cache
            $this->clearPageCache();

            // Clear transients
            $this->clearTransients();
        } catch (Throwable $e) {
            $this->logError('Failed to clear WordPress caches: ' . $e->getMessage());
            $this->clearLog['wordpress'] = $e->getMessage();
        }
    }

    /**
     * Clears PHP opcache if available
     */
    private function clearOpCache(): void {
        if (function_exists('opcache_reset')) {
            try {
                opcache_reset();
            } catch (Throwable $e) {
                $this->logError('Failed to clear opcache: ' . $e->getMessage());
                $this->clearLog['opcache'] = $e->getMessage();
            }
        }
    }

    /**
     * Notifies extensions about cache clearing
     */
    private function notifyExtensions(): void {
        $cleared_drivers = array_filter(
            array_map(fn($driver) => get_class($driver), $this->drivers),
            fn($driver) => !isset($this->clearLog[$driver])
        );

        do_action('wpsc_cache_cleared', empty($this->clearLog), $cleared_drivers);
    }

    /**
     * Gets a specific cache driver by type
     */
    public function getDriver(string $type): ?CacheDriverInterface {
        $type_lower = strtolower($type);
        foreach ($this->drivers as $driver) {
            if (str_contains(strtolower(get_class($driver)), $type_lower)) {
                return $driver;
            }
        }
        return null;
    }

    /**
     * Clears the HTML cache
     */
    public function clearHtmlCache(): bool {
        try {
            $this->clearPageCache();
            return true;
        } catch (Throwable $e) {
            $this->logError('Failed to clear HTML cache: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clears the Redis cache
     */
    public function clearRedisCache(): bool {
        return $this->clearSpecificCache('redis');
    }

    /**
     * Clears the Varnish cache
     */
    public function clearVarnishCache(): bool {
        return $this->clearSpecificCache('varnish');
    }

    /**
     * Clears a specific type of cache
     */
    private function clearSpecificCache(string $type): bool {
        try {
            $driver = $this->getDriver($type);
            if ($driver) {
                $driver->clear();
                return true;
            }
            return false;
        } catch (Throwable $e) {
            $this->logError(sprintf('Failed to clear %s cache: %s', $type, $e->getMessage()));
            return false;
        }
    }

    /**
     * Clears the page cache directory
     */
    private function clearPageCache(): void {
        $cache_dir = WPSC_CACHE_DIR . 'html/';
        if (!is_dir($cache_dir)) {
            return;
        }

        $files = glob($cache_dir . '*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $this->safeUnlink($file);
            }
        }
    }

    /**
     * Clears WordPress transients
     */
    private function clearTransients(): void {
        global $wpdb;
        
        foreach (self::TRANSIENT_PATTERNS as $pattern) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ));
        }
        
        wp_cache_flush();
    }

    /**
     * Safely deletes a file with error handling using wp_delete_file()
     */
    private function safeUnlink(string $file): void {
        try {
            if (!wp_delete_file($file)) {
                $this->logError("Failed to delete file: $file");
            }
        } catch (Throwable $e) {
            $this->logError("Error deleting file $file: " . $e->getMessage());
        }
    }

    /**
     * Logs an error message (disabled in production)
     */
    private function logError(string $message): void {
        // Debug logging removed to prevent error_log() usage in production.
    }
}
