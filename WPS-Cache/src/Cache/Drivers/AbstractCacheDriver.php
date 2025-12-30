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
     * Ensure cache directory exists and is writable using WP_Filesystem.
     */
    protected function ensureCacheDirectory(string $dir): bool
    {
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

    /**
     * Writes content to a file atomically to prevent race conditions.
     * Writes to a temp file first, then renames.
     */
    protected function atomicWrite(string $file, string $content): bool
    {
        $dir = dirname($file);
        $temp_file = $dir . '/' . uniqid('wpsc_tmp_', true) . '.tmp';

        if (@file_put_contents($temp_file, $content) === false) {
            return false;
        }

        // Set permissions before rename
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
