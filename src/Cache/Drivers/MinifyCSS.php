<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Enhanced CSS minification implementation
 */
final class MinifyCSS extends AbstractCacheDriver {
    private const REGEX_PATTERNS = [
        'strings' => '/([\'"])((?:\\\\.|[^\\\\])*?)\1/',
        'comments' => [
            'preserve' => '/\/\*![\s\S]*?\*\//',  // Important comments
            'remove' => '/\/\*(?!!)[^*]*\*+([^\/][^*]*\*+)*\//' // Remove non-important
        ],
        'math_funcs' => '/\b(calc|clamp|min|max)(\(.+?)(?=$|;|})/m',
        'custom_props' => '/(?<=^|[;}{])\s*(--[^:;{}"\'\s]+)\s*:([^;{}]+)/m'
    ];

    private string $cache_dir;
    private array $settings;
    private array $extracted = [];

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
        
        if (!$this->shouldProcessStyle($style, $handle, $excluded_css)) {
            return;
        }

        $source = $this->getSourcePath($style);
        if (!$source || !is_readable($source)) {
            return;
        }

        $content = file_get_contents($source);
        if ($content === false || empty(trim($content))) {
            return;
        }

        $cache_key = $this->generateCacheKey($handle . $content . filemtime($source));
        $cache_file = $this->getCacheFile($cache_key);

        if (!file_exists($cache_file)) {
            $minified = $this->minifyCSS($content);
            $this->set($cache_key, $minified);
        }

        $this->updateStyleRegistration($style, $cache_file);
    }

    private function minifyCSS(string $css): string {
        try {
            $this->extracted = [];

            // Extract & preserve content
            $css = $this->extractStrings($css);
            $css = $this->extractComments($css);
            $css = $this->extractMathFunctions($css);
            $css = $this->extractCustomProperties($css);

            // Perform minification
            $css = $this->stripWhitespace($css);
            $css = $this->shortenHexColors($css);
            $css = $this->shortenNumbers($css);
            $css = $this->shortenFontWeights($css);

            // Restore preserved content
            $css = strtr($css, $this->extracted);

            return trim($css);
        } catch (\Throwable $e) {
            $this->logError("CSS minification failed", $e);
            return $css;
        }
    }

    private function extractStrings(string $css): string {
        return preg_replace_callback(
            self::REGEX_PATTERNS['strings'],
            fn($matches) => $this->preserve('STRING', $matches[0]),
            $css
        );
    }

    private function extractComments(string $css): string {
        // Preserve important comments
        $css = preg_replace_callback(
            self::REGEX_PATTERNS['comments']['preserve'],
            fn($matches) => $this->preserve('COMMENT', $matches[0]),
            $css
        );

        // Remove other comments
        return preg_replace(self::REGEX_PATTERNS['comments']['remove'], '', $css);
    }

    private function extractMathFunctions(string $css): string {
        return preg_replace_callback(
            self::REGEX_PATTERNS['math_funcs'],
            function($matches) {
                $function = $matches[1];
                $expr = $this->extractBalancedExpression($matches[2]);
                return $this->preserve('MATH', $function . '(' . trim(substr($expr, 1, -1)) . ')');
            },
            $css
        );
    }

    private function extractCustomProperties(string $css): string {
        return preg_replace_callback(
            self::REGEX_PATTERNS['custom_props'],
            fn($matches) => $this->preserve('CUSTOM', $matches[1] . ':' . trim($matches[2])),
            $css
        );
    }

    private function stripWhitespace(string $css): string {
        $css = preg_replace('/\s+/', ' ', $css);
        $css = preg_replace('/\s*([\*$~^|]?+=|[{};,>~]|!important\b)\s*/', '$1', $css);
        $css = preg_replace('/([\[(:>\+])\s+/', '$1', $css);
        $css = preg_replace('/\s+([\]\)>\+])/', '$1', $css);
        $css = preg_replace('/\s+(:)(?![^\}]*\{)/', '$1', $css);
        return str_replace(';}', '}', trim($css));
    }

    private function shortenHexColors(string $css): string {
        $css = preg_replace('/(?<=[: ])#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3(?:([0-9a-f])\4)?(?=[; }])/i', '#$1$2$3$4', $css);
        $css = preg_replace('/(?<=[: ])#([0-9a-f]{6})ff(?=[; }])/i', '#$1', $css);
        return preg_replace('/(?<=[: ])#([0-9a-f]{3})f(?=[; }])/i', '#$1', $css);
    }

    private function shortenNumbers(string $css): string {
        $css = preg_replace('/(^|[^0-9])0\.([0-9]+)/', '$1.$2', $css);
        $css = preg_replace('/([^0-9])0(%|em|ex|px|in|cm|mm|pt|pc|rem|vw|vh)/', '${1}0', $css);
        return preg_replace('/\s*(0 0|0 0 0|0 0 0 0)\s*/', '0', $css);
    }

    private function shortenFontWeights(string $css): string {
        return str_replace(
            ['font-weight:normal', 'font-weight:bold'],
            ['font-weight:400', 'font-weight:700'],
            $css
        );
    }

    private function preserve(string $type, string $content): string {
        $key = sprintf('__%s_%d__', $type, count($this->extracted));
        $this->extracted[$key] = $content;
        return $key;
    }

    private function extractBalancedExpression(string $expr): string {
        $length = strlen($expr);
        $result = '';
        $opened = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $expr[$i];
            $result .= $char;
            
            if ($char === '(') {
                $opened++;
            } elseif ($char === ')' && --$opened === 0) {
                break;
            }
        }

        return $result;
    }

    private function shouldProcessStyle($style, string $handle, array $excluded_css): bool {
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
        
        if (strpos($src, 'http') !== 0) {
            $src = site_url($src);
        }

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

    private function getCacheFile(string $key): string {
        return $this->cache_dir . $key . '.css';
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