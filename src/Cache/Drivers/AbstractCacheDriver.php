<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Abstract base class providing common cache driver functionality
 */
abstract class AbstractCacheDriver implements CacheDriverInterface {
    protected bool $initialized = false;
    
    /**
     * Generate a standardized cache key
     */
    protected function generateCacheKey(string $key): string {
        return md5($key);
    }
    
    /**
     * Log cache-related errors consistently.
     * Debug logging has been disabled to prevent output in production.
     */
    protected function logError(string $message, ?\Throwable $e = null): void {
        // Debug logging disabled in production.
        // Optionally, implement logging to a file or monitoring system in a development environment.
    }
    
    /**
     * Ensure cache directory exists and is writable using WP_Filesystem.
     */
    protected function ensureCacheDirectory(string $dir): bool {
        if (!function_exists('WP_Filesystem')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        WP_Filesystem();
        global $wp_filesystem;
        
        if (!$wp_filesystem->is_dir($dir)) {
            if (!$wp_filesystem->mkdir($dir, 0755)) {
                $this->logError("Failed to create cache directory: $dir");
                return false;
            }
        }
        
        if (!$wp_filesystem->is_writable($dir)) {
            $this->logError("Cache directory is not writable: $dir");
            return false;
        }
        
        return true;
    }
}
