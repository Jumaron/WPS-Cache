<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

use DOMDocument;
use DOMElement;

/**
 * Handles Asynchronous CSS Loading.
 * Now uses DOMDocument to prevent Regex/HTML conflicts.
 */
class AsyncCSS
{
    private array $settings;
    private ?string $exclusionRegex = null;

    private const CRITICAL_KEYWORDS = [
        "body",
        "html",
        ":root",
        "header",
        "nav",
        "menu",
        ".site-header",
        ".main-navigation",
        "#masthead",
        ".container",
        ".wrapper",
        "display: none",
        "visibility: hidden",
    ];

    public function __construct(array $settings)
    {
        $this->settings = $settings;

        $exclusions = $this->settings["excluded_css"] ?? [];
        $exclusions = array_filter($exclusions);

        if (!empty($exclusions)) {
            $quoted = array_map(fn($s) => preg_quote($s, "/"), $exclusions);
            $this->exclusionRegex = "/" . implode("|", $quoted) . "/i";
        }
    }

    public function processDom(DOMDocument $dom): void
    {
        $links = $dom->getElementsByTagName("link");
        $criticalBuffer = "";

        // Loop backwards because we might insert nodes (noscript) after current node
        for ($i = $links->length - 1; $i >= 0; $i--) {
            /** @var DOMElement $link */
            $link = $links->item($i);

            // Validate it's a stylesheet
            if ($link->getAttribute("rel") !== "stylesheet") {
                continue;
            }
            if ($link->hasAttribute("data-wpsc-async")) {
                continue;
            } // Already processed

            $href = $link->getAttribute("href");

            // Check Exclusions
            if ($this->isExcluded($href)) {
                continue;
            }

            // Generate Critical CSS
            $criticalBuffer .= $this->generateHeuristicCriticalCSS($href);

            // Modify Attributes for Async Load
            // This is the SOTA pattern: preload -> onload=stylesheet
            $link->setAttribute("rel", "preload");
            $link->setAttribute("as", "style");
            $link->setAttribute("data-wpsc-async", "1");

            // DOMDocument automatically escapes this attribute value correctly, fixing the crash!
            $link->setAttribute(
                "onload",
                "this.onload=null;this.rel='stylesheet'",
            );

            // Create Noscript Fallback
            $noscript = $dom->createElement("noscript");
            $fallbackLink = $dom->createElement("link");
            $fallbackLink->setAttribute("rel", "stylesheet");
            $fallbackLink->setAttribute("href", $href);
            $noscript->appendChild($fallbackLink);

            // Insert noscript after the link
            if ($link->nextSibling) {
                $link->parentNode->insertBefore($noscript, $link->nextSibling);
            } else {
                $link->parentNode->appendChild($noscript);
            }
        }

        // Inject Critical CSS Block
        if (!empty($criticalBuffer)) {
            $head = $dom->getElementsByTagName("head")->item(0);
            if ($head) {
                $style = $dom->createElement("style");
                $style->setAttribute("id", "wpsc-critical-css");
                $style->nodeValue = $this->minifyCritical($criticalBuffer);

                // Prepend to head
                if ($head->firstChild) {
                    $head->insertBefore($style, $head->firstChild);
                } else {
                    $head->appendChild($style);
                }
            }
        }
    }

    private function generateHeuristicCriticalCSS(string $url): string
    {
        if (strpos($url, site_url()) !== 0) {
            return "";
        }

        $cache_key = "wpsc_ccss_" . md5($url);
        $cached_css = get_transient($cache_key);
        if ($cached_css !== false) {
            return $cached_css;
        }

        $path = str_replace(site_url(), ABSPATH, $url);
        $path = strtok($path, "?");

        if (!file_exists($path)) {
            return "";
        }

        $css = @file_get_contents($path);
        if (!$css) {
            return "";
        }

        $critical = "";
        $parts = explode("}", $css);
        foreach ($parts as $part) {
            foreach (self::CRITICAL_KEYWORDS as $keyword) {
                $check = explode("{", $part);
                if (
                    isset($check[0]) &&
                    stripos($check[0], $keyword) !== false
                ) {
                    $critical .= $part . "}";
                    break;
                }
            }
        }

        set_transient($cache_key, $critical, WEEK_IN_SECONDS);
        return $critical;
    }

    private function minifyCritical(string $css): string
    {
        $css = preg_replace("/\/\*((?!\*\/).)*\*\//s", "", $css);
        $css = preg_replace("/\s+/", " ", $css);
        return str_replace(
            [": ", "; ", ", ", " {", "} "],
            [":", ";", ",", "{", "}"],
            trim($css),
        );
    }

    private function isExcluded(string $url): bool
    {
        if ($this->exclusionRegex === null) {
            return false;
        }
        return preg_match($this->exclusionRegex, $url) === 1;
    }
}
