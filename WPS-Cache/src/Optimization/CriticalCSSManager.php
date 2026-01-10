<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

use DOMDocument;

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

        // Merge hardcoded safelist with user safelist
        $userList = $this->settings["css_safelist"] ?? [];
        $merged = array_unique(array_merge(self::SAFELIST, $userList));

        $quoted = array_map(fn($s) => preg_quote($s, "/"), $merged);
        $this->safelistRegex = "/" . implode("|", $quoted) . "/";
    }

    public function processDom(DOMDocument $dom): void
    {
        if (empty($this->settings["remove_unused_css"])) {
            return;
        }

        $this->prepareDomStats($dom);
        $this->selectorCache = [];

        $styles = $dom->getElementsByTagName("style");
        $nodesToRemove = [];

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
        return str_replace('<?xml encoding="utf-8" ?>', "", $dom->saveHTML());
    }

    private function prepareDomStats(DOMDocument $dom): void
    {
        $this->domStats = ["ids" => [], "classes" => [], "tags" => []];
        // Optimization: Use DOMXPath for attribute collection to avoid O(N) getAttribute calls
        $xpath = new \DOMXPath($dom);

        // 1. Collect Tags (Still need O(N) traversal, but property access is fast)
        $nodes = $dom->getElementsByTagName("*");
        foreach ($nodes as $node) {
            $this->domStats["tags"][strtolower($node->nodeName)] = true;
        }

        // 2. Collect IDs (O(1) lookup via C-based libxml)
        foreach ($xpath->query("//@id") as $attr) {
            $value = $attr->nodeValue;
            if ($value !== "") {
                $this->domStats["ids"][$value] = true;
            }
        }

        // 3. Collect Classes
        foreach ($xpath->query("//@class") as $attr) {
            $trimmed = trim($attr->nodeValue);
            if ($trimmed === "") {
                continue;
            }
            if (strpbrk($trimmed, "\t\n\r\f\v") === false) {
                $classes = explode(" ", $trimmed);
                foreach ($classes as $c) {
                    if ($c !== "") {
                        $this->domStats["classes"][$c] = true;
                    }
                }
            } else {
                $classes = preg_split(
                    "/\s+/",
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

    private function treeShakeCss(string $css): string
    {
        $keptRules = [];
        preg_match_all("/([^{]+)\{([^}]+)\}/s", $css, $matches, PREG_SET_ORDER);
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

        $cleanSelector = $selector;

        // Optimization: Skip expensive regex if no pseudo-class chars exist
        if (strpos($selector, ":") !== false) {
            $cleanSelector = preg_replace("/:[a-zA-Z-]+(\(.*?\))?/", "", $selector);
        }
        $cleanSelector = trim($cleanSelector);

        if (empty($cleanSelector)) {
            $this->selectorCache[$selector] = true;
            return true;
        }

        // Optimization: Skip expensive regex split if no combinators exist
        if (strpbrk($cleanSelector, " >+~\t\n\r\f\v") === false) {
            $target = $cleanSelector;
        } else {
            $parts = preg_split("/[\s>+~]+/", $cleanSelector);
            $target = end($parts);
        }
        if ($target === false) {
            $this->selectorCache[$selector] = true;
            return true;
        }

        if (str_starts_with($target, "#")) {
            $result = isset($this->domStats["ids"][substr($target, 1)]);
        } elseif (str_starts_with($target, ".")) {
            $result = isset($this->domStats["classes"][substr($target, 1)]);
        } elseif (ctype_alnum($target)) {
            $result = isset($this->domStats["tags"][strtolower($target)]);
        } else {
            $result = true;
        }

        $this->selectorCache[$selector] = $result;
        return $result;
    }
}
