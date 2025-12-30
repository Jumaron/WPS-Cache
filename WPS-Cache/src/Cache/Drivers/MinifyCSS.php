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
                    $prevToken = ['type' => self::T_WHITESPACE]; // Treat as break
                }
                // Skip normal comments completely (don't update prevToken)
                continue;
            }

            // 2. Whitespace
            if ($token['type'] === self::T_WHITESPACE) {
                continue; // We handle space insertion logic based on tokens, not input whitespace
            }

            // 3. Handle Pending Semicolon
            // If we have a semicolon waiting, check if we need to write it.
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
                // Check if previous token was a function name
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

        // Write trailing semicolon if exists (rare, usually EOF or })
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
                while ($i < $len && ctype_space($css[$i])) {
                    $i++;
                }
                yield ['type' => self::T_WHITESPACE, 'value' => ' '];
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
            if ($char === ',' || $char === '>' || $char === '~') {
                yield ['type' => self::T_OPERATOR, 'value' => $char];
                $i++;
                continue;
            }

            // Words / Identifiers / Numbers / Hex / Dimensions / Values
            // We consume greedily until we hit a delimiter.
            // Note: + and - are context dependent, but we can treat them as part of the word
            // if they are inside (e.g. -webkit or -10px).
            // However, + as a combinator is separate.
            // Logic: Scan until whitespace or special char.
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
                $i++;
            }

            $val = substr($css, $start, $i - $start);

            // Refine token type if it's just an operator like +
            // Note: - is usually part of a word (-moz, -10px) unless it's strictly " - ".
            // Tokenizer consumes " - " as " " "-" " ".
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
        // 1. Inside calc(), spaces are REQUIRED around + and - operators
        if ($inCalc) {
            // "10px + 20px" or "var(--a) - 10px"
            // Note: $curr['value'] could be "-" (Operator/Word)
            if ($curr['value'] === '+' || $curr['value'] === '-') return true;
            if ($prev['value'] === '+' || $prev['value'] === '-') return true;
        }

        $t1 = $prev['type'];
        $t2 = $curr['type'];

        // 2. Word + Word needs space (margin: 10px 20px; .class .child)
        if ($t1 === self::T_WORD && $t2 === self::T_WORD) {
            return true;
        }

        // 3. Media Query Conjunctions
        // @media (min-width: 600px) and (max-width: 800px)
        // Previous logic missed spaces around parenthesis in keywords

        // "and ("
        if ($t1 === self::T_WORD && $t2 === self::T_PAREN_OPEN) {
            $kw = strtolower($prev['value']);
            if ($kw === 'and' || $kw === 'or' || $kw === 'not') return true;
        }

        // ") and"
        if ($t1 === self::T_PAREN_CLOSE && $t2 === self::T_WORD) {
            $kw = strtolower($curr['value']);
            if ($kw === 'and' || $kw === 'or' || $kw === 'not') return true;
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
        if (preg_match('/^0([a-z%]+)$/i', $val, $matches)) {
            $unit = strtolower($matches[1]);

            // Protected units (Time, Angle, Frequency)
            $protected = ['s', 'ms', 'deg', 'rad', 'grad', 'turn', 'hz', 'khz'];

            if (in_array($unit, $protected, true)) {
                return $val; // Keep it (e.g., 0s)
            }

            return '0'; // Strip it (e.g., 0px -> 0)
        }

        return $val;
    }

    /**
     * Compresses Hex Colors (#AABBCC -> #abc) and (#RRGGBBAA -> #RGBA).
     */
    private function compressHex(string $val): string
    {
        // Check for empty string or non-hash
        if (empty($val) || $val[0] !== '#') {
            return $val;
        }

        $len = strlen($val);
        // Only optimize 7 chars (#RRGGBB) or 9 chars (#RRGGBBAA)
        if ($len !== 7 && $len !== 9) {
            return $val;
        }

        $val = strtolower($val);

        // 6-digit Hex
        if ($len === 7) {
            if ($val[1] === $val[2] && $val[3] === $val[4] && $val[5] === $val[6]) {
                return '#' . $val[1] . $val[3] . $val[5];
            }
        }
        // 8-digit Hex (Alpha)
        elseif ($len === 9) {
            if ($val[1] === $val[2] && $val[3] === $val[4] && $val[5] === $val[6] && $val[7] === $val[8]) {
                return '#' . $val[1] . $val[3] . $val[5] . $val[7];
            }
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
