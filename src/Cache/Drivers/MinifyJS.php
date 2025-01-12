<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Enhanced JavaScript minification implementation
 */
final class MinifyJS extends AbstractCacheDriver {
    private const MAX_FILE_SIZE = 500000; // 500KB

    private const REGEX_PATTERNS = [
        'template_literals' => '/`(?:\\\\.|[^\\\\`])*`/',  // Template literals
        'regex_patterns' => '/\\/(?!\*)(?:[^\\[\\/\\\\\n\r]++|(?:\\\\.)++|(?:\\[(?:[^\\]\\\\\n\r]++|(?:\\\\.)++)++\\])++)++\\/[gimuy]*/',
        'strings' => '/([\'"])((?:\\\\.|[^\\\\])*?)\1/',  // String literals
        'comments' => [
            'preserve' => '/\/\*![\s\S]*?\*\//',  // Important comments
            'single' => '/\/\/.*$/m',  // Single line comments
            'multi' => '/\/\*[^*]*\*+([^/*][^*]*\*+)*\//'  // Multi-line comments
        ]
    ];

    private string $cache_dir;
    private array $settings;
    private array $extracted = [];

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

    private function minifyJS(string $js): string|false {
        if (empty($js)) {
            return false;
        }

        try {
            $this->extracted = [];

            // Extract & preserve content
            $js = $this->extractStrings($js);
            $js = $this->extractRegex($js);
            $js = $this->stripComments($js);

            // Perform minification
            $js = $this->shortenBools($js);
            $js = $this->stripWhitespace($js);
            $js = $this->propertyNotation($js);

            // Restore preserved content
            $js = strtr($js, $this->extracted);

            // Final cleanup
            $js = trim($js);

            return empty($js) ? false : $js;
        } catch (\Throwable $e) {
            $this->logError("JS minification failed", $e);
            return false;
        }
    }

    private function extractStrings(string $js): string {
        return preg_replace_callback(
            self::REGEX_PATTERNS['strings'],
            fn($matches) => $this->preserve('STRING', $matches[0]),
            $js
        );
    }

    private function extractRegex(string $js): string {
        $operatorsAndKeywords = implode('|', [
            'return', 'typeof', 'in', 'instanceof', 'void', 'delete',
            'new', '=', '\+', '\!', '~', '\?', ':', '\*'
        ]);

        // Match regex when preceded by operator or keyword
        $pattern = "/(?<=^|[=:,;\+\-\*\/\{\(\[&\|!]|\b(?:$operatorsAndKeywords)\b)\s*" . 
                  substr(self::REGEX_PATTERNS['regex_patterns'], 1, -1) . '/';

        return preg_replace_callback(
            $pattern,
            fn($matches) => $this->preserve('REGEX', $matches[0]),
            $js
        );
    }

    private function stripComments(string $js): string {
        // Preserve important comments
        $js = preg_replace_callback(
            self::REGEX_PATTERNS['comments']['preserve'],
            fn($matches) => $this->preserve('COMMENT', $matches[0]),
            $js
        );

        // Remove all other comments
        $js = preg_replace(self::REGEX_PATTERNS['comments']['single'], '', $js);
        return preg_replace(self::REGEX_PATTERNS['comments']['multi'], '', $js);
    }

    private function stripWhitespace(string $js): string {
        // Convert line endings
        $js = str_replace(["\r\n", "\r"], "\n", $js);
    
        // Minify whitespace
        $patterns = [
            '/\s+/',                            // Multiple whitespace to single
            '/\s*([:,;{}()])\s*/',            // Clean whitespace around punctuation
            '/\s*\+\s*/',                      // Clean whitespace around +
            '/\s*\-\s*/',                      // Clean whitespace around -
            '/\s*([=&|])\s*/',                // Clean whitespace around operators
            '/\s*(\b(case|return|typeof)\b)\s*/',  // Ensure space after certain keywords
            '/\s*(\/\/[^\n]*\n)/',            // Preserve line comments
            '/\n+/',                          // Multiple newlines to single
        ];
        
        $replacements = [
            ' ',
            '$1',
            '+',
            '-',
            '$1',
            '$1 ',
            '$1',
            "\n"
        ];
    
        $js = preg_replace($patterns, $replacements, $js);
    
        // Special cases for ASI (Automatic Semicolon Insertion)
        $js = preg_replace('/;\s*;/', ';', $js);      // Multiple semicolons
        $js = preg_replace('/\}(\s*else\b)/', "}\n$1", $js); // Newline for else
        $js = preg_replace('/\}\s*[\n;]+/', "}\n", $js);    // Clean after blocks
        
        return trim($js);
    }

    private function shortenBools(string $js): string {
        return str_replace(
            ['true', 'false', 'undefined', 'null'],
            ['!0', '!1', 'void 0', '""'],
            $js
        );
    }

    private function propertyNotation(string $js): string {
        return preg_replace(
            '/([\'"])\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\1\s*(:)/',
            '$2$3',
            $js
        );
    }

    private function preserve(string $type, string $content): string {
        $key = sprintf('__%s_%d__', $type, count($this->extracted));
        $this->extracted[$key] = $content;
        return $key;
    }

    // WordPress integration methods remain largely unchanged...
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

        $script = $wp_scripts->registered[$handle];
        
        if (!$this->shouldProcessScript($script, $handle, $excluded_js)) {
            return;
        }

        $source = $this->getSourcePath($script);
        if (!$this->isValidSource($source)) {
            return;
        }

        $content = @file_get_contents($source);
        if ($content === false || empty(trim($content))) {
            return;
        }

        $cache_key = $this->generateCacheKey($handle . $content . filemtime($source));
        $cache_file = $this->getCacheFile($cache_key);

        if (!file_exists($cache_file)) {
            $minified = $this->minifyJS($content);
            if ($minified !== false) {
                $this->set($cache_key, $minified);
            }
        }

        if (file_exists($cache_file)) {
            $this->updateScriptRegistration($script, $cache_file);
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