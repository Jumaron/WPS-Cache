<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\WordPress;

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

    public function installObjectCache(): bool
    {
        return $this->install('object-cache.php', 'object-cache.php', true);
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

        if (!is_file($source)) {
            return false;
        }

        if (is_file($target) && filesize($target) > 0) {
            if (!$replaceOwned || !$this->owns($destination)) {
                return false;
            }
        }

        $temporary = tempnam($this->contentDirectory, 'wpsc_dropin_');
        if ($temporary === false || !copy($source, $temporary)) {
            return false;
        }

        @chmod($temporary, 0644);
        if (!@rename($temporary, $target)) {
            @unlink($target);
            if (!@rename($temporary, $target)) {
                @unlink($temporary);
                return false;
            }
        }

        return true;
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
