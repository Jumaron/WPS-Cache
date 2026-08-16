<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\Filesystem;

/** Writes complete files with verification and restoration of the previous copy. */
final class AtomicFileWriter
{
    public static function replace(string $file, string $content, ?int $mode = null): bool
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }
        if (!is_writable($directory) || (is_file($file) && !is_writable($file))) {
            return false;
        }

        $temporary = tempnam($directory, 'wpsc_write_');
        if ($temporary === false) {
            return false;
        }
        $written = file_put_contents($temporary, $content, LOCK_EX);
        if ($written !== strlen($content) || !hash_equals(hash('sha256', $content), hash_file('sha256', $temporary) ?: '')) {
            @unlink($temporary);
            return false;
        }

        $targetMode = $mode;
        if ($targetMode === null && is_file($file)) {
            $permissions = @fileperms($file);
            $targetMode = is_int($permissions) ? $permissions & 0777 : null;
        }
        @chmod($temporary, $targetMode ?? 0644);

        $backup = null;
        if (is_file($file)) {
            $backup = tempnam($directory, 'wpsc_restore_');
            if ($backup === false || !copy($file, $backup)) {
                @unlink($temporary);
                @unlink(is_string($backup) ? $backup : '');
                return false;
            }
        }

        if (!@rename($temporary, $file)) {
            if (!is_file($file) || !@unlink($file) || !@rename($temporary, $file)) {
                self::restore($file, $backup);
                @unlink($temporary);
                @unlink(is_string($backup) ? $backup : '');
                return false;
            }
        }

        $valid = hash_equals(hash('sha256', $content), hash_file('sha256', $file) ?: '');
        if (!$valid) {
            @unlink($file);
            self::restore($file, $backup);
        }
        @unlink(is_string($backup) ? $backup : '');

        if ($valid && function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        return $valid;
    }

    private static function restore(string $file, ?string $backup): void
    {
        if (is_string($backup) && is_file($backup)) {
            @copy($backup, $file);
        }
    }
}
