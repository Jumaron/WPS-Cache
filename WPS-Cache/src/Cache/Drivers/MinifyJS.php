<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Enhanced JavaScript minification implementation.
 * 
 * Uses a Lexical Scanner (Tokenizer) instead of Regex replacement to safely 
 * parse and minify modern JavaScript (ES6+), avoiding common pitfalls like 
 * corrupting regex literals or breaking Automatic Semicolon Insertion (ASI).
 */
final class MinifyJS extends AbstractCacheDriver
{
    private const MAX_FILE_SIZE = 1024 * 1024; // 1MB limit for minification safety

    // Token Types
    private const T_WHITESPACE = 0;
    private const T_COMMENT    = 1;
    private const T_STRING     = 2;
    private const T_REGEX      = 3;
    private const T_OPERATOR   = 4;
    private const T_WORD       = 5; // Identifiers, Keywords, Numbers
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

        // Use atomic write (from AbstractCacheDriver) to prevent race conditions
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

        // Generate cache key based on content hash
        $cache_key = $this->generateCacheKey($handle . $content . filemtime($source));
        $cache_file = $this->getCacheFile($cache_key);

        if (!file_exists($cache_file)) {
            try {
                $minified = $this->minifyJS($content);
                $this->set($cache_key, $minified);
            } catch (\Throwable $e) {
                // If minification fails, log error but don't crash; the site will use original
                $this->logError("JS Minification failed for $handle", $e);
                return;
            }
        }

        // Serve cached file
        if (file_exists($cache_file)) {
            $this->updateScriptRegistration($script, $cache_file);
        }
    }

    /**
     * Core Minification Logic using Lexical Analysis.
     * Uses a memory stream and generators for high performance and low memory footprint.
     */
    private function minifyJS(string $js): string
    {
        $output = fopen('php://memory', 'r+');
        $prevToken = null;

        foreach ($this->tokenize($js) as $token) {
            // 1. Handle Comments: Skip them
            if ($token['type'] === self::T_COMMENT) {
                // Optional: Preserve license comments (/*! ... */)
                if (str_starts_with($token['value'], '/*!')) {
                    fwrite($output, $token['value'] . "\n");
                    // Treat preserved comment as a break to prevent combining words incorrectly
                    $prevToken = ['type' => self::T_WHITESPACE, 'value' => "\n"];
                }
                continue;
            }

            // 2. Handle Whitespace
            if ($token['type'] === self::T_WHITESPACE) {
                // Safety: If the whitespace contains a newline, we might need to preserve it
                // to support Automatic Semicolon Insertion (ASI).
                // Example: return \n x  --> return x (changes meaning!).
                if ($prevToken && $this->needsNewlineProtection($prevToken, $token['value'])) {
                    // It's safer to keep the newline if we aren't building a full AST
                    fwrite($output, "\n");
                    $prevToken = ['type' => self::T_WHITESPACE, 'value' => "\n"];
                }
                continue;
            }

            // 3. Handle Insertion of necessary spaces
            if ($prevToken && $this->needsSpace($prevToken, $token)) {
                fwrite($output, ' ');
            }

            fwrite($output, $token['value']);
            $prevToken = $token;
        }

        rewind($output);
        $result = stream_get_contents($output);
        fclose($output);

        return $result ?: $js; // Fallback to original if empty
    }

    /**
     * Generator that yields tokens from the raw JS string.
     * This avoids loading an array of 100k tokens into memory.
     */
    private function tokenize(string $js): \Generator
    {
        $len = strlen($js);
        $i = 0;
        $lastMeaningfulToken = null; // Used to decide between Regex vs Division

        while ($i < $len) {
            $char = $js[$i];

            // --- 1. Whitespace ---
            if (ctype_space($char)) {
                $start = $i;
                while ($i < $len && ctype_space($js[$i])) {
                    $i++;
                }
                yield ['type' => self::T_WHITESPACE, 'value' => substr($js, $start, $i - $start)];
                continue;
            }

            // --- 2. Comments or Division or Regex ---
            if ($char === '/') {
                $next = $js[$i + 1] ?? '';

                // Line Comment //
                if ($next === '/') {
                    $start = $i;
                    $i += 2;
                    while ($i < $len && $js[$i] !== "\n" && $js[$i] !== "\r") {
                        $i++;
                    }
                    yield ['type' => self::T_COMMENT, 'value' => substr($js, $start, $i - $start)];
                    continue;
                }

                // Block Comment /* */
                if ($next === '*') {
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

                // Regex Literal vs Division Operator
                // This is the specific logic that fixes "Unexpected token *" errors.
                // If the previous token was an operator or keyword, this / starts a Regex.
                if ($this->isRegexStart($lastMeaningfulToken)) {
                    $start = $i;
                    $i++; // Consume opening /
                    $inClass = false; // Inside [] char class

                    while ($i < $len) {
                        $c = $js[$i];

                        if ($c === '\\') { // Escape char
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
                            $i++; // Consume closing /
                            // Consume flags (g, i, m, etc.)
                            while ($i < $len && ctype_alpha($js[$i])) {
                                $i++;
                            }
                            break;
                        }

                        // Safety break for unclosed regex at newline (invalid JS)
                        if ($c === "\n" || $c === "\r") {
                            break;
                        }

                        $i++;
                    }

                    $regex = substr($js, $start, $i - $start);
                    yield $lastMeaningfulToken = ['type' => self::T_REGEX, 'value' => $regex];
                    continue;
                }

                // If not comment or regex, it's the Division operator
                yield $lastMeaningfulToken = ['type' => self::T_OPERATOR, 'value' => '/'];
                $i++;
                continue;
            }

            // --- 3. Strings ---
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
                    if ($js[$i] === "\n" || $js[$i] === "\r") {
                        // Unclosed string at EOL - bail out or let JS engine error later
                        break;
                    }
                    $i++;
                }
                yield $lastMeaningfulToken = ['type' => self::T_STRING, 'value' => substr($js, $start, $i - $start)];
                continue;
            }

            // --- 4. Template Literals ---
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
                    // Templates CAN span newlines, so we don't break on \n
                    $i++;
                }
                yield $lastMeaningfulToken = ['type' => self::T_TEMPLATE, 'value' => substr($js, $start, $i - $start)];
                continue;
            }

            // --- 5. Operators & Punctuation ---
            // Group 1: Single chars that separate words
            if (str_contains('{}()[],:;?^~', $char)) {
                yield $lastMeaningfulToken = ['type' => self::T_OPERATOR, 'value' => $char];
                $i++;
                continue;
            }

            // Group 2: Operators that might be multi-char (>=, ===, &&, etc)
            if (str_contains('.!<>=+-*%&|', $char)) {
                $start = $i;
                while ($i < $len && str_contains('.!<>=+-*%&|', $js[$i])) {
                    $i++;
                }
                yield $lastMeaningfulToken = ['type' => self::T_OPERATOR, 'value' => substr($js, $start, $i - $start)];
                continue;
            }

            // --- 6. Words (Identifiers, Keywords, Numbers) ---
            // Consumes anything that isn't special char or whitespace
            $start = $i;
            while ($i < $len) {
                $c = $js[$i];
                if (ctype_space($c) || str_contains('/"\'`{}()[],:;?^~.!<>=+-*%&|', $c)) {
                    break;
                }
                $i++;
            }
            yield $lastMeaningfulToken = ['type' => self::T_WORD, 'value' => substr($js, $start, $i - $start)];
        }
    }

    /**
     * Determines if a forward slash '/' should be treated as a Regex Literal.
     * This logic mimics the standard JS parsing rules.
     */
    private function isRegexStart(?array $lastToken): bool
    {
        if ($lastToken === null) {
            return true; // Start of file
        }

        if ($lastToken['type'] !== self::T_WORD && $lastToken['type'] !== self::T_OPERATOR) {
            return false; // Regex can't follow a string, regex, or template
        }

        $val = $lastToken['value'];

        // If it's an operator, regex is usually expected next
        // e.g. x = /foo/, return /foo/, ( /foo/ )
        if ($lastToken['type'] === self::T_OPERATOR) {
            // Edge case: ++ and -- are operators but act like suffix, so / following them is division
            // e.g. x++ / y
            if ($val === '++' || $val === '--') {
                return false;
            }
            return true;
        }

        // If it's a Keyword, regex is expected
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
            'yield'
        ];

        return in_array($val, $keywords, true);
    }

    /**
     * Determines if a space is required between two tokens.
     * e.g. "var x" needs space. "x=y" does not.
     */
    private function needsSpace(array $prev, array $curr): bool
    {
        // Word + Word needs space (var x, typeof a, return 1, 10 in)
        if ($prev['type'] === self::T_WORD && $curr['type'] === self::T_WORD) {
            return true;
        }

        // + + needs space (a + +b vs a++b)
        // - - needs space
        if ($prev['type'] === self::T_OPERATOR && $curr['type'] === self::T_OPERATOR) {
            if (($prev['value'] === '+' && str_starts_with($curr['value'], '+')) ||
                ($prev['value'] === '-' && str_starts_with($curr['value'], '-'))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a newline must be preserved for ASI.
     * Mainly prevents "return \n x" -> "return x".
     */
    private function needsNewlineProtection(array $prev, string $whitespace): bool
    {
        // If the whitespace doesn't even contain a newline, we don't care
        if (!str_contains($whitespace, "\n") && !str_contains($whitespace, "\r")) {
            return false;
        }

        if ($prev['type'] === self::T_WORD) {
            $keywords = ['return', 'throw', 'break', 'continue', 'yield'];
            if (in_array($prev['value'], $keywords, true)) {
                return true;
            }
        }

        return false;
    }

    // --- Standard Helper Methods ---

    private function shouldProcessScript($script, string $handle, array $excluded_js): bool
    {
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

    private function getSourcePath($script): ?string
    {
        if (!isset($script->src)) {
            return null;
        }

        $src = $script->src;
        if (strpos($src, 'http') !== 0) {
            $src = site_url($src);
        }

        return str_replace(
            [site_url(), 'wp-content'],
            [ABSPATH, 'wp-content'],
            $src
        );
    }

    private function isValidSource(?string $source): bool
    {
        return $source
            && is_readable($source)
            && filesize($source) <= self::MAX_FILE_SIZE;
    }

    private function updateScriptRegistration($script, string $cache_file): void
    {
        if (!isset($script->src)) {
            return;
        }
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
            if (fnmatch($pattern, $url)) {
                return true;
            }
        }
        return false;
    }
}
