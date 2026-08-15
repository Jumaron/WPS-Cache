<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Assets;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;
use WPSCache\Contracts\Purgeable;
use WPSCache\Support\AbstractFilesystemModule;
use WPSCache\Optimization\OptimizationMode;

/** Opt-in aggregation for simple local enqueued assets; complex/inline handles stay untouched. */
final class AssetCombiner extends AbstractFilesystemModule implements Module, Purgeable
{
    private string $directory;
    private bool $booted = false;

    public function __construct(Settings $settings)
    {
        parent::__construct($settings);
        $this->directory = WPSC_CACHE_DIR . 'combined/';
    }

    public function id(): string
    {
        return 'combined-assets';
    }

    public function boot(): void
    {
        if ($this->booted || is_admin() || !OptimizationMode::allows($this->settings)) {
            return;
        }
        if (!empty($this->settings['css_combine'])) {
            add_action('wp_enqueue_scripts', [$this, 'styles'], 999);
        }
        if (!empty($this->settings['js_combine'])) {
            add_action('wp_enqueue_scripts', [$this, 'scripts'], 999);
        }
        $this->booted = true;
    }

    public function styles(): void
    {
        global $wp_styles;
        if (!is_object($wp_styles)) {
            return;
        }
        $this->combine($wp_styles, 'css');
    }

    public function scripts(): void
    {
        global $wp_scripts;
        if (!is_object($wp_scripts)) {
            return;
        }
        $this->combine($wp_scripts, 'js');
    }

    public function purge(): void
    {
        $this->recursiveDelete($this->directory);
    }

    private function combine(object $registry, string $type): void
    {
        $handles = [];
        $files = [];
        foreach ((array) ($registry->queue ?? []) as $handle) {
            $item = $registry->registered[$handle] ?? null;
            $source = is_object($item) ? (string) ($item->src ?? '') : '';
            $extra = is_object($item) ? (array) ($item->extra ?? []) : [];
            if ($source === '' || array_intersect(array_keys($extra), ['before', 'after', 'data', 'conditional']) !== []) {
                continue;
            }
            $path = $this->localPath($source);
            if ($path === null || !is_file($path) || filesize($path) > 2 * 1024 * 1024) {
                continue;
            }
            $handles[] = (string) $handle;
            $files[] = $path;
        }
        if (count($files) < 2) {
            return;
        }
        $signature = [];
        $content = '';
        foreach ($files as $file) {
            $signature[] = $file . ':' . filemtime($file) . ':' . filesize($file);
            $chunk = file_get_contents($file);
            if (!is_string($chunk)) {
                return;
            }
            $content .= $type === 'js' ? ";\n" . $chunk : "\n" . $chunk;
        }
        $name = hash('sha256', implode('|', $signature)) . '.' . $type;
        $path = $this->directory . $name;
        if (!is_file($path) && !$this->atomicWrite($path, $content)) {
            return;
        }
        foreach ($handles as $handle) {
            $type === 'css' ? wp_dequeue_style($handle) : wp_dequeue_script($handle);
        }
        $url = content_url('/cache/wps-cache/combined/' . $name);
        if ($type === 'css') {
            wp_enqueue_style('wpsc-combined-' . substr($name, 0, 12), $url, [], null);
        } else {
            wp_enqueue_script('wpsc-combined-' . substr($name, 0, 12), $url, [], null, true);
        }
    }

    private function localPath(string $url): ?string
    {
        if (str_starts_with($url, '//')) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        } elseif (str_starts_with($url, '/')) {
            $url = home_url($url);
        }
        $site = rtrim(site_url(), '/');
        if (!str_starts_with($url, $site . '/')) {
            return null;
        }
        $relative = rawurldecode((string) parse_url(substr($url, strlen($site)), PHP_URL_PATH));
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        return rtrim(ABSPATH, '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
