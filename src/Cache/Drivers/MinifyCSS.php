<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use WPSCache\Cache\Abstracts\AbstractCacheDriver;

final class MinifyCSS extends AbstractCacheDriver {
    protected function getFileExtension(): string {
        return '.css';
    }

    protected function doInitialize(): void {
        if (!is_admin() && ($this->settings['css_minify'] ?? false)) {
            add_action('wp_enqueue_scripts', [$this, 'processStyles'], 100);
        }
    }

    public function processStyles(): void {
        global $wp_styles;

        if (empty($wp_styles->queue)) {
            return;
        }

        $excluded_css = $this->settings['excluded_css'] ?? [];

        foreach ($wp_styles->queue as $handle) {
            if (!isset($wp_styles->registered[$handle])) {
                continue;
            }

            $style = $wp_styles->registered[$handle];
            
            if ($this->shouldSkipStyle($style, $excluded_css)) {
                continue;
            }

            $source_path = $this->getSourcePath($style->src);
            if (!$source_path || !is_readable($source_path)) {
                continue;
            }

            $content = file_get_contents($source_path);
            if (!$content) {
                continue;
            }

            $cache_key = $this->generateCacheKey($handle, $content, $source_path);
            $cache_file = $this->getCacheFile($cache_key);

            if (!file_exists($cache_file)) {
                $minified = $this->minifyCSS($content);
                file_put_contents($cache_file, $minified);
            }

            $this->updateStyleSource($style, $cache_file);
        }
    }

    private function shouldSkipStyle($style, array $excluded_css): bool {
        return empty($style->src) ||
               strpos($style->src, '.min.css') !== false ||
               strpos($style->src, '//') === 0 ||
               strpos($style->src, site_url()) === false ||
               in_array($style->handle, $excluded_css) ||
               $this->isExcludedUrl($style->src);
    }

    private function getSourcePath(string $src): ?string {
        if (strpos($src, 'http') !== 0) {
            $src = site_url($src);
        }

        return str_replace(
            [site_url(), 'wp-content'],
            [ABSPATH, 'wp-content'],
            $src
        );
    }

    private function generateCacheKey(string $handle, string $content, string $source_path): string {
        return md5($handle . $content . filemtime($source_path));
    }

    private function updateStyleSource($style, string $cache_file): void {
        $wp_styles->registered[$style->handle]->src = str_replace(
            ABSPATH,
            site_url('/'),
            $cache_file
        );
        $wp_styles->registered[$style->handle]->ver = filemtime($cache_file);
    }

    private function minifyCSS(string $css): string {
        // Preserve data URIs
        $data_uris = [];
        $css = preg_replace_callback(
            '/(url\(\s*[\'"]?)(data:[^;]+;base64,[^\'"]+)([\'"]?\s*\))/i',
            function ($matches) use (&$data_uris) {
                $key = "WPSC_DATA_URI_" . count($data_uris);
                $data_uris[$key] = $matches[0];
                return $key;
            },
            $css
        );

        // Remove comments except important ones
        $css = preg_replace('/\/\*(?!!)[^*]*\*+([^\/][^*]*\*+)*\//', '', $css);
        
        // Process calc expressions
        $css = preg_replace_callback(
            '/calc\(([^)]+)\)/',
            function ($matches) {
                return 'calc(' . preg_replace('/\s*([+\-*\/])\s*/', ' $1 ', $matches[1]) . ')';
            },
            $css
        );

        // Minify
        $css = preg_replace([
            '/\s+/',
            '/\s*([:;{},])\s*/',
            '/;}/',
            '/(\d+)\.0+(%|em|ex|px|in|cm|mm|pt|pc)/i',
            '/(^|[^0-9])0(%|em|ex|px|in|cm|mm|pt|pc)/i'
        ], [
            ' ',
            '$1',
            '}',
            '$1$2',
            '${1}0'
        ], trim($css));

        // Restore data URIs
        foreach ($data_uris as $key => $uri) {
            $css = str_replace($key, $uri, $css);
        }

        return $css;
    }

    public function get(string $key): mixed {
        $file = $this->getCacheFile($key);
        return file_exists($file) ? file_get_contents($file) : null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {
        file_put_contents($this->getCacheFile($key), $value);
    }

    public function delete(string $key): void {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function clear(): void {
        array_map('unlink', glob($this->cache_dir . '*' . $this->getFileExtension()) ?: []);
    }
}