<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

use DOMDocument;
use DOMXPath;

/**
 * SOTA CSS Tree Shaking.
 * Parses HTML and CSS to remove unused rules server-side.
 */
class CriticalCSSManager
{
    private array $settings;

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
    }

    // New shared method
    public function processDom(DOMDocument $dom): void
    {
        if (empty($this->settings["remove_unused_css"])) {
            return;
        }

        $xpath = new DOMXPath($dom);
        $styles = $dom->getElementsByTagName("style");
        $nodesToRemove = [];

        foreach ($styles as $style) {
            $css = $style->nodeValue;
            if (empty($css)) {
                continue;
            }

            $optimizedCss = $this->treeShakeCss($css, $xpath);

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

    private function treeShakeCss(string $css, DOMXPath $xpath): string
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
                if ($this->shouldKeep($sel, $xpath)) {
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

    private function shouldKeep(string $selector, DOMXPath $xpath): bool
    {
        foreach (self::SAFELIST as $safe) {
            if (str_contains($selector, $safe)) {
                return true;
            }
        }

        $cleanSelector = preg_replace("/:[a-zA-Z-]+(\(.*?\))?/", "", $selector);
        $cleanSelector = trim($cleanSelector);

        if (empty($cleanSelector)) {
            return true;
        }

        try {
            $query = $this->cssToXpath($cleanSelector);
            $nodes = $xpath->query($query);
            return $nodes->length > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function cssToXpath(string $selector): string
    {
        $parts = preg_split("/[\s>+~]+/", $selector);
        $target = end($parts);

        if (str_starts_with($target, "#")) {
            $id = substr($target, 1);
            return "//*[@id='$id']";
        } elseif (str_starts_with($target, ".")) {
            $class = substr($target, 1);
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]";
        } else {
            if (ctype_alnum($target)) {
                return "//" . $target;
            } else {
                throw new \Exception("Complex selector");
            }
        }
    }
}
