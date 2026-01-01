<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use Throwable;

/**
 * Base driver providing file system utilities and error handling.
 */
abstract class AbstractCacheDriver implements CacheDriverInterface
{
    protected bool $initialized = false;
    protected array $settings = [];

    public function __construct()
    {
        // Load settings once per driver instance
        $this->settings = get_option('wpsc_settings', []);
    }

    /**
     * Default support check. Overridden by drivers needing specific extensions.
     */
    public function isSupported(): bool
    {
        return true;
    }

    protected function generateCacheKey(string $input): string
    {
        return md5($input); // Or your preferred hashing logic
    }

    /**
     * Standardized error logging.
     */
    protected function logError(string $message, ?Throwable $e = null): void
    {
        $context = $e ? " [Exception: {$e->getMessage()}]" : '';
        $log = sprintf('[WPS-Cache] %s: %s%s', static::class, $message, $context);

        error_log($log);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            trigger_error($log, E_USER_WARNING);
        }
    }

    /**
     * Recursively creates a directory if it doesn't exist.
     * Uses native PHP mkdir for performance.
     */
    protected function ensureDirectory(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->logError("Failed to create directory: $dir");
            return false;
        }

        // Add index.php silence file
        @file_put_contents($dir . '/index.php', '<?php // Silence is golden');

        return true;
    }

    /**
     * Writes content to a file atomically.
     * Prevents race conditions using tempnam + rename in the SAME directory.
     */
    protected function atomicWrite(string $filepath, string $content): bool
    {
        $dir = dirname($filepath);

        if (!$this->ensureDirectory($dir)) {
            return false;
        }

        // Create temp file in the SAME directory to ensure atomic rename (same partition)
        $temp_file = tempnam($dir, 'wpsc_tmp_');

        if ($temp_file === false) {
            $this->logError("Failed to create temp file in $dir");
            return false;
        }

        if (file_put_contents($temp_file, $content) === false) {
            $this->logError("Failed to write content to $temp_file");
            @unlink($temp_file);
            return false;
        }

        @chmod($temp_file, 0644);

        if (!@rename($temp_file, $filepath)) {
            // Fallback for Windows
            @unlink($filepath);
            if (!@rename($temp_file, $filepath)) {
                $this->logError("Failed to rename $temp_file to $filepath");
                @unlink($temp_file);
                return false;
            }
        }

        return true;
    }

    /**
     * Recursive directory deletion using Iterators.
     */
    protected function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir))
            return;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
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

    // Abstract method stubs for strict typing
    abstract public function initialize(): void;
    abstract public function get(string $key): mixed;
    abstract public function set(string $key, mixed $value, int $ttl = 3600): void;
    abstract public function delete(string $key): void;
    abstract public function clear(): void;
}
