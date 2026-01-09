<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

final class MinifyJS extends AbstractCacheDriver
{
    private const MAX_FILE_SIZE = 1024 * 1024;
    private const T_WHITESPACE = 0;
    private const T_COMMENT = 1;
    private const T_STRING = 2;
    private const T_REGEX = 3;
    private const T_OPERATOR = 4;
    private const T_WORD = 5;
    private const T_TEMPLATE = 6;
    private const TOKENIZER_MASK = " \t\n\r\v\f/\"'`{}()[],:;?^~.!<>=+-*%&|";
    private const SINGLE_CHAR_OPERATORS = [
        "{" => true,
        "}" => true,
        "(" => true,
        ")" => true,
        "[" => true,
        "]" => true,
        "," => true,
        ":" => true,
        ";" => true,
        "?" => true,
        "^" => true,
        "~" => true,
    ];
    private const COMPLEX_OPERATORS_START = [
        "." => true,
        "!" => true,
        "<" => true,
        ">" => true,
        "=" => true,
        "+" => true,
        "-" => true,
        "*" => true,
        "%" => true,
        "&" => true,
        "|" => true,
    ];
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
    private const SAFE_TERMINATORS = [";" => true, "}" => true, ":" => true];

    private string $cache_dir;
    private string $exclusionRegex = "";

    public function __construct()
    {
        parent::__construct();
        $this->cache_dir = WPSC_CACHE_DIR . "js/";
        // Changed key
        if (!empty($this->settings["excluded_js_minify"])) {
            $this->compileExclusionRegex($this->settings["excluded_js_minify"]);
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
        $this->atomicWrite($this->getCacheFile($key), $value);
    }
    public function delete(string $key): void
    {
        @unlink($this->getCacheFile($key));
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
        // Changed key
        $excluded = $this->settings["excluded_js_minify"] ?? [];

        foreach ($wp_scripts->queue as $handle) {
            try {
                $this->processScript($handle, $wp_scripts, $excluded);
            } catch (\Throwable $e) {
            }
        }
    }

    private function processScript(
        string $handle,
        \WP_Scripts $wp_scripts,
        array $excluded,
    ): void {
        if (!isset($wp_scripts->registered[$handle])) {
            return;
        }
        $script = $wp_scripts->registered[$handle];
        if (!$this->shouldProcessScript($script, $handle, $excluded)) {
            return;
        }

        $source = $this->getSourcePath($script);
        if (
            !$source ||
            !is_readable($source) ||
            filesize($source) > self::MAX_FILE_SIZE
        ) {
            return;
        }

        $cache_key = $this->generateCacheKey(
            $handle . $source . filemtime($source),
        );
        $cache_file = $this->getCacheFile($cache_key);

        if (!file_exists($cache_file)) {
            $content = @file_get_contents($source);
            if ($content) {
                $this->set($cache_key, $this->minifyJS($content));
            }
        }
        if (file_exists($cache_file)) {
            $this->updateScriptRegistration($script, $cache_file);
        }
    }

    private function minifyJS(string $js): string
    {
        $buffer = [];
        $prevToken = null;
        $iterator = $this->tokenize($js);
        $currToken = $iterator->current();

        while ($currToken !== null) {
            $iterator->next();
            $nextToken = $iterator->valid() ? $iterator->current() : null;

            if ($currToken["type"] === self::T_COMMENT) {
                if (str_starts_with($currToken["value"], "/*!")) {
                    $buffer[] = $currToken["value"] . "\n";
                    $prevToken = [
                        "type" => self::T_WHITESPACE,
                        "value" => "\n",
                    ];
                }
                $currToken = $nextToken;
                continue;
            }
            if ($currToken["type"] === self::T_WHITESPACE) {
                $hasNewline =
                    str_contains($currToken["value"], "\n") ||
                    str_contains($currToken["value"], "\r");
                if ($prevToken && $nextToken && $hasNewline) {
                    if (
                        $this->needsNewlineProtection(
                            $prevToken,
                            $nextToken,
                            $currToken["value"],
                        )
                    ) {
                        $buffer[] = "\n";
                        $prevToken = [
                            "type" => self::T_WHITESPACE,
                            "value" => "\n",
                        ];
                        $currToken = $nextToken;
                        continue;
                    }
                    if ($this->shouldInsertSemicolon($prevToken, $nextToken)) {
                        $buffer[] = ";";
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
            if ($prevToken && $this->needsSpace($prevToken, $currToken)) {
                $buffer[] = " ";
            }
            $buffer[] = $currToken["value"];
            $prevToken = $currToken;
            $currToken = $nextToken;
        }
        return implode("", $buffer) ?: $js;
    }

    private function tokenize(string $js): \Generator
    {
        $len = strlen($js);
        $i = 0;
        $lastMeaningfulToken = null;

        while ($i < $len) {
            $char = $js[$i];
            if (ctype_space($char)) {
                $start = $i;
                $i += strspn($js, " \t\n\r\v\f", $i);
                $hasNewline = false;
                $p = strpos($js, "\n", $start);
                if ($p !== false && $p < $i) {
                    $hasNewline = true;
                } else {
                    $p = strpos($js, "\r", $start);
                    if ($p !== false && $p < $i) {
                        $hasNewline = true;
                    }
                }
                yield [
                    "type" => self::T_WHITESPACE,
                    "value" => $hasNewline ? "\n" : " ",
                ];
                continue;
            }
            if ($char === "/") {
                $next = $js[$i + 1] ?? "";
                if ($next === "/" || $next === "*") {
                    $start = $i;
                    $i += 2;
                    if ($next === "/") {
                        while (
                            $i < $len &&
                            $js[$i] !== "\n" &&
                            $js[$i] !== "\r"
                        ) {
                            $i++;
                        }
                    } else {
                        while ($i < $len - 1) {
                            if ($js[$i] === "*" && $js[$i + 1] === "/") {
                                $i += 2;
                                break;
                            }
                            $i++;
                        }
                    }
                    yield [
                        "type" => self::T_COMMENT,
                        "value" => substr($js, $start, $i - $start),
                    ];
                    continue;
                }
                if ($this->isRegexStart($lastMeaningfulToken)) {
                    $start = $i;
                    $i++;
                    $inClass = false;
                    while ($i < $len) {
                        $len_chunk = strcspn(
                            $js,
                            $inClass ? "\\\n\r]" : "\\\n\r/[",
                            $i,
                        );
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
                            $i++;
                            while ($i < $len && ctype_alpha($js[$i])) {
                                $i++;
                            }
                            break;
                        }
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
            if ($char === '"' || $char === "'") {
                $start = $i++;
                $mask = "\\\n\r" . $char;
                while ($i < $len) {
                    $i += strcspn($js, $mask, $i);
                    if ($i >= $len) {
                        break;
                    }
                    $c = $js[$i];
                    if ($c === $char) {
                        $i++;
                        break;
                    }
                    if ($c === "\\") {
                        $i += 2;
                        continue;
                    }
                    break;
                }
                yield ($lastMeaningfulToken = [
                    "type" => self::T_STRING,
                    "value" => substr($js, $start, $i - $start),
                ]);
                continue;
            }
            if ($char === "`") {
                $start = $i++;
                $mask = "\\`";
                while ($i < $len) {
                    $i += strcspn($js, $mask, $i);
                    if ($i >= $len) {
                        break;
                    }
                    if ($js[$i] === "`") {
                        $i++;
                        break;
                    }
                    $i += 2;
                }
                yield ($lastMeaningfulToken = [
                    "type" => self::T_TEMPLATE,
                    "value" => substr($js, $start, $i - $start),
                ]);
                continue;
            }
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
                $i += strspn($js, ".!<>=+-*%&|", $i);
                yield ($lastMeaningfulToken = [
                    "type" => self::T_OPERATOR,
                    "value" => substr($js, $start, $i - $start),
                ]);
                continue;
            }
            $start = $i;
            while ($i < $len) {
                $i += strcspn($js, self::TOKENIZER_MASK, $i);
                if ($i >= $len) {
                    break;
                }
                if ($js[$i] === ".") {
                    if (
                        $i > $start &&
                        ctype_digit($js[$i - 1]) &&
                        isset($js[$i + 1]) &&
                        ctype_digit($js[$i + 1])
                    ) {
                        $i++;
                        continue;
                    }
                }
                break;
            }
            $isProp =
                $lastMeaningfulToken !== null &&
                $lastMeaningfulToken["type"] === self::T_OPERATOR &&
                $lastMeaningfulToken["value"] === ".";
            yield ($lastMeaningfulToken = [
                "type" => self::T_WORD,
                "value" => substr($js, $start, $i - $start),
                "is_property" => $isProp,
            ]);
        }
    }

    private function isRegexStart(?array $last): bool
    {
        if ($last === null) {
            return true;
        }
        if ($last["value"] === ")" || $last["value"] === "]") {
            return false;
        }
        if ($last["type"] === self::T_OPERATOR) {
            return $last["value"] !== "++" && $last["value"] !== "--";
        }
        if ($last["type"] !== self::T_WORD || !empty($last["is_property"])) {
            return false;
        }
        return isset(self::REGEX_START_KEYWORDS[$last["value"]]);
    }
    private function needsSpace(array $p, array $c): bool
    {
        if ($p["type"] === self::T_WORD && $c["type"] === self::T_WORD) {
            return true;
        }
        if (
            $p["type"] === self::T_WORD &&
            is_numeric($p["value"][0]) &&
            $c["value"] === "."
        ) {
            return true;
        }
        if (
            $p["type"] === self::T_OPERATOR &&
            $c["type"] === self::T_OPERATOR
        ) {
            if (
                ($p["value"] === "+" && str_starts_with($c["value"], "+")) ||
                ($p["value"] === "-" && str_starts_with($c["value"], "-"))
            ) {
                return true;
            }
        }
        return false;
    }
    private function shouldInsertSemicolon(array $p, array $n): bool
    {
        $canEnd = false;
        if ($p["type"] === self::T_WORD) {
            if (!isset(self::KEYWORDS_EXPECT_CONTINUATION[$p["value"]])) {
                $canEnd = true;
            }
        } elseif (
            in_array($p["type"], [
                self::T_STRING,
                self::T_REGEX,
                self::T_TEMPLATE,
            ])
        ) {
            $canEnd = true;
        } elseif (
            $p["type"] === self::T_OPERATOR &&
            isset(self::OPERATORS_END_STATEMENT[$p["value"]])
        ) {
            $canEnd = true;
        }
        if (!$canEnd) {
            return false;
        }
        if ($p["value"] === ")" && $n["value"] === "{") {
            return false;
        }
        if ($n["type"] === self::T_WORD) {
            if ($n["value"] === "while" && $p["value"] === "}") {
                return false;
            }
            return !isset(self::STATEMENT_START_EXCEPTIONS[$n["value"]]);
        }
        if (
            in_array($n["type"], [
                self::T_STRING,
                self::T_REGEX,
                self::T_TEMPLATE,
            ])
        ) {
            return true;
        }
        if ($n["type"] === self::T_OPERATOR) {
            return isset(self::OPERATORS_START_STATEMENT[$n["value"]]);
        }
        return false;
    }
    private function needsNewlineProtection(
        array $p,
        array $n,
        string $ws,
    ): bool {
        if (!str_contains($ws, "\n") && !str_contains($ws, "\r")) {
            return false;
        }
        if (
            $p["type"] === self::T_WORD &&
            isset(self::KEYWORDS_RETURN_LIKE[$p["value"]])
        ) {
            return true;
        }
        if (
            $n["type"] === self::T_OPERATOR ||
            $n["type"] === self::T_TEMPLATE
        ) {
            if (
                in_array($n["value"], ["[", "(", "`", "+", "-", "/"]) &&
                !isset(self::SAFE_TERMINATORS[$p["value"]])
            ) {
                return true;
            }
        }
        return false;
    }
    private function shouldProcessScript($script, string $h, array $ex): bool
    {
        if (!isset($script->src) || empty($script->src)) {
            return false;
        }
        $src = $script->src;
        return strpos($src, ".min.js") === false &&
            strpos($src, site_url()) !== false &&
            !in_array($h, $ex) &&
            !$this->isExcluded($src);
    }
    private function getSourcePath($script): ?string
    {
        if (!isset($script->src)) {
            return null;
        }
        $path = str_replace(
            [site_url(), "wp-content"],
            [ABSPATH, "wp-content"],
            $script->src,
        );
        if (
            ($real = realpath($path)) &&
            str_starts_with($real, ABSPATH) &&
            pathinfo($real, PATHINFO_EXTENSION) === "js"
        ) {
            return $real;
        }
        return null;
    }
    private function updateScriptRegistration($script, string $file): void
    {
        $script->src = str_replace(ABSPATH, site_url("/"), $file);
        $script->ver = filemtime($file);
    }
    private function getCacheFile(string $key): string
    {
        return $this->cache_dir . $key . ".js";
    }
    private function isExcluded(string $url): bool
    {
        return !empty($this->exclusionRegex) &&
            preg_match($this->exclusionRegex, $url) === 1;
    }
    private function compileExclusionRegex(array $p): void
    {
        $p = array_unique($p);
        $r = [];
        foreach ($p as $s) {
            if (trim($s)) {
                $r[] =
                    "^" .
                    str_replace(
                        ["\*", "\?"],
                        [".*", "."],
                        preg_quote($s, "/"),
                    ) .
                    "$";
            }
        }
        if ($r) {
            $this->exclusionRegex = "/" . implode("|", $r) . "/i";
        }
    }
}
