<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

use WPSCache\Cache\Drivers\MinifyCSS;

class CSSOptimizer
{
    private array $settings;
    private array $used_selectors = [];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function process(string $html, string $merged_css): string
    {
        if (empty($this->settings['remove_unused_css'])) {
            return $merged_css; // Or return HTML with link tags if we aren't merging
        }

        // 1. Extract all Classes and IDs from HTML
        $this->extractUsedSelectors($html);

        // 2. Parse CSS and Filter
        return $this->filterCSS($merged_css);
    }

    /**
     * Scans HTML for 'class="..."' and 'id="..."'
     */
    private function extractUsedSelectors(string $html): void
    {
        // Match class="foo bar"
        if (preg_match_all('/class=["\']([^"\']*)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $classString) {
                $classes = explode(' ', $classString);
                foreach ($classes as $c) {
                    if (!empty($c)) $this->used_selectors['.' . trim($c)] = true;
                }
            }
        }

        // Match id="header"
        if (preg_match_all('/id=["\']([^"\']*)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                if (!empty($id)) $this->used_selectors['#' . trim($id)] = true;
            }
        }

        // Add standard tag selectors that are always safe to keep
        $tags = ['html', 'body', 'div', 'span', 'p', 'a', 'ul', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'header', 'footer', 'nav', 'section', 'article', 'main'];
        foreach ($tags as $tag) {
            $this->used_selectors[$tag] = true;
        }
    }

    /**
     * A lightweight tokenizer that skips unused blocks.
     * Reuses concepts from MinifyCSS but focused on filtering.
     */
    private function filterCSS(string $css): string
    {
        $buffer = '';
        $keepBlock = true;
        $inBlock = false;
        $currentSelector = '';

        // 1. Remove Comments first to simplify parsing
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);

        // Simple State Machine
        $len = strlen($css);
        for ($i = 0; $i < $len; $i++) {
            $char = $css[$i];

            if ($char === '{') {
                $inBlock = true;
                // Check if selector matches known used selectors
                $keepBlock = $this->isSelectorUsed($currentSelector);

                if ($keepBlock) {
                    $buffer .= $currentSelector . '{';
                }
                $currentSelector = '';
                continue;
            }

            if ($char === '}') {
                $inBlock = false;
                if ($keepBlock) {
                    $buffer .= '}';
                }
                $keepBlock = false;
                continue;
            }

            if ($inBlock) {
                if ($keepBlock) {
                    $buffer .= $char;
                }
            } else {
                $currentSelector .= $char;
            }
        }

        return $buffer;
    }

    /**
     * Determines if a CSS selector (e.g. ".header .nav") is used.
     * Safe Mode: If it contains :hover, ::before, @media, or unknown complex selectors, keep it.
     */
    private function isSelectorUsed(string $selector): bool
    {
        $selector = trim($selector);

        // Always keep @rules (media queries, keyframes, font-face)
        if (str_starts_with($selector, '@')) {
            return true;
        }

        // Always keep pseudo-classes/elements as we can't detect state from static HTML
        if (str_contains($selector, ':')) {
            return true;
        }

        // Split multiple selectors (comma separated)
        $parts = explode(',', $selector);
        $anyUsed = false;

        foreach ($parts as $part) {
            $part = trim($part);

            // Analyze the *last* part of the selector chain (the target)
            // e.g. "div .container > p.active" -> we check "p.active"
            // Simple heuristic: If any class/id in the selector exists in our list, keep it.

            // Extract classes (.name)
            if (preg_match_all('/\.([a-zA-Z0-9_\-]+)/', $part, $matches)) {
                foreach ($matches[0] as $class) {
                    if (isset($this->used_selectors[$class])) {
                        $anyUsed = true;
                        break;
                        2;
                    }
                }
            }

            // Extract IDs (#name)
            if (preg_match_all('/#([a-zA-Z0-9_\-]+)/', $part, $matches)) {
                foreach ($matches[0] as $id) {
                    if (isset($this->used_selectors[$id])) {
                        $anyUsed = true;
                        break;
                        2;
                    }
                }
            }

            // Keep generic tag selectors if they are simple (e.g. "body")
            if (isset($this->used_selectors[$part])) {
                $anyUsed = true;
                break;
                2;
            }
        }

        return $anyUsed;
    }
}
