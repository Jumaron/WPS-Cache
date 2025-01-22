<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Enhanced JavaScript minification implementation
 */
final class MinifyJS extends AbstractCacheDriver {
    private const MAX_FILE_SIZE = 500000; // 500KB

    private const REGEX_PATTERNS = [
        'strings' => '/(["\'])(?:\\\\.|(?!\1).)*+\1/s',
        'comments' => [
            'preserve' => '/\/\*![\s\S]*?\*\//s',
            'single' => '/\/\/[^\n]*/',
            'multi' => '/\/\*[\s\S]*?\*\//'
        ],
        'regex' => '/(?:^|[[({,;=?:+|&!~-]|\b(?:return|yield|delete|throw|typeof|void|case))\s*(\/(?![*\/])(?:\\\\.|\[(?:\\\\.|[^\]])+\]|[^/\\\n]|(?<=\/)[gimyus]*)*\/[gimyus]*)~ix', // Modified regex pattern, using / delimiter, and 'i' flag
    ];

    // Reserved keywords, before/after keywords, and operators (extracted from MatthiasMullie\Minify\JS data files)
    private const KEYWORDS_RESERVED = ["do","if","in","for","let","new","try","var","case","else","enum","eval","null","this","true","void","with","break","catch","class","const","false","super","throw","while","yield","delete","export","import","public","return","static","switch","typeof","default","extends","finally","package","private","continue","debugger","function","arguments","interface","protected","implements","instanceof","abstract","boolean","byte","char","double","final","float","goto","int","long","native","short","synchronized","throws","transient","volatile"];
    private const KEYWORDS_BEFORE = ["do","in","let","new","var","case","else","enum","void","with","class","const","yield","delete","export","import","public","static","typeof","extends","package","private","function","protected","implements","instanceof"];
    private const KEYWORDS_AFTER = ["in","public","extends","private","protected","implements","instanceof"];
    private const OPERATORS = ["+","-","*","/","%","=","+=","-=","*=","/=","%=","<<=",">>>=",">>=","&=","^=","|=","&","|","^","~","<",">","<<",">>",">>>","==","===","!=","!==","<=","&&","||","!",".","[","]","?",":",",",";","(",")","{","}"];
    private const OPERATORS_BEFORE = ["+","-","*","/","%","=","+=","-=","*=","/=","%=","<<=",">>>=",">>=","&=","^=","|=","&","|","^","~","<",">","<<",">>",">>>","==","===","!=","!==","<=","&&","||","!",".","[","?",":",",",";","(","{"];
    private const OPERATORS_AFTER = ["+","-","*","/","%","=","+=","-=","*=","/=","%=","<<=",">=","<<=",">>=","&=","^=","|=","&","|","^","<",">",">>",">>>","==","===","!=","!==","<=","&&","||",".","[","]","?",":",",",";","(",")","}"];

    private string $cache_dir;
    private array $settings;
    private array $extracted = [];
    private int $extractedCount = 0;

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
        if (empty($js)) return false;

        try {
            $this->extracted = [];
            $this->extractedCount = 0;

            // 1. Extract important comments first
            $js = $this->extract($js, self::REGEX_PATTERNS['comments']['preserve']);
            if ($js === false) return false;

            // 2. Extract strings and regexes
            $js = $this->extract($js, self::REGEX_PATTERNS['strings']);
            if ($js === false) return false;
            $js = $this->extract($js, self::REGEX_PATTERNS['regex']);
            if ($js === false) return false;

            // 3. Remove other comments
            $js_temp = preg_replace(self::REGEX_PATTERNS['comments']['single'], '', $js);
            if ($js_temp === null) {
                $this->logError("preg_replace failed when removing single-line comments", new \Exception("preg_replace returned null"));
                return false;
            }
            $js = $js_temp;

            $js_temp = preg_replace(self::REGEX_PATTERNS['comments']['multi'], '', $js);
            if ($js_temp === null) {
                $this->logError("preg_replace failed when removing multi-line comments", new \Exception("preg_replace returned null"));
                return false;
            }
            $js = $js_temp;


            // 4. Normalize line endings
            $js = str_replace(["\r\n", "\r"], "\n", $js);

            // 5. Advanced whitespace handling
            $js = $this->stripWhitespace($js);
            if ($js === false) return false;


            // 6. Safe boolean replacement
            $js_temp = preg_replace('/\btrue\b/', '!0', $js);
            if ($js_temp === null) {
                $this->logError("preg_replace failed during boolean replacement (true)", new \Exception("preg_replace returned null"));
                return false;
            }
            $js = $js_temp;

            $js_temp = preg_replace('/\bfalse\b/', '!1', $js);
            if ($js_temp === null) {
                $this->logError("preg_replace failed during boolean replacement (false)", new \Exception("preg_replace returned null"));
                return false;
            }
            $js = $js_temp;


            // 7. Safe property optimization
            $js = $this->optimizePropertyNotation($js);
            if ($js === false) return false;


            // 8. Restore extracted content
            $js = $this->restoreExtracted($js);
            if ($js === false) return false;


            return trim($js);
        } catch (\Throwable $e) {
            $this->logError("JS minification failed", $e);
            return false;
        }
    }

    private function stripWhitespace(string $js): string|false {
        // Improved operator spacing
        $js_temp = preg_replace('/(?<=[\s;}]|^)\s*([-+])\s*(?=\w)/', '$1', $js);
        if ($js_temp === null) {
            $this->logError("preg_replace failed in stripWhitespace - unary operator spacing", new \Exception("preg_replace returned null"));
            return false;
        }
        $js = $js_temp;

        // General operator spacing - More robust regex for strings and regex literals
        $js_temp = preg_replace('/
            \s*([!=<>+*\/%&|^~,;:?{}()[\]])\s*
            (?=
                (?:
                    (?:"(?:\\\\.|[^"])*")  # Double quoted string
                    |
                    (?:\'(?:\\\\.|[^\'])*\') # Single quoted string
                    |
                    (?:\/(?:\\\\.|[^\/])*\/(?:gimyus)?) # Regex literal
                    |
                    [^\'"]*                 # Non-string, non-regex part
                )*
                $
            )
        /x', '$1', $js);
        if ($js_temp === null) {
            $this->logError("preg_replace failed in stripWhitespace - general operator spacing", new \Exception("preg_replace returned null"));
            return false;
        }
        $js = $js_temp;


        // Keyword spacing
        $keywords = array_merge(
            array_map('preg_quote', self::KEYWORDS_BEFORE),
            array_map('preg_quote', self::KEYWORDS_AFTER)
        );
        $js_temp = preg_replace('/\b(' . implode('|', $keywords) . ')\s+/', '$1 ', $js); // Simplified regex
        if ($js_temp === null) {
            $this->logError("preg_replace failed in stripWhitespace - keyword spacing", new \Exception("preg_replace returned null"));
            return false;
        }
        $js = $js_temp;


        // Semicolon handling
        $js_temp_array = preg_replace([
            '/;+\s*(?=\W)/',            # Remove redundant semicolons
            '/\s*;\s*(?=})/',           # Remove before closing braces
            '/(\})\s*(?=[\w\$\(])/'     # Add semicolon after blocks
        ], ['', '', '$1;'], $js);

        if (!is_array($js_temp_array) && $js_temp_array === null) {
            $this->logError("preg_replace failed in stripWhitespace - semicolon handling", new \Exception("preg_replace with array of patterns returned null"));
            return false;
        }
        if (is_string($js_temp_array)) {
             $js = $js_temp_array;
        } elseif (is_array($js_temp_array)) {
            $js = $js_temp_array; // if array is returned, assume first element is the result (as per docs, if subject is string, string is returned)
            if (isset($js[0])) {
                $js = $js[0];
            } else {
                $this->logError("preg_replace in semicolon handling returned array but no first element found", new \Exception("preg_replace with array of patterns returned unexpected array format"));
                return false;
            }
        } else {
             $this->logError("preg_replace in semicolon handling returned unexpected type", new \Exception("preg_replace with array of patterns returned neither string nor array"));
             return false;
        }


        return $js;
    }

    private function optimizePropertyNotation(string $js): string|false {
        $js_temp = preg_replace_callback(
            '/(?<!\w)([a-zA-Z_$][\w$]*)\["([a-zA-Z_$][\w$]*)"\]/',
            function ($matches) {
                return in_array($matches[2], self::KEYWORDS_RESERVED)
                    ? $matches[0]
                    : "{$matches[1]}.{$matches[2]}";
            },
            $js
        );
        if ($js_temp === null) {
            $this->logError("preg_replace_callback failed in optimizePropertyNotation", new \Exception("preg_replace_callback returned null"));
            return false;
        }
        return $js_temp;
    }

    private function extract(string $js, string $pattern): string|false {
        if (!is_string($js)) {
            $this->logError("Error in extract: Input \$js is not a string.", new \Exception("Input \$js is not a string"));
            return false;
        }
        if (!is_string($pattern)) {
            $this->logError("Error in extract: Input \$pattern is not a string.", new \Exception("Input \$pattern is not a string"));
            return false;
        }

        $result = preg_replace_callback(
            $pattern,
            function ($matches) {
                $placeholder = 'MINIFY_EX_' . $this->extractedCount++ . '_';
                $this->extracted[$placeholder] = $matches[0];
                return $placeholder;
            },
            $js
        );

        if ($result === null) {
            $error = error_get_last();
            $errorMessage = $error ? $error['message'] : 'Unknown preg_replace_callback error';
            $this->logError("preg_replace_callback failed in extract function with pattern: " . $pattern . " - " . $errorMessage, new \Exception("preg_replace_callback returned null"));
            return false;
        }
        return $result;
    }

    private function restoreExtracted(string $js): string {
        return strtr($js, $this->extracted);
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