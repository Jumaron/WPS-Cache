<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * State-of-the-Art CSS Minification implementation.
 * 
 * Uses a Lexical Scanner (Tokenizer) to safely parse CSS.
 * Features:
 * - Context-aware `calc()` support (preserves required operator spacing).
 * - Safe Zero-unit stripping (preserves time/angle units).
 * - Hex color compression (6-digit and 8-digit Alpha support).
 * - Semicolon removal before closing braces.
 * - Single-pass processing with memory-efficient generators.
 */
final class MinifyCSS extends AbstractCacheDriver
{
    private const MAX_FILE_SIZE = 1024 * 1024; // 1MB safety limit

    // Token Types (Granular for precise optimization)
    private const T_WHITESPACE = 0;
    private const T_COMMENT    = 1;
    private const T_STRING     = 2;
    private const T_OPEN       = 3; // {
    private const T_CLOSE      = 4; // }
    private const T_COLON      = 5; // :
    private const T_SEMICOLON  = 6; // ;
    private const T_PAREN_OPEN = 7; // (
    private const T_PAREN_CLOSE = 8; // )
    private const T_OPERATOR   = 9; // , > + ~
    private const T_WORD       = 10; // Selectors, properties, values

    private string $cache_dir;

    public function __construct()
    {
        parent::__construct();
        $this->cache_dir = WPSC_CACHE_DIR . 'css/';
        $this->ensureDirectory($this->cache_dir);
    }

    public function initialize(): void
    {
        if (!$this->initialized && !is_admin() && ($this->settings['css_minify'] ?? false)) {
            add_action('wp_enqueue_scripts', [$this, 'processStyles'], 100);
            $this->initialized = true;
        }
    }

    public function isConnected(): bool
    {
        return is_dir($this->cache_dir) && is_writable($this->cache_dir);
    }

    // AbstractCacheDriver Interface Implementation
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
        // Use atomic write from AbstractCacheDriver
        if (!$this->atomicWrite($file, $value)) {
            $this->logError("Failed to write CSS cache file: $file");
        }
    }

    public function delete(string $key): void
    {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public function clear(): void
    {
        $this->recursiveDelete($this->cache_dir);
    }

    /**
     * Hook Handler: Intercepts WP styles queue.
     */
    public function processStyles(): void
    {
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

    private function processStyle(string $handle, \WP_Styles $wp_styles, array $excluded_css): void
    {
        if (!isset($wp_styles->registered[$handle])) {
            return;
        }

        /** @var \WP_Dependencies|\WP_Dependency $style */
        $style = $wp_styles->registered[$handle];

        if (!$this->shouldProcessStyle($style, $handle, $excluded_css)) {
            return;
        }

        $source = $this->getSourcePath($style);
        if (!$source || !is_readable($source) || filesize($source) > self::MAX_FILE_SIZE) {
            return;
        }

        $content = file_get_contents($source);
        if ($content === false || empty(trim($content))) {
            return;
        }

        // Generate cache key based on content hash + mtime
        $cache_key = $this->generateCacheKey($handle . md5($content) . filemtime($source));
        $cache_file = $this->getCacheFile($cache_key);

        if (!file_exists($cache_file)) {
            try {
                $minified = $this->minifyCSS($content);
                $this->set($cache_key, $minified);
            } catch (\Throwable $e) {
                $this->logError("CSS Minification failed for $handle", $e);
                return;
            }
        }

        // Serve cached file URL
        if (file_exists($cache_file)) {
            $this->updateStyleRegistration($style, $cache_file);
        }
    }

    /**
     * SOTA CSS Minification Logic (The "Deep" Version)
     */
    private function minifyCSS(string $css): string
    {
        $output = fopen('php://memory', 'r+');

        $prevToken = null;
        $calcDepth = 0; // Tracks if we are inside calc(), clamp(), var()
        $pendingSemicolon = false; // Deferred write buffer

        foreach ($this->tokenize($css) as $token) {

            // 1. Comments
            if ($token['type'] === self::T_COMMENT) {
                // Preserve license comments (/*! ... */)
                if (str_starts_with($token['value'], '/*!')) {
                    // Flush pending semicolon before comment
                    if ($pendingSemicolon) {
                        fwrite($output, ';');
                        $pendingSemicolon = false;
                    }
                    fwrite($output, $token['value']);
                    $prevToken = ['type' => self::T_WHITESPACE, 'value' => ' ']; // Treat as break
                }
                continue;
            }

            // 2. Whitespace
            if ($token['type'] === self::T_WHITESPACE) {
                continue; // We generate our own spaces later
            }

            // 3. Handle Pending Semicolon
            // If the current token is '}', we DROP the semicolon.
            if ($pendingSemicolon) {
                if ($token['type'] !== self::T_CLOSE) {
                    fwrite($output, ';');
                }
                $pendingSemicolon = false;
            }

            // 4. Semicolon Buffering
            if ($token['type'] === self::T_SEMICOLON) {
                $pendingSemicolon = true;
                continue;
            }

            // 5. Calc Context Tracking
            if ($token['type'] === self::T_PAREN_OPEN) {
                // Check if previous token was a function name related to math
                if ($prevToken && $prevToken['type'] === self::T_WORD && preg_match('/^(calc|clamp|min|max|var)$/i', $prevToken['value'])) {
                    $calcDepth++;
                } elseif ($calcDepth > 0) {
                    $calcDepth++; // Nested parens inside calc
                }
            }
            if ($token['type'] === self::T_PAREN_CLOSE && $calcDepth > 0) {
                $calcDepth--;
            }

            // 6. Optimization: Zero Units
            // Transform '0px' -> '0'. But NOT '0s', '0deg', or inside calc()
            if ($token['type'] === self::T_WORD && $calcDepth === 0) {
                $token['value'] = $this->optimizeZeroUnits($token['value']);
            }

            // 7. Optimization: Hex Colors
            if ($token['type'] === self::T_WORD) {
                $token['value'] = $this->compressHex($token['value']);
            }

            // 8. Insert Space Logic
            if ($prevToken && $this->needsSpace($prevToken, $token, $calcDepth > 0)) {
                fwrite($output, ' ');
            }

            fwrite($output, $token['value']);
            $prevToken = $token;
        }

        // Write trailing semicolon if exists
        if ($pendingSemicolon) {
            fwrite($output, ';');
        }

        rewind($output);
        $result = stream_get_contents($output);
        fclose($output);

        return $result ?: $css;
    }

    /**
     * Lexical Scanner / Tokenizer
     */
    private function tokenize(string $css): \Generator
    {
        $len = strlen($css);
        $i = 0;

        while ($i < $len) {
            $char = $css[$i];

            // Whitespace
            if (ctype_space($char)) {
                $start = $i;
                while ($i < $len && ctype_space($css[$i])) $i++;
                yield ['type' => self::T_WHITESPACE, 'value' => ' '];
                continue;
            }

            // Strings (" or ')
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $start = $i;
                $i++;
                while ($i < $len) {
                    if ($css[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($css[$i] === $quote) {
                        $i++;
                        break;
                    }
                    if ($css[$i] === "\n") break;
                    $i++;
                }
                yield ['type' => self::T_STRING, 'value' => substr($css, $start, $i - $start)];
                continue;
            }

            // Comments (/* ... */)
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $start = $i;
                $i += 2;
                while ($i < $len - 1) {
                    if ($css[$i] === '*' && $css[$i + 1] === '/') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }
                yield ['type' => self::T_COMMENT, 'value' => substr($css, $start, $i - $start)];
                continue;
            }

            // Granular Tokens
            if ($char === '{') {
                yield ['type' => self::T_OPEN, 'value' => '{'];
                $i++;
                continue;
            }
            if ($char === '}') {
                yield ['type' => self::T_CLOSE, 'value' => '}'];
                $i++;
                continue;
            }
            if ($char === ':') {
                yield ['type' => self::T_COLON, 'value' => ':'];
                $i++;
                continue;
            }
            if ($char === ';') {
                yield ['type' => self::T_SEMICOLON, 'value' => ';'];
                $i++;
                continue;
            }
            if ($char === '(') {
                yield ['type' => self::T_PAREN_OPEN, 'value' => '('];
                $i++;
                continue;
            }
            if ($char === ')') {
                yield ['type' => self::T_PAREN_CLOSE, 'value' => ')'];
                $i++;
                continue;
            }

            // Operators
            if (str_contains(',>+~', $char)) {
                yield ['type' => self::T_OPERATOR, 'value' => $char];
                $i++;
                continue;
            }

            // Words / Identifiers
            $start = $i;
            while ($i < $len) {
                $c = $css[$i];
                // Break on special chars
                if (ctype_space($c) || str_contains('{}():;,\'"', $c) || ($c === '/' && ($css[$i + 1] ?? '') === '*')) break;
                if (str_contains('>+~', $c)) break;
                $i++;
            }

            $val = substr($css, $start, $i - $start);
            yield ['type' => self::T_WORD, 'value' => $val];
        }
    }

    private function needsSpace(array $prev, array $curr, bool $inCalc): bool
    {
        // 1. Inside calc(), spaces are REQUIRED around + and - operators
        if ($inCalc) {
            $val = $curr['value'];
            $pVal = $prev['value'];
            if ($val === '+' || $val === '-') return true;
            if ($pVal === '+' || $pVal === '-') return true;
        }

        $t1 = $prev['type'];
        $t2 = $curr['type'];

        // 2. Word + Word (margin: 10px 20px)
        if ($t1 === self::T_WORD && $t2 === self::T_WORD) return true;

        // 3. Variable/Function fusion fix: var(--a) var(--b)
        if ($t1 === self::T_PAREN_CLOSE && $t2 === self::T_WORD) return true;

        // 4. Media Query "and ("
        if ($t1 === self::T_WORD && $t2 === self::T_PAREN_OPEN) {
            $kw = strtolower($prev['value']);
            if ($kw === 'and' || $kw === 'or' || $kw === 'not') return true;
        }
        if ($t1 === self::T_PAREN_CLOSE && $t2 === self::T_WORD) {
            $kw = strtolower($curr['value']);
            if ($kw === 'and' || $kw === 'or' || $kw === 'not') return true;
        }

        return false;
    }

    private function optimizeZeroUnits(string $val): string
    {
        if (!str_starts_with($val, '0')) return $val;
        if (preg_match('/^0([a-z%]+)$/i', $val, $matches)) {
            $unit = strtolower($matches[1]);
            // Protected units
            if (in_array($unit, ['s', 'ms', 'deg', 'rad', 'grad', 'turn', 'hz', 'khz'], true)) return $val;
            return '0';
        }
        return $val;
    }

    private function compressHex(string $val): string
    {
        if (empty($val) || $val[0] !== '#') return $val;
        $len = strlen($val);
        $val = strtolower($val);

        if ($len === 7) { // #aabbcc
            if ($val[1] === $val[2] && $val[3] === $val[4] && $val[5] === $val[6]) {
                return '#' . $val[1] . $val[3] . $val[5];
            }
        } elseif ($len === 9) { // #aabbccdd
            if ($val[1] === $val[2] && $val[3] === $val[4] && $val[5] === $val[6] && $val[7] === $val[8]) {
                return '#' . $val[1] . $val[3] . $val[5] . $val[7];
            }
        }
        return $val;
    }

    // Helpers
    private function shouldProcessStyle($style, string $handle, array $excluded_css): bool
    {
        if (!isset($style->src) || empty($style->src)) return false;
        $src = $style->src;
        return strpos($src, '.min.css') === false
            && strpos($src, '//') !== 0
            && strpos($src, site_url()) !== false
            && !in_array($handle, $excluded_css)
            && !$this->isExcluded($src, $excluded_css);
    }

    private function getSourcePath($style): ?string
    {
        if (!isset($style->src)) return null;
        $src = $style->src;
        if (strpos($src, 'http') !== 0) $src = site_url($src);
        return str_replace([site_url(), 'wp-content'], [ABSPATH, 'wp-content'], $src);
    }

    private function updateStyleRegistration($style, string $cache_file): void
    {
        if (!isset($style->src)) return;
        $style->src = str_replace(ABSPATH, site_url('/'), $cache_file);
        $style->ver = filemtime($cache_file);
    }

    private function getCacheFile(string $key): string
    {
        return $this->cache_dir . $key . '.css';
    }

    private function isExcluded(string $url, array $excluded_patterns): bool
    {
        foreach ($excluded_patterns as $pattern) {
            if (fnmatch($pattern, $url)) return true;
        }
        return false;
    }
}
