<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Enhanced JavaScript minification implementation.
 * 
 * Uses a Lexical Scanner (Tokenizer) to safely parse and minify JS.
 * Handles ASI (Automatic Semicolon Insertion) by detecting where
 * semicolons or newlines must be preserved.
 */
final class MinifyJS extends AbstractCacheDriver
{
    private const MAX_FILE_SIZE = 1024 * 1024; // 1MB limit

    // Token Types
    private const T_WHITESPACE = 0;
    private const T_COMMENT    = 1;
    private const T_STRING     = 2;
    private const T_REGEX      = 3;
    private const T_OPERATOR   = 4;
    private const T_WORD       = 5; // Identifiers, Keywords, Numbers, Booleans
    private const T_TEMPLATE   = 6;

    private string $cache_dir;
    private array $settings;

    public function __construct()
    {
        $this->cache_dir = WPSC_CACHE_DIR . 'js/';
        $this->settings = get_option('wpsc_settings', []);
        $this->ensureCacheDirectory($this->cache_dir);
    }

    public function initialize(): void
    {
        if (!$this->initialized && !is_admin() && ($this->settings['js_minify'] ?? false)) {
            add_action('wp_enqueue_scripts', [$this, 'processScripts'], 100);
            $this->initialized = true;
        }
    }

    public function isConnected(): bool
    {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;
        return $wp_filesystem->is_writable($this->cache_dir);
    }

    public function get(string $key): mixed
    {
        $file = $this->getCacheFile($key);
        return is_readable($file) ? file_get_contents($file) : null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (!is_string($value) || empty(trim($value))) {
            return;
        }

        $file = $this->getCacheFile($key);
        if (!$this->atomicWrite($file, $value)) {
            $this->logError("Failed to write JS cache file: $file");
        }
    }

    public function delete(string $key): void
    {
        $file = $this->getCacheFile($key);
        if (file_exists($file) && !wp_delete_file($file)) {
            $this->logError("Failed to delete JS cache file: $file");
        }
    }

    public function clear(): void
    {
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

    public function processScripts(): void
    {
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

    private function processScript(string $handle, \WP_Scripts $wp_scripts, array $excluded_js): void
    {
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
            try {
                $minified = $this->minifyJS($content);
                $this->set($cache_key, $minified);
            } catch (\Throwable $e) {
                $this->logError("JS Minification failed for $handle", $e);
                return;
            }
        }

        if (file_exists($cache_file)) {
            $this->updateScriptRegistration($script, $cache_file);
        }
    }

    /**
     * Core Minification Logic using Lexical Analysis.
     */
    private function minifyJS(string $js): string
    {
        $output = fopen('php://memory', 'r+');
        $prevToken = null;

        $iterator = $this->tokenize($js);
        $currToken = $iterator->current();

        while ($currToken !== null) {
            $iterator->next();
            $nextToken = $iterator->valid() ? $iterator->current() : null;

            // 1. Handle Comments: Skip them
            if ($currToken['type'] === self::T_COMMENT) {
                if (str_starts_with($currToken['value'], '/*!')) {
                    fwrite($output, $currToken['value'] . "\n");
                    $prevToken = ['type' => self::T_WHITESPACE, 'value' => "\n"];
                }
                $currToken = $nextToken;
                continue;
            }

            // 2. Handle Whitespace
            if ($currToken['type'] === self::T_WHITESPACE) {
                $hasNewline = str_contains($currToken['value'], "\n") || str_contains($currToken['value'], "\r");

                if ($prevToken && $nextToken && $hasNewline) {
                    // 2a. ASI Protection: Preserve Newline if strictly necessary for syntax (e.g. return \n val)
                    if ($this->needsNewlineProtection($prevToken, $nextToken, $currToken['value'])) {
                        fwrite($output, "\n");
                        $prevToken = ['type' => self::T_WHITESPACE, 'value' => "\n"];
                        $currToken = $nextToken;
                        continue;
                    }

                    // 2b. MISSING SEMICOLON FIX:
                    // If we remove the newline, we might merge two statements (e.g. "{} window").
                    // If the original code implied a semicolon via ASI, we must insert a real one now.
                    if ($this->shouldInsertSemicolon($prevToken, $nextToken)) {
                        fwrite($output, ';');
                        // Treat inserted semicolon as an operator for subsequent logic
                        $prevToken = ['type' => self::T_OPERATOR, 'value' => ';'];
                        $currToken = $nextToken;
                        continue;
                    }
                }

                $currToken = $nextToken;
                continue;
            }

            // 3. Handle Insertion of necessary spaces (e.g. "var x", "10 .toString")
            if ($prevToken && $this->needsSpace($prevToken, $currToken)) {
                fwrite($output, ' ');
            }

            fwrite($output, $currToken['value']);
            $prevToken = $currToken;
            $currToken = $nextToken;
        }

        rewind($output);
        $result = stream_get_contents($output);
        fclose($output);

        return $result ?: $js;
    }

    /**
     * Generator that yields tokens from the raw JS string.
     */
    private function tokenize(string $js): \Generator
    {
        $len = strlen($js);
        $i = 0;
        $lastMeaningfulToken = null;

        while ($i < $len) {
            $char = $js[$i];

            // Whitespace
            if (ctype_space($char)) {
                $start = $i;
                while ($i < $len && ctype_space($js[$i])) {
                    $i++;
                }
                yield ['type' => self::T_WHITESPACE, 'value' => substr($js, $start, $i - $start)];
                continue;
            }

            // Comments / Regex / Division
            if ($char === '/') {
                $next = $js[$i + 1] ?? '';

                if ($next === '/') { // Line Comment
                    $start = $i;
                    $i += 2;
                    while ($i < $len && $js[$i] !== "\n" && $js[$i] !== "\r") $i++;
                    yield ['type' => self::T_COMMENT, 'value' => substr($js, $start, $i - $start)];
                    continue;
                }
                if ($next === '*') { // Block Comment
                    $start = $i;
                    $i += 2;
                    while ($i < $len - 1) {
                        if ($js[$i] === '*' && $js[$i + 1] === '/') {
                            $i += 2;
                            break;
                        }
                        $i++;
                    }
                    yield ['type' => self::T_COMMENT, 'value' => substr($js, $start, $i - $start)];
                    continue;
                }

                if ($this->isRegexStart($lastMeaningfulToken)) {
                    $start = $i;
                    $i++;
                    $inClass = false;
                    while ($i < $len) {
                        $c = $js[$i];
                        if ($c === '\\') {
                            $i += 2;
                            continue;
                        }
                        if ($c === '[') {
                            $inClass = true;
                        }
                        if ($c === ']') {
                            $inClass = false;
                        }
                        if ($c === '/' && !$inClass) {
                            $i++;
                            while ($i < $len && ctype_alpha($js[$i])) $i++;
                            break;
                        }
                        if ($c === "\n" || $c === "\r") break;
                        $i++;
                    }
                    yield $lastMeaningfulToken = ['type' => self::T_REGEX, 'value' => substr($js, $start, $i - $start)];
                    continue;
                }

                yield $lastMeaningfulToken = ['type' => self::T_OPERATOR, 'value' => '/'];
                $i++;
                continue;
            }

            // Strings
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $start = $i;
                $i++;
                while ($i < $len) {
                    if ($js[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($js[$i] === $quote) {
                        $i++;
                        break;
                    }
                    if ($js[$i] === "\n" || $js[$i] === "\r") break;
                    $i++;
                }
                yield $lastMeaningfulToken = ['type' => self::T_STRING, 'value' => substr($js, $start, $i - $start)];
                continue;
            }

            // Template Literals
            if ($char === '`') {
                $start = $i;
                $i++;
                while ($i < $len) {
                    if ($js[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($js[$i] === '`') {
                        $i++;
                        break;
                    }
                    $i++;
                }
                yield $lastMeaningfulToken = ['type' => self::T_TEMPLATE, 'value' => substr($js, $start, $i - $start)];
                continue;
            }

            // Operators
            if (str_contains('{}()[],:;?^~', $char)) {
                yield $lastMeaningfulToken = ['type' => self::T_OPERATOR, 'value' => $char];
                $i++;
                continue;
            }
            if (str_contains('.!<>=+-*%&|', $char)) {
                $start = $i;
                while ($i < $len && str_contains('.!<>=+-*%&|', $js[$i])) $i++;
                yield $lastMeaningfulToken = ['type' => self::T_OPERATOR, 'value' => substr($js, $start, $i - $start)];
                continue;
            }

            // Words
            $start = $i;
            while ($i < $len) {
                $c = $js[$i];
                if ($c === '.' && $i > $start && ctype_digit($js[$i - 1]) && isset($js[$i + 1]) && ctype_digit($js[$i + 1])) {
                    $i++;
                    continue;
                }
                if (ctype_space($c) || str_contains('/"\'`{}()[],:;?^~.!<>=+-*%&|', $c)) break;
                $i++;
            }

            $isProperty = false;
            if ($lastMeaningfulToken !== null && $lastMeaningfulToken['type'] === self::T_OPERATOR && $lastMeaningfulToken['value'] === '.') {
                $isProperty = true;
            }

            yield $lastMeaningfulToken = [
                'type' => self::T_WORD,
                'value' => substr($js, $start, $i - $start),
                'is_property' => $isProperty
            ];
        }
    }

    private function isRegexStart(?array $lastToken): bool
    {
        if ($lastToken === null) return true;
        $val = $lastToken['value'];
        if ($val === ')' || $val === ']') return false;
        if ($lastToken['type'] === self::T_OPERATOR) {
            if ($val === '++' || $val === '--') return false;
            return true;
        }
        if ($lastToken['type'] !== self::T_WORD) return false;
        if (!empty($lastToken['is_property'])) return false;

        $keywords = [
            'case',
            'else',
            'return',
            'throw',
            'typeof',
            'void',
            'delete',
            'do',
            'await',
            'yield',
            'if',
            'while',
            'for',
            'in',
            'instanceof',
            'new',
            'export'
        ];
        return in_array($val, $keywords, true);
    }

    private function needsSpace(array $prev, array $curr): bool
    {
        if ($prev['type'] === self::T_WORD && $curr['type'] === self::T_WORD) return true;
        if ($prev['type'] === self::T_WORD && is_numeric($prev['value'][0]) && $curr['value'] === '.') return true;
        if ($prev['type'] === self::T_OPERATOR && $curr['type'] === self::T_OPERATOR) {
            if (($prev['value'] === '+' && str_starts_with($curr['value'], '+')) ||
                ($prev['value'] === '-' && str_starts_with($curr['value'], '-'))
            ) return true;
        }
        return false;
    }

    /**
     * Determines if a semicolon must be inserted when a newline is removed.
     */
    private function shouldInsertSemicolon(array $prev, array $next): bool
    {
        // 1. Next token must be a Word (Keyword or Identifier)
        if ($next['type'] !== self::T_WORD) {
            return false;
        }

        // 2. Previous token must be the end of an expression/block/statement
        // Words (variables/literals)
        if ($prev['type'] === self::T_WORD) return true;

        // Strings/Regex/Templates
        if ($prev['type'] === self::T_STRING || $prev['type'] === self::T_REGEX || $prev['type'] === self::T_TEMPLATE) return true;

        // Operators that can end an expression
        if ($prev['type'] === self::T_OPERATOR) {
            // } = end of object or block
            // ] = end of array or access
            // ) = end of function call or grouping
            // ++ / -- = postfix operators
            if (in_array($prev['value'], ['}', ']', ')', '++', '--'], true)) {

                // EXCEPTION: Do not insert semicolon before these continuations
                // 'instanceof' and 'in' are binary operators (infix).
                // 'else', 'catch', 'finally' continue a control block.
                $exceptions = ['else', 'catch', 'finally', 'instanceof', 'in'];

                if (in_array($next['value'], $exceptions, true)) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    private function needsNewlineProtection(array $prev, array $next, string $whitespace): bool
    {
        if (!str_contains($whitespace, "\n") && !str_contains($whitespace, "\r")) return false;

        // 1. Restricted Productions (return, throw, etc cannot have newline after them)
        if ($prev['type'] === self::T_WORD) {
            $restricted = ['return', 'throw', 'break', 'continue', 'yield'];
            if (in_array($prev['value'], $restricted, true)) return true;
        }

        // 2. Ambiguous Starts of next line
        if ($next['type'] === self::T_OPERATOR || $next['type'] === self::T_TEMPLATE) {
            $val = $next['value'];
            if ($val === '[' || $val === '(' || $val === '`' || $val === '+' || $val === '-' || $val === '/') {
                $safeTerminators = [';', '}', ':'];
                if (!in_array($prev['value'], $safeTerminators, true)) return true;
            }
        }
        return false;
    }

    // --- Helpers ---
    private function shouldProcessScript($script, string $handle, array $excluded_js): bool
    {
        if (!isset($script->src) || empty($script->src)) return false;
        $src = $script->src;
        return strpos($src, '.min.js') === false
            && strpos($src, '//') !== 0
            && strpos($src, site_url()) !== false
            && !in_array($handle, $excluded_js)
            && !$this->isExcluded($src, $excluded_js);
    }

    private function getSourcePath($script): ?string
    {
        if (!isset($script->src)) return null;
        $src = $script->src;
        if (strpos($src, 'http') !== 0) $src = site_url($src);
        return str_replace([site_url(), 'wp-content'], [ABSPATH, 'wp-content'], $src);
    }

    private function isValidSource(?string $source): bool
    {
        return $source && is_readable($source) && filesize($source) <= self::MAX_FILE_SIZE;
    }

    private function updateScriptRegistration($script, string $cache_file): void
    {
        if (!isset($script->src)) return;
        $script->src = str_replace(ABSPATH, site_url('/'), $cache_file);
        $script->ver = filemtime($cache_file);
    }

    private function getCacheFile(string $key): string
    {
        return $this->cache_dir . $key . '.js';
    }

    private function isExcluded(string $url, array $excluded_patterns): bool
    {
        foreach ($excluded_patterns as $pattern) {
            if (fnmatch($pattern, $url)) return true;
        }
        return false;
    }
}
