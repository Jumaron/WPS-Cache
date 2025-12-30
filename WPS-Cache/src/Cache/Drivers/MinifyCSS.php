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
 * - Hex color compression (#aabbcc -> #abc).
 * - Single-pass processing for high performance.
 * - Memory efficient generators.
 */
final class MinifyCSS extends AbstractCacheDriver
{
    private const MAX_FILE_SIZE = 1024 * 1024; // 1MB safety limit

    // Token Types
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
    private array $settings;

    public function __construct()
    {
        $this->cache_dir = WPSC_CACHE_DIR . 'css/';
        $this->settings = get_option('wpsc_settings', []);
        $this->ensureCacheDirectory($this->cache_dir);
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

        // Use atomic write to prevent race conditions
        if (!$this->atomicWrite($file, $value)) {
            $this->logError("Failed to write CSS cache file: $file");
        }
    }

    public function delete(string $key): void
    {
        $file = $this->getCacheFile($key);
        if (file_exists($file) && !wp_delete_file($file)) {
            $this->logError("Failed to delete CSS cache file: $file");
        }
    }

    public function clear(): void
    {
        $files = glob($this->cache_dir . '*.css');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file) && !wp_delete_file($file)) {
                $this->logError("Failed to delete CSS file during clear: $file");
            }
        }
    }

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

        // Generate cache key
        $cache_key = $this->generateCacheKey($handle . $content . filemtime($source));
        $cache_file = $this->getCacheFile($cache_key);

        if (!file_exists($cache_file)) {
            try {
                $minified = $this->minifyCSS($content);
                $this->set($cache_key, $minified);
            } catch (\Throwable $e) {
                // Fail safe: If minification errors, do not cache broken file
                $this->logError("CSS Minification failed for $handle", $e);
                return;
            }
        }

        // Serve cached file
        if (file_exists($cache_file)) {
            $this->updateStyleRegistration($style, $cache_file);
        }
    }

    /**
     * SOTA CSS Minification Logic
     */
    private function minifyCSS(string $css): string
    {
        $output = fopen('php://memory', 'r+');

        $prevToken = null;
        $calcDepth = 0; // Tracks if we are inside calc(), clamp(), var(), etc.

        foreach ($this->tokenize($css) as $token) {

            // 1. Comments
            if ($token['type'] === self::T_COMMENT) {
                // Preserve license comments (/*! ... */)
                if (str_starts_with($token['value'], '/*!')) {
                    fwrite($output, $token['value']);
                    $prevToken = ['type' => self::T_WHITESPACE]; // Treat as break
                }
                continue;
            }

            // 2. Whitespace
            if ($token['type'] === self::T_WHITESPACE) {
                // Inside calc(), whitespace is significant around + and -
                if ($calcDepth > 0 && $prevToken) {
                    // We only preserve the space if it might be needed next. 
                    // We'll handle the insertion in the next loop iteration (Operator logic)
                    // or simply convert to a single space here for safety.
                    // But actually, the safest logic is: Insert space if needsSpace returns true.
                    // We handle this below.
                }
                continue;
            }

            // 3. Calc Context Tracking
            if ($token['type'] === self::T_WORD && preg_match('/^(calc|clamp|min|max|var)$/i', $token['value'])) {
                // The next token will be '(', which increments depth
                // We don't increment here, we wait for the paren.
            }
            if ($token['type'] === self::T_PAREN_OPEN) {
                if ($prevToken && $prevToken['type'] === self::T_WORD && preg_match('/^(calc|clamp|min|max|var)$/i', $prevToken['value'])) {
                    $calcDepth++;
                } elseif ($calcDepth > 0) {
                    $calcDepth++; // Nested parens inside calc
                }
            }
            if ($token['type'] === self::T_PAREN_CLOSE && $calcDepth > 0) {
                $calcDepth--;
            }

            // 4. Optimization: Zero Units
            // Transform '0px', '0em' -> '0'. But NOT '0s', '0deg', or inside calc()
            if ($token['type'] === self::T_WORD && $calcDepth === 0) {
                $token['value'] = $this->optimizeZeroUnits($token['value']);
            }

            // 5. Optimization: Hex Colors
            // Transform #AABBCC -> #abc
            if ($token['type'] === self::T_WORD) {
                $token['value'] = $this->compressHex($token['value']);
            }

            // 6. Optimization: Semicolon removal
            // We handle this by buffering. For simplicity in streaming:
            // If we have a stored semicolon from previous loop, and current is '}', we drop it.
            // (Implemented via direct write, so we can't look back easily without buffer. 
            // Instead, we just write the semicolon. Browsers handle ';}' fine, saving 1 byte isn't worth complex logic here).

            // 7. Insert Space Logic
            if ($prevToken && $this->needsSpace($prevToken, $token, $calcDepth > 0)) {
                fwrite($output, ' ');
            }

            fwrite($output, $token['value']);
            $prevToken = $token;
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
                while ($i < $len && ctype_space($css[$i])) {
                    $i++;
                }
                yield ['type' => self::T_WHITESPACE, 'value' => ' ']; // Normalize to single space
                continue;
            }

            // Strings (" or ')
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $start = $i;
                $i++;
                while ($i < $len) {
                    if ($css[$i] === '\\') { // Escape
                        $i += 2;
                        continue;
                    }
                    if ($css[$i] === $quote) {
                        $i++;
                        break;
                    }
                    if ($css[$i] === "\n") {
                        break; // Safety break
                    }
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

            // Punctuation
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

            // Operators (Comma, > + ~)
            // Note: In CSS, + and - can be part of a number (-10px) or an operator (calc(10px + 20px))
            // The tokenizer is simple: treat + and - as WORD parts if followed by digit, or OPERATOR if not?
            // Safer strategy: Treat + and - as Operators if they are standalone.
            // If it's part of a word like -webkit-, the WORD logic below handles it.
            if ($char === ',' || $char === '>' || $char === '~') {
                yield ['type' => self::T_OPERATOR, 'value' => $char];
                $i++;
                continue;
            }

            // + and - are ambiguous. In Tokenizer, we try to consume "words" (identifiers/numbers) greedily.

            // Words / Identifiers / Numbers / Hex / Dimensions
            // Allowed: a-z, A-Z, 0-9, -, _, ., %, #, @, !, + (in calc), * (in hack)
            $start = $i;
            while ($i < $len) {
                $c = $css[$i];
                if (ctype_space($c) || str_contains('{}():;,\'"', $c) || ($c === '/' && ($css[$i + 1] ?? '') === '*')) {
                    break;
                }
                // Break on > and ~ (operators)
                if ($c === '>' || $c === '~') {
                    break;
                }
                // Edge case: + should break if it's potentially an operator in calc
                // But it can also be part of exponential notation 1e+10 (rare in CSS)
                $i++;
            }

            $val = substr($css, $start, $i - $start);

            // Refine token type if it's just an operator
            if ($val === '+' || $val === '*') {
                yield ['type' => self::T_OPERATOR, 'value' => $val];
            } else {
                yield ['type' => self::T_WORD, 'value' => $val];
            }
        }
    }

    /**
     * Determines if space is needed between tokens.
     */
    private function needsSpace(array $prev, array $curr, bool $inCalc): bool
    {
        // Inside calc(), spaces are REQUIRED around + and -
        if ($inCalc) {
            // "10px + 20px"
            if ($curr['value'] === '+' || $curr['value'] === '-') return true;
            if ($prev['value'] === '+' || $prev['value'] === '-') return true;
        }

        // Logic for Standard CSS
        $t1 = $prev['type'];
        $t2 = $curr['type'];

        // Word + Word needs space (margin: 10px 20px; .class .child)
        if ($t1 === self::T_WORD && $t2 === self::T_WORD) {
            return true;
        }

        // Operator interactions
        // .class > .child (No space needed: .class>.child)
        // .class + .child (No space needed: .class+.child)
        // EXCEPT: "and (" in media queries needs space
        if ($t1 === self::T_WORD && $t2 === self::T_PAREN_OPEN) {
            if (strtolower($prev['value']) === 'and') return true; // @media ... and (
            if (strtolower($prev['value']) === 'or') return true;
            if (strtolower($prev['value']) === 'not') return true;
        }

        return false;
    }

    /**
     * Optimizes Zero Units (0px -> 0), but protects time/angle units.
     */
    private function optimizeZeroUnits(string $val): string
    {
        // Must start with 0
        if (!str_starts_with($val, '0')) {
            return $val;
        }

        // Check if it matches 0 + unit pattern
        // Regex: ^0([a-z%]+)$ case insensitive
        if (preg_match('/^0([a-z%]+)$/i', $val, $matches)) {
            $unit = strtolower($matches[1]);

            // Protected units (Time, Angle, Frequency) - Browsers require these
            // Also viewport units often behave weirdly if 0 is unitless in some contexts
            $protected = ['s', 'ms', 'deg', 'rad', 'grad', 'turn', 'hz', 'khz'];

            if (in_array($unit, $protected, true)) {
                return $val; // Keep it (e.g., 0s)
            }

            return '0'; // Strip it (e.g., 0px -> 0)
        }

        return $val;
    }

    /**
     * Compresses Hex Colors (#AABBCC -> #abc).
     */
    private function compressHex(string $val): string
    {
        if ($val[0] !== '#' || strlen($val) !== 7) {
            return $val;
        }

        $val = strtolower($val);
        // Check pattern #aabbcc
        if ($val[1] === $val[2] && $val[3] === $val[4] && $val[5] === $val[6]) {
            return '#' . $val[1] . $val[3] . $val[5];
        }

        return $val;
    }

    // --- Standard Helpers ---

    private function shouldProcessStyle($style, string $handle, array $excluded_css): bool
    {
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

    private function getSourcePath($style): ?string
    {
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

    private function updateStyleRegistration($style, string $cache_file): void
    {
        if (!isset($style->src)) {
            return;
        }
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
            if (fnmatch($pattern, $url)) {
                return true;
            }
        }
        return false;
    }
}
