<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

final class MinifyCSS extends AbstractCacheDriver
{
    private const MAX_FILE_SIZE = 1024 * 1024;
    private const T_WHITESPACE = 0;
    private const T_COMMENT = 1;
    private const T_STRING = 2;
    private const T_OPEN = 3;
    private const T_CLOSE = 4;
    private const T_COLON = 5;
    private const T_SEMICOLON = 6;
    private const T_PAREN_OPEN = 7;
    private const T_PAREN_CLOSE = 8;
    private const T_OPERATOR = 9;
    private const T_WORD = 10;
    private const TOKENIZER_MASK = " \t\n\r\v\f{}():;,'\">+~/";
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
        "dir" => true,
        "lang" => true,
        "host" => true,
        "host-context" => true,
        "part" => true,
        "slotted" => true,
        "matches" => true,
        "cue" => true,
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
        // Changed key: excluded_css_minify
        if (!empty($this->settings["excluded_css_minify"])) {
            $this->compileExclusionRegex(
                $this->settings["excluded_css_minify"],
            );
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

    public function processStyles(): void
    {
        global $wp_styles;
        if (empty($wp_styles->queue)) {
            return;
        }
        // Changed key
        $excluded = $this->settings["excluded_css_minify"] ?? [];

        foreach ($wp_styles->queue as $handle) {
            try {
                $this->processStyle($handle, $wp_styles, $excluded);
            } catch (\Throwable $e) {
            }
        }
    }

    private function processStyle(
        string $handle,
        \WP_Styles $wp_styles,
        array $excluded,
    ): void {
        if (!isset($wp_styles->registered[$handle])) {
            return;
        }
        $style = $wp_styles->registered[$handle];
        if (!$this->shouldProcessStyle($style, $handle, $excluded)) {
            return;
        }

        $source = $this->getSourcePath($style);
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
            $content = file_get_contents($source);
            if ($content) {
                $this->set($cache_key, $this->minifyCSS($content));
            }
        }
        if (file_exists($cache_file)) {
            $this->updateStyleRegistration($style, $cache_file);
        }
    }

    private function minifyCSS(string $css): string
    {
        $buffer = [];
        $prevToken = null;
        $prevPrevToken = null;
        $calcDepth = 0;
        $pendingSemicolon = false;
        $parenStack = [];
        $lastClosedFunc = null;
        $whitespaceSkipped = false;

        foreach ($this->tokenize($css) as $token) {
            if ($token["type"] === self::T_COMMENT) {
                if (str_starts_with($token["value"], "/*!")) {
                    if ($pendingSemicolon) {
                        $buffer[] = ";";
                        $pendingSemicolon = false;
                    }
                    $buffer[] = $token["value"];
                    $prevToken = ["type" => self::T_WHITESPACE, "value" => " "];
                }
                continue;
            }
            if ($token["type"] === self::T_WHITESPACE) {
                $whitespaceSkipped = true;
                continue;
            }
            if ($pendingSemicolon) {
                if ($token["type"] !== self::T_CLOSE) {
                    $buffer[] = ";";
                }
                $pendingSemicolon = false;
            }
            if ($token["type"] === self::T_SEMICOLON) {
                $pendingSemicolon = true;
                continue;
            }

            if ($token["type"] === self::T_PAREN_OPEN) {
                $func = null;
                if ($prevToken && $prevToken["type"] === self::T_WORD) {
                    $func = strtolower($prevToken["value"]);
                    if (isset(self::CALC_FUNCTIONS[$func])) {
                        $calcDepth++;
                    } elseif ($calcDepth > 0) {
                        $calcDepth++;
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
            if (
                $token["type"] === self::T_WORD &&
                $calcDepth === 0 &&
                ($token["value"][0] ?? "") === "0"
            ) {
                $token["value"] = $this->optimizeZeroUnits($token["value"]);
            }
            if (
                $token["type"] === self::T_WORD &&
                ($token["value"][0] ?? "") === "#"
            ) {
                $token["value"] = $this->compressHex($token["value"]);
            }
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
        if ($pendingSemicolon) {
            $buffer[] = ";";
        }
        return implode("", $buffer) ?: $css;
    }

    private function tokenize(string $css): \Generator
    {
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            $char = $css[$i];
            if (ctype_space($char)) {
                $i += strspn($css, " \t\n\r\v\f", $i);
                yield ["type" => self::T_WHITESPACE, "value" => " "];
                continue;
            }
            if ($char === '"' || $char === "'") {
                $start = $i++;
                $mask = "\\\n" . $char;
                while ($i < $len) {
                    $i += strcspn($css, $mask, $i);
                    if ($i >= $len) {
                        break;
                    }
                    $c = $css[$i];
                    if ($c === $char) {
                        $i++;
                        break;
                    }
                    if ($c === "\\") {
                        $i += 2;
                        continue;
                    }
                    if ($c === "\n") {
                        break;
                    }
                }
                yield [
                    "type" => self::T_STRING,
                    "value" => substr($css, $start, $i - $start),
                ];
                continue;
            }
            if ($char === "/" && ($css[$i + 1] ?? "") === "*") {
                $start = $i;
                $end = strpos($css, "*/", $i + 2);
                $i = $end === false ? $len : $end + 2;
                yield [
                    "type" => self::T_COMMENT,
                    "value" => substr($css, $start, $i - $start),
                ];
                continue;
            }
            if (isset(self::TOKEN_MAP[$char])) {
                yield ["type" => self::TOKEN_MAP[$char], "value" => $char];
                $i++;
                continue;
            }
            $start = $i;
            while ($i < $len) {
                $i += strcspn($css, self::TOKENIZER_MASK, $i);
                if ($i >= $len) {
                    break;
                }
                if ($css[$i] === "/" && ($css[$i + 1] ?? "") === "*") {
                    break;
                }
                if ($css[$i] !== "/") {
                    break;
                }
                $i++;
            }
            yield [
                "type" => self::T_WORD,
                "value" => substr($css, $start, $i - $start),
            ];
        }
    }

    private function needsSpace(
        array $prev,
        array $curr,
        bool $inCalc,
        ?string $last,
        ?array $pp,
        bool $skipped,
    ): bool {
        if (
            $inCalc &&
            ($curr["value"] === "+" ||
                $curr["value"] === "-" ||
                $prev["value"] === "+" ||
                $prev["value"] === "-")
        ) {
            return true;
        }
        if ($prev["type"] === self::T_WORD && $curr["type"] === self::T_WORD) {
            return true;
        }
        if (
            $prev["type"] === self::T_PAREN_CLOSE &&
            $curr["type"] === self::T_WORD
        ) {
            if ($skipped) {
                return true;
            }
            if ($last && isset(self::SELECTOR_PSEUDOS[$last])) {
                return false;
            }
            return true;
        }
        if (
            $prev["type"] === self::T_WORD &&
            $curr["type"] === self::T_PAREN_OPEN
        ) {
            $v = strtolower($prev["value"]);
            if ($v === "and" || $v === "or" || $v === "not") {
                if ($v === "not" && $pp && $pp["type"] === self::T_COLON) {
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
        if (
            preg_match('/^0([a-z%]+)$/i', $val, $m) &&
            !isset(self::PRESERVED_UNITS[strtolower($m[1])])
        ) {
            return "0";
        }
        return $val;
    }
    private function compressHex(string $val): string
    {
        if ($val[0] !== "#") {
            return $val;
        }
        $val = strtolower($val);
        $len = strlen($val);
        if (
            $len === 7 &&
            $val[1] === $val[2] &&
            $val[3] === $val[4] &&
            $val[5] === $val[6]
        ) {
            return "#" . $val[1] . $val[3] . $val[5];
        }
        return $val;
    }

    private function shouldProcessStyle(
        $style,
        string $handle,
        array $excluded,
    ): bool {
        if (!isset($style->src) || empty($style->src)) {
            return false;
        }
        $src = $style->src;
        return strpos($src, ".min.css") === false &&
            strpos($src, site_url()) !== false &&
            !in_array($handle, $excluded) &&
            !$this->isExcluded($src);
    }
    private function getSourcePath($style): ?string
    {
        if (!isset($style->src)) {
            return null;
        }
        $path = str_replace(
            [site_url(), "wp-content"],
            [ABSPATH, "wp-content"],
            $style->src,
        );
        if (
            ($real = realpath($path)) &&
            str_starts_with($real, ABSPATH) &&
            pathinfo($real, PATHINFO_EXTENSION) === "css"
        ) {
            return $real;
        }
        return null;
    }
    private function updateStyleRegistration($style, string $file): void
    {
        $style->src = str_replace(ABSPATH, site_url("/"), $file);
        $style->ver = filemtime($file);
    }
    private function getCacheFile(string $key): string
    {
        return $this->cache_dir . $key . ".css";
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
