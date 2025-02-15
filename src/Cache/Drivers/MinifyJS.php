<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Enhanced JavaScript minification implementation
 */
final class MinifyJS extends AbstractCacheDriver {
    private const MAX_FILE_SIZE = 500000; // 500KB
    private const MIN_FILE_SIZE = 500;    // 500B

    private const PRESERVE_PATTERNS = [
        'template_literals' => '/`(?:\\\\.|[^\\\\`])*`/',  // Template literals with expressions
        'regex_patterns'    => '/\/(?:\\\\.|[^\\/])*\/[gimuy]*(?=\s*(?:[)\].,;:\s]|$))/', // Regex patterns
        'strings'           => '/([\'"])((?:\\\\.|(?!\1)[^\\\\])*)\1/', // String literals
        'comments'          => '/\/\*![\s\S]*?\*\//',  // Important comments
    ];

    private string $cache_dir;
    private array $settings;
    private array $preserved = [];

    public function __construct() {
        $this->cache_dir = WPSC_CACHE_DIR . 'js/';
        $this->settings = get_option('wpsc_settings', []);
        $this->ensureCacheDirectory($this->cache_dir);
    }

    public function initialize(): void {
        if (!$this->initialized && !is_admin() && ($this->settings['js_minify'] ?? false)) {
            add_action('wp_enqueue_scripts', [$this, 'processScripts'], 100);
            $this->initialized = true;
        }
    }

    public function isConnected(): bool {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        return $wp_filesystem->is_writable($this->cache_dir);
    }

    public function get(string $key): mixed {
        $file = $this->getCacheFile($key);
        return is_readable($file) ? file_get_contents($file) : null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {
        if (!is_string($value) || empty(trim($value))) {
            return;
        }

        $file = $this->getCacheFile($key);
        if (@file_put_contents($file, $value) === false) {
            $this->logError("Failed to write JS cache file: $file");
        }
    }

    public function delete(string $key): void {
        $file = $this->getCacheFile($key);
        if (file_exists($file) && !wp_delete_file($file)) {
            $this->logError("Failed to delete JS cache file: $file");
        }
    }

    public function clear(): void {
        $files = glob($this->cache_dir . '*.js');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file) && !wp_delete_file($file)) {
                $this->logError("Failed to delete JS file during clear: $file");
            }
        }
    }

    private function minifyJS(string $js): string|false {
        if (empty($js)) {
            return false;
        }

        try {
            // Reset preserved content
            $this->preserved = [];

            // Preserve special content
            $js = $this->preserveContent($js);

            // Remove comments (except preserved ones)
            $js = preg_replace([
                '#^\s*//[^\n]*$#m',     // Single line comments
                '#^\s*/\*[^!][^*]*\*+([^/*][^*]*\*+)*/\s*#m' // Multi-line comments (non-important)
            ], '', $js);

            // Safe whitespace removal
            $js = preg_replace(
                [
                    '/\s+/',                            // Multiple whitespace to single
                    '/\s*([\[\]{}(:,=+\-*\/])\s*/',      // Space around specific operators
                    '/\s*;+\s*([\]}])\s*/',              // Remove unnecessary semicolons
                    '/;\s*;/',                         // Multiple semicolons to single
                    '/\)\s*{/',                        // Space between ) and {
                    '/}\s*(\w+)/',                     // Add newline after } followed by word
                    '/}\s*(else|catch|finally)/',      // No space before else/catch/finally
                    '/[\r\n\t]+/',                     // Remove newlines/tabs
                ],
                [
                    ' ',       // Single space
                    '$1',      // Just the operator
                    '$1',      // Just the bracket
                    ';',       // Single semicolon
                    '){',      // No space
                    "}\n$1",   // Newline between
                    "}$1",     // No space
                    '',        // Remove completely
                ],
                $js
            );

            // Add missing semicolons only where needed
            $js = preg_replace(
                '/(\}|\++|\-+|\*+|\/+|%+|=+|\w+|\)+|\'+|\"+|`+)\s*\n\s*(?=[\w({\'"[`])/i',
                '$1;',
                $js
            );

            // Restore preserved content
            $js = strtr($js, $this->preserved);

            // Final cleanup
            $js = trim($js);

            return empty($js) ? false : $js;

        } catch (\Throwable $e) {
            $this->logError("JS minification failed", $e);
            return false;
        }
    }

    private function preserveContent(string $js): string {
        if (empty($js)) {
            return '';
        }
        
        foreach (self::PRESERVE_PATTERNS as $type => $pattern) {
            $js = (string)preg_replace_callback($pattern,
                function($matches) use ($type) {
                    if (!isset($matches[0])) {
                        return '';
                    }
                    $key = '___' . strtoupper($type) . '_' . count($this->preserved) . '___';
                    $this->preserved[$key] = $matches[0];
                    return $key;
                },
                $js
            ) ?? $js;
        }

        return $js;
    }

    private function processScript(string $handle, \WP_Scripts $wp_scripts, array $excluded_js): void {
        if (!isset($wp_scripts->registered[$handle])) {
            return;
        }

        $script = $wp_scripts->registered[$handle];
        
        // Skip if script should not be processed
        if (!$this->shouldProcessScript($script, $handle, $excluded_js)) {
            return;
        }

        // Get the source file path
        $source = $this->getSourcePath($script);
        if (!$this->isValidSource($source)) {
            return;
        }

        // Read and process content
        $content = @file_get_contents($source);
        if ($content === false || empty(trim($content))) {
            return;
        }

        // Generate cache key and path
        $cache_key = $this->generateCacheKey($handle . $content . filemtime($source));
        $cache_file = $this->getCacheFile($cache_key);

        // Process and cache if needed
        if (!file_exists($cache_file)) {
            $minified = $this->minifyJS($content);
            if ($minified !== false) {
                $this->set($cache_key, $minified);
            }
        }

        // Update WordPress script registration
        if (file_exists($cache_file)) {
            $this->updateScriptRegistration($script, $cache_file);
        }
    }

    public function processScripts(): void {
        global $wp_scripts;
        
        if (empty($wp_scripts->queue)) {
            return;
        }

        $excluded_js = $this->settings['excluded_js'] ?? [];

        foreach ($wp_scripts->queue as $handle) {
            try {
                $this->processScript($handle, $wp_scripts, $excluded_js);
            } catch (\Throwable $e) {
                $this->logError("Failed to process script $handle", $e);
            }
        }
    }

    private function shouldProcessScript($script, string $handle, array $excluded_js): bool {
        if (!isset($script->src) || empty($script->src)) {
            return false;
        }

        $src = $script->src;
        return strpos($src, '.min.js') === false
            && strpos($src, '//') !== 0
            && strpos($src, site_url()) !== false
            && !in_array($handle, $excluded_js)
            && !$this->isExcluded($src, $excluded_js);
    }

    private function getSourcePath($script): ?string {
        if (!isset($script->src)) {
            return null;
        }

        $src = $script->src;
        
        // Convert relative URL to absolute
        if (strpos($src, 'http') !== 0) {
            $src = site_url($src);
        }

        // Convert URL to file path
        return str_replace(
            [site_url(), 'wp-content'],
            [ABSPATH, 'wp-content'],
            $src
        );
    }

    private function isValidSource(?string $source): bool {
        return $source 
            && is_readable($source) 
            && filesize($source) <= self::MAX_FILE_SIZE;
    }

    private function updateScriptRegistration($script, string $cache_file): void {
        if (!isset($script->src)) {
            return;
        }

        $script->src = str_replace(
            ABSPATH,
            site_url('/'),
            $cache_file
        );
        $script->ver = filemtime($cache_file);
    }

    private function getCacheFile(string $key): string {
        return $this->cache_dir . $key . '.js';
    }

    private function isExcluded(string $url, array $excluded_patterns): bool {
        foreach ($excluded_patterns as $pattern) {
            if (fnmatch($pattern, $url)) {
                return true;
            }
        }
        return false;
    }
}