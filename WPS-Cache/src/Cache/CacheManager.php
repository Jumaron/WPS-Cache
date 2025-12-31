<?php

declare(strict_types=1);

namespace WPSCache\Cache;

use WPSCache\Cache\Drivers\CacheDriverInterface;
use WPSCache\Cache\Drivers\RedisCache;
use WPSCache\Cache\Drivers\VarnishCache;
use Throwable;

/**
 * Orchestrates multiple cache drivers and handles global cache operations.
 */
final class CacheManager
{
    /** @var array<CacheDriverInterface> */
    private array $drivers = [];

    private bool $initialized = false;
    private array $errorLog = [];

    /**
     * Registers a driver instance.
     */
    public function addDriver(CacheDriverInterface $driver): void
    {
        $this->drivers[] = $driver;
    }

    /**
     * Bootstraps drivers and local hooks.
     */
    public function initializeCache(): void
    {
        if ($this->initialized) {
            return;
        }

        foreach ($this->drivers as $driver) {
            try {
                $driver->initialize();
            } catch (Throwable $e) {
                error_log(sprintf('WPS Cache: Driver %s failed to init: %s', get_class($driver), $e->getMessage()));
            }
        }

        $this->setupCacheHooks();
        $this->initialized = true;
    }

    private function setupCacheHooks(): void
    {
        // Content updates: Clear data caches only (No OpCache reset)
        add_action('save_post', [$this, 'clearContentCaches']);
        add_action('comment_post', [$this, 'clearContentCaches']);

        // System updates: Clear everything including OpCache
        add_action('switched_theme', [$this, 'clearAllCaches']);
        add_action('activated_plugin', [$this, 'clearAllCaches']);
        add_action('deactivated_plugin', [$this, 'clearAllCaches']);
    }

    /**
     * Clears content-related caches (Drivers + WP Internals) but preserves OpCache.
     * This prevents performance degradation on frequent content updates.
     */
    public function clearContentCaches(): bool
    {
        $this->errorLog = [];
        $success = true;

        // 1. Clear Drivers (HTML, Redis, Minified Assets)
        foreach ($this->drivers as $driver) {
            try {
                $driver->clear();
            } catch (Throwable $e) {
                $this->errorLog[] = get_class($driver) . ': ' . $e->getMessage();
                $success = false;
            }
        }

        // 2. Clear WordPress Internals (Object Cache & Transients)
        $this->clearWordPressInternals(false);

        // 3. Fire Signal
        do_action('wpsc_cache_cleared', $success, $this->errorLog);

        return $success && empty($this->errorLog);
    }

    /**
     * Master Switch: Clears every layer of caching available.
     */
    public function clearAllCaches(): bool
    {
        $this->errorLog = [];
        $success = true;

        // 1. Clear Drivers (HTML, Redis, Minified Assets)
        foreach ($this->drivers as $driver) {
            try {
                $driver->clear();
            } catch (Throwable $e) {
                $this->errorLog[] = get_class($driver) . ': ' . $e->getMessage();
                $success = false;
            }
        }

        // 2. Clear WordPress Internals (Object Cache & Transients)
        $this->clearWordPressInternals(true);

        // 3. Clear OpCache (PHP Code Cache)
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // 4. Fire Signal
        do_action('wpsc_cache_cleared', $success, $this->errorLog);

        return $success && empty($this->errorLog);
    }

    private function clearWordPressInternals(bool $full_flush = true): void
    {
        // Flush Memory Object Cache
        if ($full_flush) {
            wp_cache_flush();
        }

        // Flush Transients (DB) - SOTA Optimized
        $this->clearDatabaseTransients();

        // Remove physical page cache files if driver missing but files exist (cleanup)
        $this->forceCleanupHtmlDirectory();
    }

    /**
     * SOTA Optimization: Direct SQL deletion for transients.
     * WP's native delete_transient() is O(N) where N is number of transients (loops + hooks).
     * This implementation is O(1) for millions of rows.
     */
    private function clearDatabaseTransients(): void
    {
        global $wpdb;

        try {
            // Delete transient data (covers both data and timeouts)
            // _transient_% covers _transient_timeout_%
            // _site_transient_% covers _site_transient_timeout_%
            $wpdb->query(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE '\_transient\_%' 
                 OR option_name LIKE '\_site\_transient\_%'"
            );
        } catch (Throwable $e) {
            $this->errorLog['db'] = $e->getMessage();
        }
    }

    /**
     * Fallback to ensure HTML directory is empty even if driver isn't loaded.
     */
    private function forceCleanupHtmlDirectory(): void
    {
        if (defined('WPSC_CACHE_DIR')) {
            $html_dir = WPSC_CACHE_DIR . 'html/';
            if (is_dir($html_dir)) {
                $this->recursiveRemoveDir($html_dir);
                @mkdir($html_dir, 0755, true); // Recreate empty
            }
        }
    }

    private function recursiveRemoveDir(string $dir): void
    {
        if (!is_dir($dir)) return;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * Helper to retrieve specific driver instance.
     */
    public function getDriver(string $alias): ?CacheDriverInterface
    {
        foreach ($this->drivers as $driver) {
            if ($alias === 'redis' && $driver instanceof RedisCache) return $driver;
            if ($alias === 'varnish' && $driver instanceof VarnishCache) return $driver;
        }
        return null;
    }

    /**
     * Specific Clearing Methods (Used by Admin Buttons)
     */

    public function clearHtmlCache(): bool
    {
        // If HTML driver is loaded, use it
        foreach ($this->drivers as $driver) {
            if (str_contains(get_class($driver), 'HTMLCache')) {
                $driver->clear();
                return true;
            }
        }
        // Fallback
        $this->forceCleanupHtmlDirectory();
        return true;
    }

    public function clearRedisCache(): bool
    {
        $driver = $this->getDriver('redis');
        if ($driver) {
            $driver->clear();
            return true;
        }
        return false;
    }

    public function clearVarnishCache(): bool
    {
        $driver = $this->getDriver('varnish');
        if ($driver) {
            $driver->clear();
            return true;
        }
        return false;
    }
}
