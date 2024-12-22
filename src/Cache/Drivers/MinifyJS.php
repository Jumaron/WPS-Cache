<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

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

        $excluded_js = $this->settings['excluded_js'] ?? [];
    
        foreach ($wp_scripts->queue as $handle) {
            if (!isset($wp_scripts->registered[$handle])) {
                continue;
            }
    
            $script = $wp_scripts->registered[$handle];
            
            // Skip if any exclusion conditions are met
            if (empty($script->src) || 
                strpos($script->src, '.min.js') !== false ||
                strpos($script->src, '//') === 0 ||
                strpos($script->src, site_url()) === false ||
                in_array($handle, $excluded_js) ||
                $this->isExcluded($script->src, $excluded_js)) {
                continue;
            }

            // Convert URL to file path
            $source = str_replace(
                [site_url(), 'wp-content'],
                [ABSPATH, 'wp-content'],
                $script->src
            );
    
            // Skip if file issues
            if (!file_exists($source) || 
                !is_readable($source) || 
                filesize($source) > 500000) { // 500KB limit
                continue;
            }

            $content = @file_get_contents($source);
            if ($content === false || empty(trim($content))) {
                continue;
            }

            try {
                $cache_key = md5($handle . $content . filemtime($source));
                $cache_file = $this->getCacheFile($cache_key);

                if (!file_exists($cache_file)) {
                    $minified = $this->minifyJS($content);
                    if ($minified === false) {
                        continue;
                    }

                    if (@file_put_contents($cache_file, $minified) === false) {
                        continue;
                    }
                }

                // Update script source
                $wp_scripts->registered[$handle]->src = str_replace(
                    ABSPATH,
                    site_url('/'),
                    $cache_file
                );
                $wp_scripts->registered[$handle]->ver = filemtime($cache_file);

            } catch (\Exception $e) {
                error_log("WPS Cache JS Error: " . $e->getMessage() . " in file: {$source}");
                continue;
            }
        }
    }

    private function minifyJS(string $js): string|false {
        if (empty($js)) {
            return false;
        }

        try {
            // Skip tiny or already minified files
            if (strlen($js) < 500 || str_contains($js, '.min.js')) {
                return $js;
            }

            // Step 1: Add newlines after specific tokens to prevent regex issues
            $js = str_replace(['){', ']{', '}else{'], [")\n{", "]\n{", "}\nelse\n{"], $js);

            // Step 2: Preserve important comment blocks
            $preserveComments = [];
            $js = preg_replace_callback('/\/\*![\s\S]*?\*\//', function($match) use (&$preserveComments) {
                $placeholder = '/*PC' . count($preserveComments) . '*/';
                $preserveComments[$placeholder] = $match[0];
                return $placeholder;
            }, $js);

            // Step 3: Remove comments safely
            $js = preg_replace([
                '#^\s*//[^\n]*$#m',     // Single line comments
                '#^\s*/\*[^*]*\*+([^/*][^*]*\*+)*/\s*#m' // Multi-line comments
            ], '', $js);
            
            if ($js === null) {
                return $js; // Return original if comment removal fails
            }

            // Step 4: Safely remove whitespace
            $js = preg_replace([
                '#^\s+#m',               // Leading whitespace
                '#\s+$#m',               // Trailing whitespace
                '#[\r\n]+#',             // Multiple newlines to single
                '#[\t ]+#'               // Multiple spaces/tabs to single space
            ], ['', '', "\n", ' '], $js);

            if ($js === null) {
                return $js; // Return original if whitespace removal fails
            }

            // Step 5: Restore preserved comments
            foreach ($preserveComments as $placeholder => $original) {
                $js = str_replace($placeholder, $original, $js);
            }

            // Final cleanup
            $js = trim($js);

            if (empty($js)) {
                return false;
            }

            return $js;

        } catch (\Exception $e) {
            error_log('WPS Cache JS Minification Error: ' . $e->getMessage());
            return false;
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

    public function isConnected(): bool {
        return is_writable($this->cache_dir);
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