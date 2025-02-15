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
     * Log cache-related errors consistently
     */
    protected function logError(string $message, ?\Throwable $e = null): void {
        $error = sprintf(
            'WPS Cache Error [%s]: %s%s',
            static::class,
            $message,
            $e ? ' - ' . $e->getMessage() : ''
        );
        
        error_log($error);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Escape the error message before outputting it as a warning.
            trigger_error(esc_html($error), E_USER_WARNING);
        }
    }
    
    /**
     * Ensure cache directory exists and is writable
     */
    protected function ensureCacheDirectory(string $dir): bool {
        if (!file_exists($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                $this->logError("Failed to create cache directory: $dir");
                return false;
            }
        }
        
        if (!is_writable($dir)) {
            $this->logError("Cache directory is not writable: $dir");
            return false;
        }
        
        return true;
    }
}