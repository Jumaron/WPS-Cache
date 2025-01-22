<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Enhanced JavaScript minification implementation (mimicking MatthiasMullie\Minify\JS)
 */
final class MinifyJS extends AbstractCacheDriver {
    private const MAX_FILE_SIZE = 500000; // 500KB

    private const REGEX_PATTERNS = [
        'strings' => '/([\'"])(.*?)(?<!\\\\)(\\\\\\\\)*+\\1/s', // Improved string matching
        'comments' => [
            'preserve' => '/\/\*![\s\S]*?\*\//s',  // Important comments
            'single' => '/\/\/[^\n]*$/m',  // Single line comments
            'multi' => '/\/\*[^*]*\*+(?:[^\/][^*]*\*+)*\//'  // Multi-line comments
        ],
        'regex' => '~
        (?<=^|[=!:&|,?+\\-*\/\(\{\[]) # Lookbehind for common operators or start of line/block
        \s*                         # Optional whitespace before the slash
        /                           # Opening /
        (?![\/*])                   # Not followed by / or * (not a comment)
        (?:                         # Non-capturing group for the regex body
            [^/\\\\\[\n\r]          # Any character except /, \, [, newline, or carriage return
            |\\\\.                 # Or an escaped character
            |\[                     # Or a character set [
            (?:                     # Non-capturing group for the character set body
                [^\]\\\\\n\r]       # Any character except ], \, newline, or carriage return
                |\\\\.             # Or an escaped character
            )*
            \]
        )+
        /                           # Closing /
        [gimyus]*                   # Optional flags
        (?=\s*($|[\)\}\],;:\?\+\-\*\/\&\|\!])) # Lookahead for common operators or end of line/block
    ~x',
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
        if (empty($js)) {
            return false;
        }

        try {
            $this->extracted = [];
            $this->extractedCount = 0;

            // 1. Extract strings and regexes (to avoid minifying inside them)
            $js = $this->extract($js, self::REGEX_PATTERNS['strings']);
            $js = $this->extract($js, self::REGEX_PATTERNS['regex']);

            // 2. Preserve important comments and Remove comments
            $js = $this->extract($js, self::REGEX_PATTERNS['comments']['preserve']);
            $js = preg_replace(self::REGEX_PATTERNS['comments']['single'], '', $js);
            $js = preg_replace(self::REGEX_PATTERNS['comments']['multi'], '', $js);

            // 3. Convert line endings to Unix-style
            $js = str_replace(["\r\n", "\r"], "\n", $js);

            // 4. Strip whitespace
            $js = $this->stripWhitespace($js);

            // 5. Shorten booleans (true -> !0, false -> !1)
            $js = $this->shortenBooleans($js);

            // 6. Optimize property notation (e.g., array["key"] -> array.key)
            $js = $this->optimizePropertyNotation($js);

            // 7. Restore extracted strings, regexes and comments
            $js = $this->restoreExtracted($js);

            return trim($js);
        } catch (\Throwable $e) {
            $this->logError("JS minification failed", $e);
            return false;
        }
    }

    private function extract(string $js, string $pattern): string {
        return preg_replace_callback(
            $pattern,
            function ($matches) {
                $placeholder = '__EXTRACTED' . $this->extractedCount . '__';
                $this->extracted[$placeholder] = $matches[0];
                $this->extractedCount++;
                return $placeholder;
            },
            $js
        ) ?? $js;
    }

    private function restoreExtracted(string $js): string {
        return strtr($js, $this->extracted);
    }

    /**
     *  Strip whitespace as per the logic in MatthiasMullie\Minify\JS
     */
    private function stripWhitespace(string $js): string
    {
        // Remove unnecessary whitespace around operators
        $operatorsBefore = $this->getOperatorsForRegex(self::OPERATORS_BEFORE);
        $operatorsAfter = $this->getOperatorsForRegex(self::OPERATORS_AFTER);

        // Handle . and = without lookbehinds in the main regex
        $js = preg_replace('/\s*([+\-*\/%]=?|<<=?|>>>?=?|&=?|\^=?|\|=?|!=?=?|={2,3}|[<>]=?|[~,;:\?\(\{\[])\s*/s', '$1', $js);

        // Collapse whitespace around reserved words into single space
        $keywordsBefore = $this->getKeywordsForRegex(self::KEYWORDS_BEFORE);
        $keywordsAfter = $this->getKeywordsForRegex(self::KEYWORDS_AFTER);
        $js = preg_replace('/(^|[;\}\s])(' . implode('|', $keywordsBefore) . ')\s+/', '\\2 ', $js);
        $js = preg_replace('/\s+(' . implode('|', $keywordsAfter) . ')(?=([;\{\s]|$))/', ' \\1', $js);

        // Remove whitespace after return if followed by certain characters
        $js = preg_replace('/\breturn\s+(["\'\/\+\-])/', 'return$1', $js);

        // Remove specific unnecessary whitespaces
        $js = preg_replace('/\)\s+\{/', '){', $js);
        $js = preg_replace('/}\n(else|catch|finally)\b/', '}$1', $js);

        // Ensure semicolons are present between top-level statements
        $js = preg_replace('/(?<=\})\s*(?!\s*(var|let|const|function|class|import|export|{|\[|\())/m', ';', $js);

        // Remove unnecessary semicolons, but avoid removing between statements within blocks
        $js = preg_replace('/;+(?!\s*(var|let|const|function|if|for|while|switch|try|catch|finally))/', ';', $js);
        $js = preg_replace('/;(\}|$)/', '$1', $js);
        $js = preg_replace('/\bfor\(([^;]*);;([^;]*)\)/', 'for(\\1;-;\\2)', $js);
        $js = preg_replace('/\bfor\(([^;]*);-;([^;]*)\)/', 'for(\\1;;\\2)', $js);
        $js = ltrim($js, ';');

        return $js;
    }

    /**
     * Shorten booleans (true -> !0, false -> !1)
     */
    private function shortenBooleans(string $js): string {
        $js = preg_replace('/\btrue\b/', '!0', $js);
        $js = preg_replace('/\bfalse\b/', '!1', $js);
        return $js;
    }

    /**
     * Optimize property notation (array["key"] -> array.key)
     */
    private function optimizePropertyNotation(string $js): string {
        $pattern = '/
            (?<![\w\$])            # Negative lookbehind to ensure it is not preceded by a word character or $
            ([a-zA-Z_$][\w\$]*)    # Capture the object name (must start with a letter, _, or $)
            \s*\[\s*              # Match the opening bracket with optional whitespace
            (["\'])([a-zA-Z_$][\w\$]*)\\2  # Capture the property name inside quotes
            \s*\]                 # Match the closing bracket with optional whitespace
            (?![\w\$])             # Negative lookahead to ensure it is not followed by a word character or $
        /x';

        $js = preg_replace_callback(
            $pattern,
            function ($matches) {
                // Check if the property name is a reserved keyword
                if (in_array($matches[3], self::KEYWORDS_RESERVED)) {
                    return $matches[0]; // Leave it unchanged
                }
                return $matches[1] . '.' . $matches[3];
            },
            $js
        );

        return $js;
    }

    /**
     * Prepare operators for regex usage, similar to MatthiasMullie\Minify\JS
     */
    private function getOperatorsForRegex(array $operators): array
    {
        // Simply escape operators for regex usage
        return array_map(function ($operator) {
            return preg_quote($operator, '/');
        }, $operators);
    }

    /**
     * Prepare keywords for regex usage
     */
    private function getKeywordsForRegex(array $keywords): array {
        $escaped = array_map(function ($keyword) {
            return preg_quote($keyword, '/');
        }, $keywords);

        return array_map(function ($keyword) {
            return '\\b' . $keyword . '\\b';
        }, $escaped);
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