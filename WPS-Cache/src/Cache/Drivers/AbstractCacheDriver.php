<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Abstract base class providing common cache driver functionality
 */
abstract class AbstractCacheDriver implements CacheDriverInterface
{
    protected bool $initialized = false;

    /**
     * Generate a standardized cache key
     */
    protected function generateCacheKey(string $key): string
    {
        return md5($key);
    }

    /**
     * Log cache-related errors consistently
     */
    protected function logError(string $message, ?\Throwable $e = null): void
    {
        $error = sprintf(
            'WPS Cache Error [%s]: %s%s',
            static::class,
            $message,
            $e ? ' - ' . $e->getMessage() : ''
        );

        error_log($error);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            trigger_error(esc_html($error), E_USER_WARNING);
        }
    }

    /**
     * Ensure cache directory exists and is writable.
     * 
     * FIX: Switched from WP_Filesystem to native PHP/WP functions.
     * The cache folder is strictly a runtime folder that the PHP process 
     * (www-data/nginx) must have write access to naturally. 
     * WP_Filesystem is overkill here and often causes "not writable" false negatives
     * if FS_METHOD is not direct.
     */
    protected function ensureCacheDirectory(string $dir): bool
    {
        // 1. Check if directory exists, if not create it recursively
        if (!is_dir($dir)) {
            // wp_mkdir_p uses native php mkdir recursively and respects umask
            if (!wp_mkdir_p($dir)) {
                // If it fails, try one last force attempt with 0755
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    $this->logError("Failed to create cache directory: $dir");
                    return false;
                }
            }
        }

        // 2. Check strict writability
        if (!is_writable($dir)) {
            $this->logError("Cache directory exists but is not writable: $dir");
            return false;
        }

        // 3. Create index.php silence file if missing
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', '<?php // Silence is golden');
        }

        return true;
    }

    /**
     * Writes content to a file atomically to prevent race conditions.
     * Writes to a temp file first, then renames.
     */
    protected function atomicWrite(string $file, string $content): bool
    {
        $dir = dirname($file);

        // Ensure dir exists before writing (just in case)
        if (!is_dir($dir)) {
            if (!$this->ensureCacheDirectory($dir)) {
                return false;
            }
        }

        $temp_file = $dir . '/' . uniqid('wpsc_tmp_', true) . '.tmp';

        if (@file_put_contents($temp_file, $content) === false) {
            return false;
        }

        // Set permissions before rename (standard file permissions)
        @chmod($temp_file, 0644);

        // Atomic rename
        if (@rename($temp_file, $file)) {
            return true;
        }

        // Cleanup on failure
        @unlink($temp_file);
        return false;
    }
}
