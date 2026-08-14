<?php

declare(strict_types=1);

namespace WPSCache\Support;

use Throwable;
use WPSCache\Config\Settings;

abstract class AbstractFilesystemModule
{
    /** @var array<string, mixed> */
    protected array $settings;

    public function __construct(Settings|array $settings)
    {
        $this->settings = $settings instanceof Settings
            ? $settings->all()
            : $settings;
    }

    protected function generateCacheKey(string $input): string
    {
        return hash('sha256', $input);
    }

    protected function logError(string $message, ?Throwable $exception = null): void
    {
        $context = $exception === null ? '' : ' [Exception: ' . $exception->getMessage() . ']';
        error_log(sprintf('[WPS-Cache] %s: %s%s', static::class, $message, $context));
    }

    protected function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->logError('Failed to create directory: ' . $directory);
            return false;
        }

        @file_put_contents(rtrim($directory, '/\\') . '/index.php', '<?php // Silence is golden');

        return true;
    }

    protected function atomicWrite(string $file, string $content): bool
    {
        $directory = dirname($file);
        if (!$this->ensureDirectory($directory)) {
            return false;
        }

        $temporary = tempnam($directory, 'wpsc_tmp_');
        if ($temporary === false) {
            $this->logError('Failed to create a temporary file in ' . $directory);
            return false;
        }

        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            @unlink($temporary);
            return false;
        }

        @chmod($temporary, 0644);
        if (!@rename($temporary, $file)) {
            @unlink($file);
            if (!@rename($temporary, $file)) {
                @unlink($temporary);
                return false;
            }
        }

        return true;
    }

    protected function recursiveDelete(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }
}
