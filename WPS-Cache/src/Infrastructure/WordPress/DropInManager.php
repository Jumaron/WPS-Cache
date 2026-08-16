<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\WordPress;

use WPSCache\Infrastructure\Filesystem\AtomicFileWriter;

final class DropInManager
{
    private const SIGNATURES = ['WPS-Cache', 'WPS Cache'];

    public function __construct(
        private readonly string $templatesDirectory,
        private readonly string $contentDirectory,
    ) {
    }

    public function installAdvancedCache(): bool
    {
        return $this->install('advanced-cache-template.php', 'advanced-cache.php', true);
    }

    public function advancedCacheInstallationIssue(): ?string
    {
        $source = $this->templatesDirectory . '/advanced-cache-template.php';
        $target = $this->contentDirectory . '/advanced-cache.php';
        if (!is_file($source) || !is_readable($source) || (int) @filesize($source) < 16) {
            return 'The bundled page-cache drop-in is missing or unreadable.';
        }
        if (!is_dir($this->contentDirectory) || !is_writable($this->contentDirectory)) {
            return 'Page caching was not enabled because the WordPress content directory is not writable.';
        }
        if (is_file($target) && !$this->owns('advanced-cache.php')) {
            return 'Page caching was not enabled because another plugin owns advanced-cache.php.';
        }
        if (is_file($target) && !is_writable($target)) {
            return 'Page caching was not enabled because advanced-cache.php is not writable.';
        }
        return null;
    }

    public function installObjectCache(string $backend = 'redis'): bool
    {
        if (!in_array($backend, ['redis', 'memcached'], true)) {
            return false;
        }
        $template = $backend === 'memcached' ? 'object-cache-memcached.php' : 'object-cache.php';
        return $this->install($template, 'object-cache.php', true);
    }

    public function objectCacheInstallationIssue(string $backend): ?string
    {
        if (!in_array($backend, ['redis', 'memcached'], true)) {
            return 'The requested object-cache backend is invalid.';
        }
        $template = $backend === 'memcached' ? 'object-cache-memcached.php' : 'object-cache.php';
        $source = $this->templatesDirectory . '/' . $template;
        $target = $this->contentDirectory . '/object-cache.php';
        if (!is_file($source) || !is_readable($source) || (int) @filesize($source) < 16) {
            return 'The bundled ' . ucfirst($backend) . ' drop-in is missing or unreadable.';
        }
        if (!is_dir($this->contentDirectory) || !is_writable($this->contentDirectory)) {
            return 'The WordPress content directory is not writable.';
        }
        if (is_file($target) && !$this->owns('object-cache.php')) {
            return 'Another plugin owns object-cache.php. It will not be replaced.';
        }
        if (is_file($target) && !is_writable($target)) {
            return 'The existing object-cache.php file is not writable.';
        }
        return null;
    }

    public function installedObjectCacheBackend(): ?string
    {
        if (!$this->owns('object-cache.php')) {
            return null;
        }
        $content = file_get_contents($this->contentDirectory . '/object-cache.php');
        if (!is_string($content)) {
            return null;
        }
        return stripos($content, 'memcached') !== false ? 'memcached' : 'redis';
    }

    public function removeAdvancedCache(): bool
    {
        return $this->removeOwned('advanced-cache.php');
    }

    public function removeObjectCache(): bool
    {
        return $this->removeOwned('object-cache.php');
    }

    public function removeAllOwned(): void
    {
        $this->removeAdvancedCache();
        $this->removeObjectCache();
    }

    public function owns(string $filename): bool
    {
        $file = $this->contentDirectory . '/' . ltrim($filename, '/\\');
        if (!is_file($file)) {
            return false;
        }

        $content = file_get_contents($file);
        if (!is_string($content)) {
            return false;
        }

        foreach (self::SIGNATURES as $signature) {
            if (str_contains($content, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function install(string $template, string $destination, bool $replaceOwned): bool
    {
        $source = $this->templatesDirectory . '/' . $template;
        $target = $this->contentDirectory . '/' . $destination;

        if (!is_file($source) || !is_readable($source) || !is_dir($this->contentDirectory) || !is_writable($this->contentDirectory)) {
            return false;
        }

        if (is_file($target) && filesize($target) > 0) {
            if (!$replaceOwned || !$this->owns($destination)) {
                return false;
            }
        }

        $content = file_get_contents($source);
        if (!is_string($content) || !str_starts_with(ltrim($content), '<?php')) {
            return false;
        }
        return AtomicFileWriter::replace($target, $content, 0644);
    }

    private function removeOwned(string $filename): bool
    {
        $file = $this->contentDirectory . '/' . $filename;
        if (!is_file($file)) {
            return true;
        }

        return $this->owns($filename) && @unlink($file);
    }
}
