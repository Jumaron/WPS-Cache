<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Enhanced CSS minification implementation
 */
final class MinifyCSS extends AbstractCacheDriver {
    private const PRESERVE_PATTERNS = [
        'data_uris' => '/(url\(\s*[\'"]?)(data:[^;]+;base64,[^\'"]+)([\'"]?\s*\))/i',
        'calc' => '/calc\(([^)]+)\)/',
        'comments' => '/\/\*![\s\S]*?\*\//',  // Important comments
        'strings' => '/([\'"])((?:\\\\.|[^\\\\])*?)\1/'
    ];

    private const MINIFY_PATTERNS = [
        'comments' => '/\/\*(?!!)[^*]*\*+([^\/][^*]*\*+)*\//',  // Remove non-important comments
        'whitespace' => [
            '/\s+/' => ' ',                    // Collapse multiple whitespace
            '/\s*([:;{},>~+])\s*/' => '$1',    // Remove space around operators
            '/;}/' => '}',                     // Remove last semicolon
        ],
        'numbers' => [
            '/(^|[^0-9])0\.([0-9]+)/' => '$1.$2',  // Leading zero in decimal
            '/([^0-9])0(%|em|ex|px|in|cm|mm|pt|pc|rem|vw|vh)/' => '${1}0',  // Zero units
        ]
    ];

    private string $cache_dir;
    private array $settings;
    private array $preserved = [];

    public function __construct() {
        $this->cache_dir = WPSC_CACHE_DIR . 'css/';
        $this->settings = get_option('wpsc_settings', []);
        $this->ensureCacheDirectory($this->cache_dir);
    }

    public function initialize(): void {
        if (!$this->initialized && !is_admin() && ($this->settings['css_minify'] ?? false)) {
            add_action('wp_enqueue_scripts', [$this, 'processStyles'], 100);
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
            $this->logError("Failed to write CSS cache file: $file");
        }
    }

    public function delete(string $key): void {
        $file = $this->getCacheFile($key);
        if (file_exists($file) && !@unlink($file)) {
            $this->logError("Failed to delete CSS cache file: $file");
        }
    }

    public function clear(): void {
        $files = glob($this->cache_dir . '*.css');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file) && !@unlink($file)) {
                $this->logError("Failed to delete CSS file during clear: $file");
            }
        }
    }

    public function processStyles(): void {
        global $wp_styles;
        
        if (empty($wp_styles->queue)) {
            return;
        }

        $excluded_css = $this->settings['excluded_css'] ?? [];

        foreach ($wp_styles->queue as $handle) {
            try {
                $this->processStyle($handle, $wp_styles, $excluded_css);
            } catch (\Throwable $e) {
                $this->logError("Failed to process style $handle", $e);
            }
        }
    }

    private function processStyle(string $handle, \WP_Styles $wp_styles, array $excluded_css): void {
        if (!isset($wp_styles->registered[$handle])) {
            return;
        }

        /** @var \WP_Dependencies|\WP_Dependency $style */
        $style = $wp_styles->registered[$handle];
        
        // Skip if script should not be processed
        if (!$this->shouldProcessStyle($style, $handle, $excluded_css)) {
            return;
        }

        // Get the source file path
        $source = $this->getSourcePath($style);
        if (!$source || !is_readable($source)) {
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
            $minified = $this->minifyCSS($content);
            $this->set($cache_key, $minified);
        }

        // Update WordPress style registration
        $this->updateStyleRegistration($style, $cache_file);
    }

    private function shouldProcessStyle($style, string $handle, array $excluded_css): bool {
        // First check if we have a src property
        if (!isset($style->src) || empty($style->src)) {
            return false;
        }

        $src = $style->src;
        return strpos($src, '.min.css') === false
            && strpos($src, '//') !== 0
            && strpos($src, site_url()) !== false
            && !in_array($handle, $excluded_css)
            && !$this->isExcluded($src, $excluded_css);
    }

    private function getSourcePath($style): ?string {
        if (!isset($style->src)) {
            return null;
        }

        $src = $style->src;
        
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

    private function updateStyleRegistration($style, string $cache_file): void {
        if (!isset($style->src)) {
            return;
        }

        $style->src = str_replace(
            ABSPATH,
            site_url('/'),
            $cache_file
        );
        $style->ver = filemtime($cache_file);
    }

    private function minifyCSS(string $css): string {
        try {
            // Reset preserved content
            $this->preserved = [];

            // Preserve special content
            $css = $this->preserveContent($css);

            // Apply minification
            $css = $this->applyMinification($css);

            // Restore preserved content
            $css = $this->restoreContent($css);

            return trim($css);
        } catch (\Throwable $e) {
            $this->logError("CSS minification failed", $e);
            return $css; // Return original on error
        }
    }

    private function preserveContent(string $css): string {
        // Preserve data URIs
        $css = preg_replace_callback(self::PRESERVE_PATTERNS['data_uris'], 
            function($matches) {
                $key = '___URI_' . count($this->preserved) . '___';
                $this->preserved[$key] = $matches[0];
                return $key;
            }, 
            $css
        );

        // Preserve calc() operations
        $css = preg_replace_callback(self::PRESERVE_PATTERNS['calc'],
            function($matches) {
                $key = '___CALC_' . count($this->preserved) . '___';
                $calc = 'calc(' . preg_replace('/\s*([+\-*\/])\s*/', ' $1 ', $matches[1]) . ')';
                $this->preserved[$key] = $calc;
                return $key;
            },
            $css
        );

        // Preserve important comments and strings
        foreach (['comments', 'strings'] as $type) {
            $css = preg_replace_callback(self::PRESERVE_PATTERNS[$type],
                function($matches) use ($type) {
                    $key = '___' . strtoupper($type) . '_' . count($this->preserved) . '___';
                    $this->preserved[$key] = $matches[0];
                    return $key;
                },
                $css
            );
        }

        return $css;
    }

    private function applyMinification(string $css): string {
        // Remove comments
        $css = preg_replace(self::MINIFY_PATTERNS['comments'], '', $css);

        // Apply whitespace patterns
        foreach (self::MINIFY_PATTERNS['whitespace'] as $pattern => $replacement) {
            $css = preg_replace($pattern, $replacement, $css);
        }

        // Apply number optimization patterns
        foreach (self::MINIFY_PATTERNS['numbers'] as $pattern => $replacement) {
            $css = preg_replace($pattern, $replacement, $css);
        }

        return $css;
    }

    private function restoreContent(string $css): string {
        return strtr($css, $this->preserved);
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
        return $this->cache_dir . $key . '.css';
    }
}