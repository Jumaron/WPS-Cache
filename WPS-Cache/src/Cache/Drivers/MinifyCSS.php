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
    private const T_COMMENT = 1;
    private const T_STRING = 2;

    private const T_OPEN = 3; // {
    private const T_CLOSE = 4; // }
    private const T_COLON = 5; // :
    private const T_SEMICOLON = 6; // ;
    private const T_PAREN_OPEN = 7; // (
    private const T_PAREN_CLOSE = 8; // )
    private const T_OPERATOR = 9; // , > + ~
    private const T_WORD = 10; // Selectors, properties, values

    // Mask: Whitespace + Special Chars + Operators + /
    // Used for strcspn optimization in tokenizer
    private const TOKENIZER_MASK = " \t\n\r\v\f{}():;,'\">+~/";

    // Optimization: O(1) lookup for single-character tokens
    private const TOKEN_MAP = [
        "{" => self::T_OPEN,
        "}" => self::T_CLOSE,
        ":" => self::T_COLON,
        ";" => self::T_SEMICOLON,
        "(" => self::T_PAREN_OPEN,
        ")" => self::T_PAREN_CLOSE,
        "," => self::T_OPERATOR,
        ">" => self::T_OPERATOR,
        "+" => self::T_OPERATOR,
        "~" => self::T_OPERATOR,
    ];

    private const SELECTOR_PSEUDOS = [
        "not" => true,
        "is" => true,
        "where" => true,
        "has" => true,
        "nth-child" => true,
        "nth-last-child" => true,
        "nth-of-type" => true,
        "nth-last-of-type" => true,
        "nth-col" => true,
        "nth-last-col" => true,
        "dir" => true,
        "lang" => true,
        "host" => true,
        "host-context" => true,
        "part" => true,
        "slotted" => true,
        "matches" => true,
        "-webkit-any" => true,
        "-moz-any" => true,
        "cue" => true,
        "current" => true,
        "past" => true,
        "future" => true,
        "state" => true,
        "view-transition-group" => true,
        "view-transition-image-pair" => true,
        "view-transition-old" => true,
        "view-transition-new" => true,
    ];

    private const CALC_FUNCTIONS = [
        "calc" => true,
        "clamp" => true,
        "min" => true,
        "max" => true,
        "var" => true,
    ];

    private const PRESERVED_UNITS = [
        "s" => true,
        "ms" => true,
        "deg" => true,
        "rad" => true,
        "grad" => true,
        "turn" => true,
        "hz" => true,
        "khz" => true,
    ];

    private string $cache_dir;
    private string $exclusionRegex = "";

    public function __construct()
    {
        parent::__construct();
        $this->cache_dir = WPSC_CACHE_DIR . "css/";
        // Optimization: Removed ensureDirectory here. atomicWrite handles it.

        if (!empty($this->settings["excluded_css"])) {
            $this->compileExclusionRegex($this->settings["excluded_css"]);
        }
    }

    public function initialize(): void
    {
        if (
            !$this->initialized &&
            !is_admin() &&
            ($this->settings["css_minify"] ?? false)
        ) {
            add_action("wp_enqueue_scripts", [$this, "processStyles"], 100);
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

        $excluded_css = $this->settings["excluded_css"] ?? [];

        foreach ($wp_styles->queue as $handle) {
            try {
                $this->processStyle($handle, $wp_styles, $excluded_css);
            } catch (\Throwable $e) {
                $this->logError("Failed to process style $handle", $e);
            }
        }
    }

    private function processStyle(
        string $handle,
        \WP_Styles $wp_styles,
        array $excluded_css,
    ): void {
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

        $size = filesize($source);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            return;
        }

        // Generate cache key based on handle + path + mtime + size (No content read)
        // Optimization: Avoid reading file content on every request if cache exists.
        $mtime = filemtime($source);
        $cache_key = $this->generateCacheKey(
            $handle . $source . $mtime . $size,
        );
        $cache_file = $this->getCacheFile($cache_key);

        if (!file_exists($cache_file)) {
            $content = file_get_contents($source);
            if ($content === false || empty(trim($content))) {
                return;
            }

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
        // Optimization: Use array buffer instead of php://memory stream for performance
        $buffer = [];

        $prevToken = null;
        $prevPrevToken = null;
        $calcDepth = 0; // Tracks if we are inside calc(), clamp(), var()
        $pendingSemicolon = false; // Deferred write buffer

        $parenStack = [];
        $lastClosedFunc = null;
        $whitespaceSkipped = false;

        foreach ($this->tokenize($css) as $token) {
            // 1. Comments
            if ($token["type"] === self::T_COMMENT) {
                // Preserve license comments (/*! ... */)
                if (str_starts_with($token["value"], "/*!")) {
                    // Flush pending semicolon before comment
                    if ($pendingSemicolon) {
                        $buffer[] = ";";
                        $pendingSemicolon = false;
                    }
                    $buffer[] = $token["value"];
                    $prevToken = ["type" => self::T_WHITESPACE, "value" => " "]; // Treat as break
                }
                continue;
            }

            // 2. Whitespace
            if ($token["type"] === self::T_WHITESPACE) {
                $whitespaceSkipped = true;
                continue; // We generate our own spaces later
            }

            // 3. Handle Pending Semicolon
            // If the current token is '}', we DROP the semicolon.
            if ($pendingSemicolon) {
                if ($token["type"] !== self::T_CLOSE) {
                    $buffer[] = ";";
                }
                $pendingSemicolon = false;
            }

            // 4. Semicolon Buffering
            if ($token["type"] === self::T_SEMICOLON) {
                $pendingSemicolon = true;
                continue;
            }

            // 5. Context Tracking
            if ($token["type"] === self::T_PAREN_OPEN) {
                $func = null;
                // Check if previous token was a function name
                if ($prevToken && $prevToken["type"] === self::T_WORD) {
                    $func = strtolower($prevToken["value"]);
                    if (isset(self::CALC_FUNCTIONS[$func])) {
                        $calcDepth++;
                    } elseif ($calcDepth > 0) {
                        $calcDepth++; // Nested parens inside calc
                    }
                } elseif ($calcDepth > 0) {
                    $calcDepth++;
                }
                $parenStack[] = $func;
            }
            if ($token["type"] === self::T_PAREN_CLOSE) {
                if ($calcDepth > 0) {
                    $calcDepth--;
                }
                $lastClosedFunc = array_pop($parenStack);
            }

            // 6. Optimization: Zero Units
            // Transform '0px' -> '0'. But NOT '0s', '0deg', or inside calc()
            if ($token["type"] === self::T_WORD && $calcDepth === 0) {
                // Optimization: Inline check to avoid function call overhead
                if (($token["value"][0] ?? '') === '0') {
                    $token["value"] = $this->optimizeZeroUnits($token["value"]);
                }
            }

            // 7. Optimization: Hex Colors
            if ($token["type"] === self::T_WORD) {
                // Optimization: Inline check to avoid function call overhead
                if (($token["value"][0] ?? '') === '#') {
                    $token["value"] = $this->compressHex($token["value"]);
                }
            }

            // 8. Insert Space Logic
            if (
                $prevToken &&
                $this->needsSpace(
                    $prevToken,
                    $token,
                    $calcDepth > 0,
                    $lastClosedFunc,
                    $prevPrevToken,
                    $whitespaceSkipped,
                )
            ) {
                $buffer[] = " ";
            }

            $buffer[] = $token["value"];
            $prevPrevToken = $prevToken;
            $prevToken = $token;
            $whitespaceSkipped = false;
        }

        // Write trailing semicolon if exists
        if ($pendingSemicolon) {
            $buffer[] = ";";
        }

        $result = implode("", $buffer);

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
                // Optimization: Use strspn to skip all whitespace at once (much faster than loop)
                $len_ws = strspn($css, " \t\n\r\v\f", $i);
                $i += $len_ws;
                yield ["type" => self::T_WHITESPACE, "value" => " "];
                continue;
            }

            // Strings (" or ')
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $start = $i;
                $i++;
                while ($i < $len) {
                    if ($css[$i] === "\\") {
                        $i += 2;
                        continue;
                    }
                    if ($css[$i] === $quote) {
                        $i++;
                        break;
                    }
                    if ($css[$i] === "\n") {
                        break;
                    }
                    $i++;
                }
                yield [
                    "type" => self::T_STRING,
                    "value" => substr($css, $start, $i - $start),
                ];
                continue;
            }

            // Comments (/* ... */)
            if ($char === "/" && ($css[$i + 1] ?? "") === "*") {
                $start = $i;
                $end = strpos($css, "*/", $i + 2);

                if ($end === false) {
                    $i = $len; // Unterminated comment
                } else {
                    $i = $end + 2;
                }

                yield [
                    "type" => self::T_COMMENT,
                    "value" => substr($css, $start, $i - $start),
                ];
                continue;
            }

            // Optimization: Use O(1) lookup instead of sequential IF checks and str_contains
            if (isset(self::TOKEN_MAP[$char])) {
                yield ["type" => self::TOKEN_MAP[$char], "value" => $char];
                $i++;
                continue;
            }

            // Words / Identifiers
            $start = $i;
            // Optimization: Use strcspn to skip over non-break characters

            while ($i < $len) {
                $len_chunk = strcspn($css, self::TOKENIZER_MASK, $i);
                $i += $len_chunk;

                if ($i >= $len) {
                    break;
                }

                $c = $css[$i];

                // If stopped at /, check if it's a comment start (/*)
                if ($c === "/") {
                    if (($css[$i + 1] ?? "") === "*") {
                        break; // Comment start detected, let main loop handle it
                    }
                    $i++; // Consume / as part of the word/identifier (e.g. url(/img.png))
                    continue;
                }

                // Any other match in mask is a break character
                break;
            }

            $val = substr($css, $start, $i - $start);
            yield ["type" => self::T_WORD, "value" => $val];
        }
    }

    private function needsSpace(
        array $prev,
        array $curr,
        bool $inCalc,
        ?string $lastClosedFunc = null,
        ?array $prevPrevToken = null,
        bool $whitespaceSkipped = false,
    ): bool {
        // 1. Inside calc(), spaces are REQUIRED around + and - operators
        if ($inCalc) {
            $val = $curr["value"];
            $pVal = $prev["value"];
            if ($val === "+" || $val === "-") {
                return true;
            }
            if ($pVal === "+" || $pVal === "-") {
                return true;
            }
        }

        $t1 = $prev["type"];
        $t2 = $curr["type"];

        // 2. Word + Word (margin: 10px 20px)
        if ($t1 === self::T_WORD && $t2 === self::T_WORD) {
            return true;
        }

        // 3. Variable/Function fusion fix
        if ($t1 === self::T_PAREN_CLOSE && $t2 === self::T_WORD) {
            // If whitespace was explicitly skipped, usually preserve it (space as combinator)
            if ($whitespaceSkipped) {
                return true;
            }

            // If NO whitespace was skipped, check if we need to force space

            // Selector pseudo-classes
            if (
                $lastClosedFunc &&
                isset(self::SELECTOR_PSEUDOS[$lastClosedFunc])
            ) {
                // In selector context, no input space means chained selector.
                // Do NOT insert space.
                return false;
            }

            // Default (Value context or unknown): Add space to be safe/fix fusion
            // E.g. var(--a)var(--b) -> var(--a) var(--b)
            return true;
        }

        // 4. Media Query "and ("
        if ($t1 === self::T_WORD && $t2 === self::T_PAREN_OPEN) {
            $kw = strtolower($prev["value"]);
            if ($kw === "and" || $kw === "or" || $kw === "not") {
                // Check if this is a pseudo-class like :not(
                if (
                    $kw === "not" &&
                    $prevPrevToken &&
                    $prevPrevToken["type"] === self::T_COLON
                ) {
                    return false;
                }
                return true;
            }
        }

        return false;
    }

    private function optimizeZeroUnits(string $val): string
    {
        if (!str_starts_with($val, "0")) {
            return $val;
        }
        if (preg_match('/^0([a-z%]+)$/i', $val, $matches)) {
            $unit = strtolower($matches[1]);
            // Protected units
            if (isset(self::PRESERVED_UNITS[$unit])) {
                return $val;
            }
            return "0";
        }
        return $val;
    }

    private function compressHex(string $val): string
    {
        if (empty($val) || $val[0] !== "#") {
            return $val;
        }
        $len = strlen($val);
        $val = strtolower($val);

        if ($len === 7) {
            // #aabbcc
            if (
                $val[1] === $val[2] &&
                $val[3] === $val[4] &&
                $val[5] === $val[6]
            ) {
                return "#" . $val[1] . $val[3] . $val[5];
            }
        } elseif ($len === 9) {
            // #aabbccdd
            if (
                $val[1] === $val[2] &&
                $val[3] === $val[4] &&
                $val[5] === $val[6] &&
                $val[7] === $val[8]
            ) {
                return "#" . $val[1] . $val[3] . $val[5] . $val[7];
            }
        }
        return $val;
    }

    // Helpers
    private function shouldProcessStyle(
        $style,
        string $handle,
        array $excluded_css,
    ): bool {
        if (!isset($style->src) || empty($style->src)) {
            return false;
        }
        $src = $style->src;
        return strpos($src, ".min.css") === false &&
            strpos($src, "//") !== 0 &&
            strpos($src, site_url()) !== false &&
            !in_array($handle, $excluded_css) &&
            !$this->isExcluded($src);
    }

    private function getSourcePath($style): ?string
    {
        if (!isset($style->src)) {
            return null;
        }
        $src = $style->src;
        if (strpos($src, "http") !== 0) {
            $src = site_url($src);
        }

        $path = str_replace(
            [site_url(), "wp-content"],
            [ABSPATH, "wp-content"],
            $src,
        );

        // Sentinel Fix: Prevent Path Traversal & Source Code Disclosure
        $realPath = realpath($path);
        if ($realPath === false || !str_starts_with($realPath, ABSPATH)) {
            return null;
        }

        // Sentinel Fix: Ensure strictly CSS extension
        if (pathinfo($realPath, PATHINFO_EXTENSION) !== "css") {
            return null;
        }

        return $realPath;
    }

    private function updateStyleRegistration($style, string $cache_file): void
    {
        if (!isset($style->src)) {
            return;
        }
        $style->src = str_replace(ABSPATH, site_url("/"), $cache_file);
        $style->ver = filemtime($cache_file);
    }

    private function getCacheFile(string $key): string
    {
        return $this->cache_dir . $key . ".css";
    }

    private function isExcluded(string $url): bool
    {
        if (empty($this->exclusionRegex)) {
            return false;
        }
        return preg_match($this->exclusionRegex, $url) === 1;
    }

    private function compileExclusionRegex(array $patterns): void
    {
        $regexParts = [];
        foreach ($patterns as $pattern) {
            if (empty(trim($pattern))) {
                continue;
            }
            // Convert glob pattern to regex
            $regex = preg_quote($pattern, "/");
            // Restore wildcards
            $regex = str_replace(["\*", "\?"], [".*", "."], $regex);
            // Anchor matching to ensure it matches the full string like fnmatch
            $regexParts[] = "^" . $regex . "$";
        }

        if (!empty($regexParts)) {
            // Optimization: Combine all checks into a single regex O(1)
            $this->exclusionRegex = "/" . implode("|", $regexParts) . "/i";
        }
    }
}
