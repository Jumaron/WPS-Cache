<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

use DOMDocument;

/**
 * SOTA CSS Tree Shaking.
 * Parses HTML and CSS to remove unused rules server-side.
 */
class CriticalCSSManager
{
    private array $settings;
    private array $selectorCache = [];
    private string $safelistRegex;
    private array $domStats = [];

    private const SAFELIST = [
        "active",
        "open",
        "show",
        "visible",
        "hidden",
        "error",
        "success",
        "admin-bar",
        "cookie",
        ":hover",
        ":focus",
        ":target",
        ":checked",
        ":disabled",
        "::before",
        "::after",
        "@media",
        "@keyframes",
        "@font-face",
    ];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $quoted = array_map(fn($s) => preg_quote($s, "/"), self::SAFELIST);
        $this->safelistRegex = "/" . implode("|", $quoted) . "/";
    }

    // New shared method
    public function processDom(DOMDocument $dom): void
    {
        if (empty($this->settings["remove_unused_css"])) {
            return;
        }

        // Optimization: Scan DOM once (O(N)) to build O(1) lookup maps
        $this->prepareDomStats($dom);
        $this->selectorCache = [];

        $styles = $dom->getElementsByTagName("style");
        $nodesToRemove = [];

        // Optimization: Direct iteration is O(N) in PHP 8.0+ and saves memory
        foreach ($styles as $style) {
            $css = $style->nodeValue;
            if (empty($css)) {
                continue;
            }

            $optimizedCss = $this->treeShakeCss($css);

            if (empty(trim($optimizedCss))) {
                $nodesToRemove[] = $style;
            } else {
                $style->nodeValue = $optimizedCss;
            }
        }

        foreach ($nodesToRemove as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    // Wrapper for compat
    public function process(string $html): string
    {
        if (empty($this->settings["remove_unused_css"])) {
            return $html;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $this->processDom($dom);

        $output = $dom->saveHTML();
        return str_replace('<?xml encoding="utf-8" ?>', "", $output);
    }

    private function prepareDomStats(DOMDocument $dom): void
    {
        $this->domStats = [
            "ids" => [],
            "classes" => [],
            "tags" => [],
        ];

        $nodes = $dom->getElementsByTagName("*");

        // Optimization: Direct iteration is O(N) in PHP 8.0+ and saves memory
        // by avoiding the creation of a large intermediate array.
        foreach ($nodes as $node) {
            // Tags (always lowercase in DOM for HTML)
            $this->domStats["tags"][strtolower($node->nodeName)] = true;

            // ID
            if ($id = $node->getAttribute("id")) {
                $this->domStats["ids"][$id] = true;
            }

            // Classes
            if ($class = $node->getAttribute("class")) {
                $trimmed = trim($class);
                if ($trimmed === "") {
                    continue;
                }

                // Optimization: Use explode for standard space-separated classes (faster than regex)
                // Check for non-space whitespace (tabs, newlines, etc)
                if (strpbrk($trimmed, "\t\n\r\f\v") === false) {
                    $classes = explode(" ", $trimmed);
                    foreach ($classes as $c) {
                        if ($c !== "") {
                            $this->domStats["classes"][$c] = true;
                        }
                    }
                } else {
                    // Fallback for complex whitespace
                    $classes = preg_split(
                        '/\s+/',
                        $trimmed,
                        -1,
                        PREG_SPLIT_NO_EMPTY,
                    );
                    foreach ($classes as $c) {
                        $this->domStats["classes"][$c] = true;
                    }
                }
            }
        }
    }

    private function treeShakeCss(string $css): string
    {
        $keptRules = [];
        preg_match_all(
            "/([^{]+)\{([^}]+)\}/s",
            $css,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $fullSelector = trim($match[1]);
            $body = $match[2];

            if (str_starts_with($fullSelector, "@")) {
                $keptRules[] = $match[0];
                continue;
            }

            $subSelectors = explode(",", $fullSelector);
            $keptSubSelectors = [];

            foreach ($subSelectors as $sel) {
                $sel = trim($sel);
                if ($this->shouldKeep($sel)) {
                    $keptSubSelectors[] = $sel;
                }
            }

            if (!empty($keptSubSelectors)) {
                $keptRules[] =
                    implode(", ", $keptSubSelectors) . "{" . $body . "}";
            }
        }

        return implode("\n", $keptRules);
    }

    private function shouldKeep(string $selector): bool
    {
        if (isset($this->selectorCache[$selector])) {
            return $this->selectorCache[$selector];
        }

        if (preg_match($this->safelistRegex, $selector)) {
            $this->selectorCache[$selector] = true;
            return true;
        }

        $cleanSelector = preg_replace(
            "/:[a-zA-Z-]+(\(.*?\))?/",
            "",
            $selector,
        );
        $cleanSelector = trim($cleanSelector);

        if (empty($cleanSelector)) {
            $this->selectorCache[$selector] = true;
            return true;
        }

        // Optimization: Use O(1) hash map lookups instead of slow DOMXPath queries
        $parts = preg_split("/[\s>+~]+/", $cleanSelector);
        $target = end($parts);

        // Safe Fallback: If we can't easily parse the target, keep it
        if ($target === false) {
            $this->selectorCache[$selector] = true;
            return true;
        }

        if (str_starts_with($target, "#")) {
            $id = substr($target, 1);
            $result = isset($this->domStats["ids"][$id]);
        } elseif (str_starts_with($target, ".")) {
            $class = substr($target, 1);
            $result = isset($this->domStats["classes"][$class]);
        } elseif (ctype_alnum($target)) {
            $result = isset($this->domStats["tags"][strtolower($target)]);
        } else {
            // Complex selector (e.g. attributes [type="text"]), default to Keep
            $result = true;
        }

        $this->selectorCache[$selector] = $result;
        return $result;
    }
}
