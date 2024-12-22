<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * JavaScript minification implementation
 */
final class MinifyJS implements CacheDriverInterface {
    private string $cache_dir;
    private array $settings;

    public function __construct() {
        $this->cache_dir = WPSC_CACHE_DIR . 'js/';
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
        if (!is_admin() && ($this->settings['js_minify'] ?? false)) {
            add_action('wp_enqueue_scripts', [$this, 'processScripts'], 100);
        }
    }

    public function processScripts(): void {
        global $wp_scripts;

        if (empty($wp_scripts->queue)) {
            return;
        }

        // Get excluded JS handles/URLs from settings
        $excluded_js = $this->settings['excluded_js'] ?? [];

        foreach ($wp_scripts->queue as $handle) {
            if (!isset($wp_scripts->registered[$handle])) {
                continue;
            }

            $script = $wp_scripts->registered[$handle];

            // Skip if no source, already minified, external, or in excluded list
            if (empty($script->src) ||
                strpos($script->src, '.min.js') !== false ||
                strpos($script->src, '//') === 0 ||
                strpos($script->src, site_url()) === false ||
                in_array($handle, $excluded_js) ||
                $this->isExcluded($script->src, $excluded_js)) {
                continue;
            }

            // Get absolute URL if it's a relative path
            if (strpos($script->src, 'http') !== 0) {
                $script->src = site_url($script->src);
            }

            // Convert URL to file path
            $source = str_replace(
                [site_url(), 'wp-content'],
                [ABSPATH, 'wp-content'],
                $script->src
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
                $minified_content = $this->minifyJS($content);

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

            $wp_scripts->registered[$handle]->src = $minified_url;

            // Add version to break cache
            $wp_scripts->registered[$handle]->ver = filemtime($cache_file);
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

    private function minifyJS(string $js): string {
        // Preserve strings
        $strings = [];
        $js = preg_replace_callback(
            '/(\'[^\']*\'|"[^"]*")/',
            function ($matches) use (&$strings) {
                $key = "WPSC_STRING_" . count($strings);
                $strings[$key] = $matches[0];
                return $key;
            },
            $js
        );

        // Remove single-line comments
        $js = preg_replace('/\/\/[^\n]*/', '', $js);

        // Remove multi-line comments
        $js = preg_replace('/\/\*[\s\S]*?\*\//', '', $js);

        // Remove whitespace
        $js = preg_replace('/\s+/', ' ', $js);
        
        // Remove spaces around operators
        $js = preg_replace('/\s*([\(\){}\[\]=<>:?!,;&|+\-*\/])\s*/', '$1', $js);
        
        // Special handling for minus operator
        $js = preg_replace('/-\s+/', '-', $js);
        $js = preg_replace('/\s+-/', '-', $js);

        // Restore preserved strings
        foreach ($strings as $key => $string) {
            $js = str_replace($key, $string, $js);
        }

        // Additional safety replacements
        $js = str_replace([';}'], '}', $js); // Remove unnecessary semicolons
        $js = preg_replace('/([{;}])\s+/', '$1', $js); // Remove spaces after specific characters
        $js = preg_replace('/\s+({)/', '$1', $js); // Remove spaces before curly braces
        
        return trim($js);
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
        return $this->cache_dir . $key . '.js';
    }
}