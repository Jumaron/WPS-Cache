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
        'comments' => '/\/\*![\s\S]*?\*\//',           // Important comments
        'strings' => '/([\'"`])((?:\\\\.|[^\\\\])*?)\1/', // String literals
        'regex' => '/(\/.+?\/)[gimy]{0,4}/',           // Regular expressions
        'templates' => '/`(?:\\\\.|[^\\\\`])*`/'       // Template literals
    ];

    private const MINIFY_PATTERNS = [
        'comments' => [
            '#^\s*//[^\n]*$#m',                        // Single line comments
            '#^\s*/\*[^!][^*]*\*+([^/*][^*]*\*+)*/\s*#m' // Multi-line comments (non-important)
        ],
        'whitespace' => [
            '#^\s+#m' => '',                           // Leading whitespace
            '#\s+$#m' => '',                           // Trailing whitespace
            '#[\r\n]+#' => "\n",                       // Multiple newlines
            '#[\t ]+#' => ' '                          // Multiple spaces/tabs
        ],
        'syntax' => [
            '/\s*([:;{},=\(\)\[\]])\s*/' => '$1',     // Around operators
            '/;}/' => '}',                             // Extra semicolons
            '/([^;{}])}/m' => '$1;}'                   // Add missing semicolons
        ]
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
        return is_writable($this->cache_dir);
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
        if (file_exists($file) && !@unlink($file)) {
            $this->logError("Failed to delete JS cache file: $file");
        }
    }

    public function clear(): void {
        $files = glob($this->cache_dir . '*.js');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file) && !@unlink($file)) {
                $this->logError("Failed to delete JS file during clear: $file");
            }
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

    private function processScript(string $handle, \WP_Scripts $wp_scripts, array $excluded_js): void {
        if (!isset($wp_scripts->registered[$handle])) {
            return;
        }

        /** @var \WP_Dependencies|\WP_Dependency $script */
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
        $content = file_get_contents($source);
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
        $this->updateScriptRegistration($script, $cache_file);
    }

    private function shouldProcessScript($script, string $handle, array $excluded_js): bool {
        // First check if we have a src property
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
    
    private function isValidSource(?string $source): bool {
        return $source 
            && is_readable($source) 
            && filesize($source) <= self::MAX_FILE_SIZE;
    }

    private function minifyJS(string $js): string|false {
        if (empty($js) || strlen($js) < self::MIN_FILE_SIZE) {
            return $js;
        }

        try {
            // Reset preserved content
            $this->preserved = [];

            // Preserve special content
            $js = $this->preserveContent($js);

            // Apply minification
            $js = $this->applyMinification($js);

            // Restore preserved content
            $js = $this->restoreContent($js);

            // Verify result
            if (empty(trim($js))) {
                return false;
            }

            return $js;
        } catch (\Throwable $e) {
            $this->logError("JS minification failed", $e);
            return false;
        }
    }

    private function preserveContent(string $js): string {
        // Add safe line breaks for better regex processing
        $js = str_replace(
            ['){', ']{', '}else{'], 
            [")\n{", "]\n{", "}\nelse\n{"],
            $js
        );

        // Preserve special content
        foreach (self::PRESERVE_PATTERNS as $type => $pattern) {
            $js = preg_replace_callback($pattern,
                function($matches) use ($type) {
                    $key = '___' . strtoupper($type) . '_' . count($this->preserved) . '___';
                    $this->preserved[$key] = $matches[0];
                    return $key;
                },
                $js
            );
        }

        return $js;
    }

    private function applyMinification(string $js): string {
        // Remove comments
        foreach (self::MINIFY_PATTERNS['comments'] as $pattern) {
            $js = preg_replace($pattern, '', $js);
        }

        // Apply whitespace patterns
        foreach (self::MINIFY_PATTERNS['whitespace'] as $pattern => $replacement) {
            $js = preg_replace($pattern, $replacement, $js);
        }

        // Apply syntax optimization patterns
        foreach (self::MINIFY_PATTERNS['syntax'] as $pattern => $replacement) {
            $js = preg_replace($pattern, $replacement, $js);
        }

        return $js;
    }

    private function restoreContent(string $js): string {
        return strtr($js, $this->preserved);
    }

    private function isExcluded(string $url, array $excluded_patterns): bool {
        foreach ($excluded_patterns as $pattern) {
            if (fnmatch($pattern, $url)) {
                return true;
            }
        }
        return false;
    }

    private function getCacheFile(string $key): string {
        return $this->cache_dir . $key . '.js';
    }
}