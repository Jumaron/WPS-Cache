<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Enhanced JavaScript minification implementation
 */
final class MinifyJS extends AbstractCacheDriver {
    private const MAX_FILE_SIZE = 500000; // 500KB

    private const REGEX_PATTERNS = [
        'strings' => '#(["\'])(?:\\.|(?!\1).)*+\1#s', // Changed delimiter to #
        'comments' => [
            'preserve' => '#/\*![\s\S]*?\*/#s',       // Changed delimiter to #
            'single' => '#//[^\n]*#',                 // Changed delimiter to #
            'multi' => '#/\*[\s\S]*?\*/#'             // Changed delimiter to #
        ],
        'regex' => '~
            (?:^|[[({,;=?:+|&!~-]|\b(?:return|yield|delete|throw|typeof|void|case))\s*
            (/(?![*/])(?:\\\\.|\[(?:\\\\.|[^\]])+\]|[^/\\\n]|/[gimyus]*)*+/[gimyus]*)
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
        if (empty($js)) return false;

        try {
            $this->extracted = [];
            $this->extractedCount = 0;

            // 1. Extract important comments first
            $js = $this->extract($js, self::REGEX_PATTERNS['comments']['preserve']);
            
            // 2. Extract strings and regexes
            $js = $this->extract($js, self::REGEX_PATTERNS['strings']);
            $js = $this->extract($js, self::REGEX_PATTERNS['regex']);

            // 3. Remove other comments
            $js = preg_replace(self::REGEX_PATTERNS['comments']['single'], '', $js);
            $js = preg_replace(self::REGEX_PATTERNS['comments']['multi'], '', $js);

            // 4. Normalize line endings
            $js = str_replace(["\r\n", "\r"], "\n", $js);

            // 5. Advanced whitespace handling
            $js = $this->stripWhitespace($js);

            // 6. Safe boolean replacement
            $js = preg_replace('/\btrue\b/', '!0', $js);
            $js = preg_replace('/\bfalse\b/', '!1', $js);

            // 7. Safe property optimization
            $js = $this->optimizePropertyNotation($js);

            // 8. Restore extracted content
            $js = $this->restoreExtracted($js);

            return trim($js);
        } catch (\Throwable $e) {
            $this->logError("JS minification failed", $e);
            return false;
        }
    }

    private function stripWhitespace(string $js): string {
        // Improved operator spacing (changed delimiter to #)
        $js = preg_replace('#
            (?<=[\s;}]|^)\s*([-+])\s*(?=\w)  # Unary operators
        #x', '$1', $js);

        // General operator spacing (changed delimiter to #, fixed backreference)
        $js = preg_replace('#
            \s*([!=<>+*\/%&|^~,;:?{}()[\]])\s*
            (?=
                [^"\'#]*                # Lookahead to ensure not in string/regex
                (?:
                    (?:["\']).*?\1      # Skip strings (corrected to \1)
                    |
                    \/[^/].*?\/         # Skip regex
                )*$
            )
        #x', '$1', $js);

        // Keyword spacing (changed delimiter to #)
        $keywords = array_merge(
            array_map('preg_quote', self::KEYWORDS_BEFORE),
            array_map('preg_quote', self::KEYWORDS_AFTER)
        );
        $js = preg_replace('#
            \b(' . implode('|', $keywords) . ')\s+
        #x', '$1 ', $js);

        // Semicolon handling (changed delimiters to #)
        $js = preg_replace([
            '#;+\s*(?=\W)#',            # Remove redundant semicolons
            '#\s*;\s*(?=})#',           # Remove before closing braces
            '#(\})\s*(?=[\w\$\(])#'     # Add semicolon after blocks
        ], ['', '', '$1;'], $js);

        return $js;
    }

    private function optimizePropertyNotation(string $js): string {
        // Changed delimiter to #
        return preg_replace_callback(
            '#(?<!\w)([a-zA-Z_$][\w$]*)\["([a-zA-Z_$][\w$]*)"\]#',
            function ($matches) {
                return in_array($matches[2], self::KEYWORDS_RESERVED) 
                    ? $matches[0] 
                    : "{$matches[1]}.{$matches[2]}";
            },
            $js
        );
    }

    private function extract(string $js, string $pattern): string {
        return preg_replace_callback(
            $pattern,
            function ($matches) {
                $placeholder = 'MINIFY_EX_' . $this->extractedCount++ . '_';
                $this->extracted[$placeholder] = $matches[0];
                return $placeholder;
            },
            $js
        ) ?? $js;
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