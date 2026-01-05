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
    private const T_COMMENT = 1;
    private const T_STRING = 2;
    private const T_REGEX = 3;
    private const T_OPERATOR = 4;
    private const T_WORD = 5; // Identifiers, Keywords, Numbers, Booleans
    private const T_TEMPLATE = 6;

    // Mask: Whitespace + Special Chars + Operators
    // Used for strcspn optimization in tokenizer
    private const TOKENIZER_MASK = " \t\n\r\v\f/\"'`{}()[],:;?^~.!<>=+-*%&|";

    // Optimization: O(1) lookup for single-character operators
    private const SINGLE_CHAR_OPERATORS = [
        "{" => true, "}" => true, "(" => true, ")" => true, "[" => true, "]" => true,
        "," => true, ":" => true, ";" => true, "?" => true, "^" => true, "~" => true,
    ];

    // Optimization: O(1) lookup for characters that start complex operators
    private const COMPLEX_OPERATORS_START = [
        "." => true, "!" => true, "<" => true, ">" => true, "=" => true,
        "+" => true, "-" => true, "*" => true, "%" => true, "&" => true, "|" => true,
    ];

    // Optimization: Pre-computed hash maps for O(1) lookup
    private const REGEX_START_KEYWORDS = [
        "case" => true,
        "else" => true,
        "return" => true,
        "throw" => true,
        "typeof" => true,
        "void" => true,
        "delete" => true,
        "do" => true,
        "await" => true,
        "yield" => true,
        "if" => true,
        "while" => true,
        "for" => true,
        "in" => true,
        "instanceof" => true,
        "new" => true,
        "export" => true,
    ];

    private const KEYWORDS_EXPECT_CONTINUATION = [
        "if" => true,
        "else" => true,
        "for" => true,
        "while" => true,
        "do" => true,
        "switch" => true,
        "case" => true,
        "default" => true,
        "try" => true,
        "catch" => true,
        "finally" => true,
        "with" => true,
        "var" => true,
        "let" => true,
        "const" => true,
        "function" => true,
        "class" => true,
        "new" => true,
        "import" => true,
        "export" => true,
        "extends" => true,
        "instanceof" => true,
        "typeof" => true,
        "void" => true,
        "delete" => true,
    ];

    private const OPERATORS_END_STATEMENT = [
        "}" => true,
        "]" => true,
        ")" => true,
        "++" => true,
        "--" => true,
    ];

    private const STATEMENT_START_EXCEPTIONS = [
        "else" => true,
        "catch" => true,
        "finally" => true,
        "instanceof" => true,
        "in" => true,
    ];

    private const OPERATORS_START_STATEMENT = [
        "!" => true,
        "~" => true,
        "{" => true,
        "++" => true,
        "--" => true,
    ];

    private const KEYWORDS_RETURN_LIKE = [
        "return" => true,
        "throw" => true,
        "break" => true,
        "continue" => true,
        "yield" => true,
    ];

    private const SAFE_TERMINATORS = [
        ";" => true,
        "}" => true,
        ":" => true,
    ];

    private string $cache_dir;
    private string $exclusionRegex = "";

    public function __construct()
    {
        parent::__construct();
        $this->cache_dir = WPSC_CACHE_DIR . "js/";
        // Optimization: Removed ensureDirectory here. atomicWrite handles it.

        if (!empty($this->settings["excluded_js"])) {
            $this->compileExclusionRegex($this->settings["excluded_js"]);
        }
    }

    public function initialize(): void
    {
        if (
            !$this->initialized &&
            !is_admin() &&
            ($this->settings["js_minify"] ?? false)
        ) {
            add_action("wp_enqueue_scripts", [$this, "processScripts"], 100);
            $this->initialized = true;
        }
    }

    public function isConnected(): bool
    {
        return is_dir($this->cache_dir) && is_writable($this->cache_dir);
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
        $this->recursiveDelete($this->cache_dir);
    }

    public function processScripts(): void
    {
        global $wp_scripts;
        if (empty($wp_scripts->queue)) {
            return;
        }

        $excluded_js = $this->settings["excluded_js"] ?? [];

        foreach ($wp_scripts->queue as $handle) {
            try {
                $this->processScript($handle, $wp_scripts, $excluded_js);
            } catch (\Throwable $e) {
                $this->logError("Failed to process script $handle", $e);
            }
        }
    }

    private function processScript(
        string $handle,
        \WP_Scripts $wp_scripts,
        array $excluded_js,
    ): void {
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

        $mtime = @filemtime($source);
        $size = @filesize($source);

        if ($mtime === false || $size === false) {
            return;
        }

        // Optimization: Generate cache key based on metadata to avoid reading content
        $cache_key = $this->generateCacheKey(
            $handle . $source . $mtime . $size,
        );
        $cache_file = $this->getCacheFile($cache_key);

        if (!file_exists($cache_file)) {
            $content = @file_get_contents($source);
            if ($content === false || empty(trim($content))) {
                return;
            }

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
        $output = fopen("php://memory", "r+");
        $prevToken = null;

        $iterator = $this->tokenize($js);
        $currToken = $iterator->current();

        while ($currToken !== null) {
            $iterator->next();
            $nextToken = $iterator->valid() ? $iterator->current() : null;

            // 1. Handle Comments: Skip them
            if ($currToken["type"] === self::T_COMMENT) {
                if (str_starts_with($currToken["value"], "/*!")) {
                    fwrite($output, $currToken["value"] . "\n");
                    $prevToken = [
                        "type" => self::T_WHITESPACE,
                        "value" => "\n",
                    ];
                }
                $currToken = $nextToken;
                continue;
            }

            // 2. Handle Whitespace
            if ($currToken["type"] === self::T_WHITESPACE) {
                $hasNewline =
                    str_contains($currToken["value"], "\n") ||
                    str_contains($currToken["value"], "\r");

                if ($prevToken && $nextToken && $hasNewline) {
                    // 2a. ASI Protection: Preserve Newline if strictly necessary for syntax (e.g. return \n val)
                    if (
                        $this->needsNewlineProtection(
                            $prevToken,
                            $nextToken,
                            $currToken["value"],
                        )
                    ) {
                        fwrite($output, "\n");
                        $prevToken = [
                            "type" => self::T_WHITESPACE,
                            "value" => "\n",
                        ];
                        $currToken = $nextToken;
                        continue;
                    }

                    // 2b. MISSING SEMICOLON FIX
                    if ($this->shouldInsertSemicolon($prevToken, $nextToken)) {
                        fwrite($output, ";");
                        $prevToken = [
                            "type" => self::T_OPERATOR,
                            "value" => ";",
                        ];
                        $currToken = $nextToken;
                        continue;
                    }
                }

                $currToken = $nextToken;
                continue;
            }

            // 3. Handle Insertion of necessary spaces (e.g. "var x")
            if ($prevToken && $this->needsSpace($prevToken, $currToken)) {
                fwrite($output, " ");
            }

            fwrite($output, $currToken["value"]);
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
                // Optimization: Use strspn to skip all whitespace at once (much faster than loop)
                $len_ws = strspn($js, " \t\n\r\v\f", $i);
                $limit = $i + $len_ws;

                // Optimization: Avoid substr allocation for whitespace.
                // We only need to know if it contains a newline for ASI.
                $hasNewline = false;

                // Check for newline in the chunk without allocating substring
                $p = strpos($js, "\n", $i);
                if ($p !== false && $p < $limit) {
                    $hasNewline = true;
                } else {
                    $p = strpos($js, "\r", $i);
                    if ($p !== false && $p < $limit) {
                        $hasNewline = true;
                    }
                }

                $i = $limit;
                yield [
                    "type" => self::T_WHITESPACE,
                    "value" => $hasNewline ? "\n" : " ",
                ];
                continue;
            }

            // Comments / Regex / Division
            if ($char === "/") {
                $next = $js[$i + 1] ?? "";

                if ($next === "/") {
                    // Line Comment
                    $start = $i;
                    $i += 2;
                    while ($i < $len && $js[$i] !== "\n" && $js[$i] !== "\r") {
                        $i++;
                    }
                    yield [
                        "type" => self::T_COMMENT,
                        "value" => substr($js, $start, $i - $start),
                    ];
                    continue;
                }
                if ($next === "*") {
                    // Block Comment
                    $start = $i;
                    $i += 2;
                    while ($i < $len - 1) {
                        if ($js[$i] === "*" && $js[$i + 1] === "/") {
                            $i += 2;
                            break;
                        }
                        $i++;
                    }
                    yield [
                        "type" => self::T_COMMENT,
                        "value" => substr($js, $start, $i - $start),
                    ];
                    continue;
                }

                // Regex Literal Detection
                if ($this->isRegexStart($lastMeaningfulToken)) {
                    $start = $i;
                    $i++;
                    $inClass = false;

                    while ($i < $len) {
                        // Optimization: Use strcspn to skip safe characters
                        // If in class [...], we look for ] or \ or newline
                        // If not in class, we look for / or [ or \ or newline
                        $mask = $inClass ? "\\\n\r]" : "\\\n\r/[";
                        $len_chunk = strcspn($js, $mask, $i);
                        $i += $len_chunk;

                        if ($i >= $len) {
                            break;
                        }

                        $c = $js[$i];

                        if ($c === "\\") {
                            $i += 2;
                            continue;
                        }

                        if ($c === "[") {
                            $inClass = true;
                            $i++;
                            continue;
                        }

                        if ($c === "]") {
                            $inClass = false;
                            $i++;
                            continue;
                        }

                        if ($c === "/") {
                            // End of regex (guaranteed !inClass by mask logic)
                            $i++;
                            // Consume flags
                            while ($i < $len && ctype_alpha($js[$i])) {
                                $i++;
                            }
                            break;
                        }

                        // Newline means invalid regex literal
                        break;
                    }

                    yield ($lastMeaningfulToken = [
                        "type" => self::T_REGEX,
                        "value" => substr($js, $start, $i - $start),
                    ]);
                    continue;
                }

                yield ($lastMeaningfulToken = [
                    "type" => self::T_OPERATOR,
                    "value" => "/",
                ]);
                $i++;
                continue;
            }

            // Strings
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $start = $i;
                $i++;
                $mask = "\\\n\r" . $quote;

                while ($i < $len) {
                    $len_chunk = strcspn($js, $mask, $i);
                    $i += $len_chunk;

                    if ($i >= $len) {
                        break;
                    }

                    $c = $js[$i];

                    if ($c === $quote) {
                        $i++;
                        break;
                    }
                    if ($c === "\\") {
                        $i += 2;
                        continue;
                    }

                    // Newline means unterminated string
                    break;
                }
                yield ($lastMeaningfulToken = [
                    "type" => self::T_STRING,
                    "value" => substr($js, $start, $i - $start),
                ]);
                continue;
            }

            // Template Literals
            if ($char === "`") {
                $start = $i;
                $i++;
                $mask = "\\`";

                while ($i < $len) {
                    $len_chunk = strcspn($js, $mask, $i);
                    $i += $len_chunk;

                    if ($i >= $len) {
                        break;
                    }

                    $c = $js[$i];

                    if ($c === "`") {
                        $i++;
                        break;
                    }
                    if ($c === "\\") {
                        $i += 2;
                        continue;
                    }
                }
                yield ($lastMeaningfulToken = [
                    "type" => self::T_TEMPLATE,
                    "value" => substr($js, $start, $i - $start),
                ]);
                continue;
            }

            // Operators
            // Optimization: Use O(1) lookup instead of O(N) str_contains
            if (isset(self::SINGLE_CHAR_OPERATORS[$char])) {
                yield ($lastMeaningfulToken = [
                    "type" => self::T_OPERATOR,
                    "value" => $char,
                ]);
                $i++;
                continue;
            }
            if (isset(self::COMPLEX_OPERATORS_START[$char])) {
                $start = $i;
                // Optimization: Use strspn to match consecutive operator characters at once
                $len_op = strspn($js, ".!<>=+-*%&|", $i);
                $i += $len_op;
                yield ($lastMeaningfulToken = [
                    "type" => self::T_OPERATOR,
                    "value" => substr($js, $start, $len_op),
                ]);
                continue;
            }

            // Words
            $start = $i;
            // Optimization: Use strcspn to skip over non-break characters

            while ($i < $len) {
                $len_chunk = strcspn($js, self::TOKENIZER_MASK, $i);
                $i += $len_chunk;

                if ($i >= $len) {
                    break;
                }

                $c = $js[$i];

                // Handle decimal number case: 1.5
                if ($c === ".") {
                    if (
                        $i > $start &&
                        ctype_digit($js[$i - 1]) &&
                        isset($js[$i + 1]) &&
                        ctype_digit($js[$i + 1])
                    ) {
                        $i++;
                        continue; // decimal number
                    }
                }

                break;
            }

            $isProperty = false;
            if (
                $lastMeaningfulToken !== null &&
                $lastMeaningfulToken["type"] === self::T_OPERATOR &&
                $lastMeaningfulToken["value"] === "."
            ) {
                $isProperty = true;
            }

            yield ($lastMeaningfulToken = [
                "type" => self::T_WORD,
                "value" => substr($js, $start, $i - $start),
                "is_property" => $isProperty,
            ]);
        }
    }

    private function isRegexStart(?array $lastToken): bool
    {
        if ($lastToken === null) {
            return true;
        }
        $val = $lastToken["value"];
        if ($val === ")" || $val === "]") {
            return false;
        }
        if ($lastToken["type"] === self::T_OPERATOR) {
            if ($val === "++" || $val === "--") {
                return false;
            }
            return true;
        }
        if ($lastToken["type"] !== self::T_WORD) {
            return false;
        }
        if (!empty($lastToken["is_property"])) {
            return false;
        }

        return isset(self::REGEX_START_KEYWORDS[$val]);
    }

    private function needsSpace(array $prev, array $curr): bool
    {
        if ($prev["type"] === self::T_WORD && $curr["type"] === self::T_WORD) {
            return true;
        }
        if (
            $prev["type"] === self::T_WORD &&
            is_numeric($prev["value"][0]) &&
            $curr["value"] === "."
        ) {
            return true;
        }
        if (
            $prev["type"] === self::T_OPERATOR &&
            $curr["type"] === self::T_OPERATOR
        ) {
            if (
                ($prev["value"] === "+" &&
                    str_starts_with($curr["value"], "+")) ||
                ($prev["value"] === "-" && str_starts_with($curr["value"], "-"))
            ) {
                return true;
            }
        }
        return false;
    }

    private function shouldInsertSemicolon(array $prev, array $next): bool
    {
        // Check if previous token can end a statement
        $canEndStatement = false;
        if ($prev["type"] === self::T_WORD) {
            // Exclude keywords that expect a continuation
            if (!isset(self::KEYWORDS_EXPECT_CONTINUATION[$prev["value"]])) {
                $canEndStatement = true;
            }
        } elseif (
            $prev["type"] === self::T_STRING ||
            $prev["type"] === self::T_REGEX ||
            $prev["type"] === self::T_TEMPLATE
        ) {
            $canEndStatement = true;
        } elseif ($prev["type"] === self::T_OPERATOR) {
            if (isset(self::OPERATORS_END_STATEMENT[$prev["value"]])) {
                $canEndStatement = true;
            }
        }

        if (!$canEndStatement) {
            return false;
        }

        // Special Case: Don't insert semicolon between ) and { (e.g. if(x){...})
        if ($prev["value"] === ")" && $next["value"] === "{") {
            return false;
        }

        // Check if next token starts a new statement or needs separation
        if ($next["type"] === self::T_WORD) {
            if ($next["value"] === "while" && $prev["value"] === "}") {
                return false;
            }
            return !isset(self::STATEMENT_START_EXCEPTIONS[$next["value"]]);
        }

        if (
            $next["type"] === self::T_STRING ||
            $next["type"] === self::T_REGEX ||
            $next["type"] === self::T_TEMPLATE
        ) {
            return true;
        }

        if ($next["type"] === self::T_OPERATOR) {
            return isset(self::OPERATORS_START_STATEMENT[$next["value"]]);
        }

        return false;
    }

    private function needsNewlineProtection(
        array $prev,
        array $next,
        string $whitespace,
    ): bool {
        if (
            !str_contains($whitespace, "\n") &&
            !str_contains($whitespace, "\r")
        ) {
            return false;
        }
        if ($prev["type"] === self::T_WORD) {
            if (isset(self::KEYWORDS_RETURN_LIKE[$prev["value"]])) {
                return true;
            }
        }
        if (
            $next["type"] === self::T_OPERATOR ||
            $next["type"] === self::T_TEMPLATE
        ) {
            $val = $next["value"];
            if (
                $val === "[" ||
                $val === "(" ||
                $val === "`" ||
                $val === "+" ||
                $val === "-" ||
                $val === "/"
            ) {
                if (!isset(self::SAFE_TERMINATORS[$prev["value"]])) {
                    return true;
                }
            }
        }
        return false;
    }

    // --- Helpers ---
    private function shouldProcessScript(
        $script,
        string $handle,
        array $excluded_js,
    ): bool {
        if (!isset($script->src) || empty($script->src)) {
            return false;
        }
        $src = $script->src;
        return strpos($src, ".min.js") === false &&
            strpos($src, "//") !== 0 &&
            strpos($src, site_url()) !== false &&
            !in_array($handle, $excluded_js) &&
            !$this->isExcluded($src);
    }

    private function getSourcePath($script): ?string
    {
        if (!isset($script->src)) {
            return null;
        }
        $src = $script->src;
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

        // Sentinel Fix: Ensure strictly JS extension (prevents reading .php files)
        if (pathinfo($realPath, PATHINFO_EXTENSION) !== "js") {
            return null;
        }

        return $realPath;
    }

    private function isValidSource(?string $source): bool
    {
        return $source &&
            is_readable($source) &&
            filesize($source) <= self::MAX_FILE_SIZE;
    }

    private function updateScriptRegistration($script, string $cache_file): void
    {
        if (!isset($script->src)) {
            return;
        }
        $script->src = str_replace(ABSPATH, site_url("/"), $cache_file);
        $script->ver = filemtime($cache_file);
    }

    private function getCacheFile(string $key): string
    {
        return $this->cache_dir . $key . ".js";
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
