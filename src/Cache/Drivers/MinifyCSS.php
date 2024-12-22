<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Improved CSS minification implementation
 */
final class MinifyCSS implements CacheDriverInterface {
    private string $cache_dir;
    private array $settings;

    public function __construct() {
        $this->cache_dir = WPSC_CACHE_DIR . 'css/';
        if (!file_exists($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }

        $this->settings = get_option('wpsc_settings', []);
    }

    public function isConnected(): bool {
        return is_writable($this->cache_dir);
    }
    
    public function delete(string $key): void {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function initialize(): void {
        if (!is_admin() && ($this->settings['css_minify'] ?? false)) {
            add_action('wp_enqueue_scripts', [$this, 'processStyles'], 100);
        }
    }

    public function processStyles(): void {
        global $wp_styles;

        if (empty($wp_styles->queue)) {
            return;
        }

        // Get excluded CSS handles/URLs from settings
        $excluded_css = $this->settings['excluded_css'] ?? [];

        foreach ($wp_styles->queue as $handle) {
            if (!isset($wp_styles->registered[$handle])) {
                continue;
            }

            $style = $wp_styles->registered[$handle];

            // Skip if no source, already minified, external, or in excluded list
            if (empty($style->src) ||
                strpos($style->src, '.min.css') !== false ||
                strpos($style->src, '//') === 0 ||
                strpos($style->src, site_url()) === false ||
                in_array($handle, $excluded_css) ||
                $this->isExcluded($style->src, $excluded_css)) {
                continue;
            }

            // Get absolute URL if it's a relative path
            if (strpos($style->src, 'http') !== 0) {
                $style->src = site_url($style->src);
            }

            // Convert URL to file path
            $source = str_replace(
                [site_url(), 'wp-content'],
                [ABSPATH, 'wp-content'],
                $style->src
            );

            // Skip if file doesn't exist
            if (!file_exists($source)) {
                continue;
            }

            $content = file_get_contents($source);
            if (!$content) {
                continue;
            }

            // Create cache key from the file content and last modified time
            $cache_key = md5($handle . $content . filemtime($source));
            $cache_file = $this->getCacheFile($cache_key);

            // Check if cached version exists and is valid
            if (!file_exists($cache_file)) {
                $minified_content = $this->minifyCSS($content);

                // Add source file info as comment
                $minified_content = sprintf(
                    "/* Minified by WPS Cache - Original: %s */\n%s",
                    basename($source),
                    $minified_content
                );

                file_put_contents($cache_file, $minified_content);
            }

            // Update source to use minified version
            $minified_url = str_replace(
                ABSPATH,
                site_url('/'),
                $cache_file
            );

            $wp_styles->registered[$handle]->src = $minified_url;

            // Add version to break cache
            $wp_styles->registered[$handle]->ver = filemtime($cache_file);
        }
    }

    private function isExcluded(string $url, array $excluded_patterns): bool {
        foreach ($excluded_patterns as $pattern) {
            if (fnmatch($pattern, $url)) {
                return true;
            }
        }
        return false;
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
    
        // Remove comments, but preserve important ones (/*! ... */)
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);
    
        // Trim whitespace
        $css = trim($css);
    
        // Normalize whitespace (collapse multiple spaces to one)
        $css = preg_replace('/\s+/', ' ', $css);
    
        // Remove spaces around specific characters, but keep spaces around operators in calc()
        $css = preg_replace_callback(
            '/calc\(([^)]+)\)/',
            function ($matches) {
                return 'calc(' . preg_replace('/\s*([+\-*\/])\s*/', ' $1 ', $matches[1]) . ')';
            },
            $css
        );
        $css = preg_replace('/\s*([:;{},>~])\s*/', '$1', $css);
    
        // Remove unnecessary semicolons (last one in a block)
        $css = preg_replace('/;(?=\s*})/', '', $css);
    
        // Remove unnecessary zeros (e.g., 0.5 -> .5, 0px -> 0)
        $css = preg_replace('/(^| )0\.([0-9]+)(%|em|ex|px|in|cm|mm|pt|pc)/i', '${1}.${2}${3}', $css);
        $css = preg_replace('/(^| ):0(%|em|ex|px|in|cm|mm|pt|pc)/i', '${1}:0', $css);
    
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

    public function clear(): void {
        $files = glob($this->cache_dir . '*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function getCacheFile(?string $key = null): string {
        $key ??= md5(uniqid());
        return $this->cache_dir . $key . '.css';
    }
}