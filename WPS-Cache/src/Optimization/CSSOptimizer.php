<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

class CSSOptimizer
{
    private array $settings;
    private array $used_classes = [];
    private array $used_ids = [];
    private array $used_tags = [];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function process(string $html, string $css): string
    {
        if (empty($this->settings['remove_unused_css'])) {
            return $css;
        }

        // 1. Build the lookup table from HTML
        $this->extractUsedSelectors($html);

        // 2. Parse and Filter the CSS
        return $this->parseAndFilter($css);
    }

    private function extractUsedSelectors(string $html): void
    {
        // Extract Classes
        if (preg_match_all('/class=["\']([^"\']*)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $classString) {
                $classes = preg_split('/\s+/', trim($classString));
                foreach ($classes as $c) {
                    if ($c) $this->used_classes[$c] = true;
                }
            }
        }

        // Extract IDs
        if (preg_match_all('/id=["\']([^"\']*)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                if ($id) $this->used_ids[$id] = true;
            }
        }

        // Standard HTML5 tags are always "used"
        $this->used_tags = array_flip([
            'html',
            'body',
            'div',
            'span',
            'applet',
            'object',
            'iframe',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'p',
            'blockquote',
            'pre',
            'a',
            'abbr',
            'acronym',
            'address',
            'big',
            'cite',
            'code',
            'del',
            'dfn',
            'em',
            'img',
            'ins',
            'kbd',
            'q',
            's',
            'samp',
            'small',
            'strike',
            'strong',
            'sub',
            'sup',
            'tt',
            'var',
            'b',
            'u',
            'i',
            'center',
            'dl',
            'dt',
            'dd',
            'ol',
            'ul',
            'li',
            'fieldset',
            'form',
            'label',
            'legend',
            'table',
            'caption',
            'tbody',
            'tfoot',
            'thead',
            'tr',
            'th',
            'td',
            'article',
            'aside',
            'canvas',
            'details',
            'embed',
            'figure',
            'figcaption',
            'footer',
            'header',
            'hgroup',
            'menu',
            'nav',
            'output',
            'ruby',
            'section',
            'summary',
            'time',
            'mark',
            'audio',
            'video',
            'main',
            'svg',
            'path'
        ]);
    }

    /**
     * The Core Logic: A recursive parser that handles @media nesting
     */
    private function parseAndFilter(string $css): string
    {
        // Remove comments to simplify parsing
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);

        $buffer = '';
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            $char = $css[$i];

            // 1. Handle @-rules (media, keyframes, etc)
            if ($char === '@') {
                $blockStart = $i;
                // Read until we find '{' or ';'
                while ($i < $length && $css[$i] !== '{' && $css[$i] !== ';') {
                    $i++;
                }

                if ($i >= $length) break;

                // If it's a statement like @import or @charset (ends with ;)
                if ($css[$i] === ';') {
                    $buffer .= substr($css, $blockStart, $i - $blockStart + 1);
                    $i++;
                    continue;
                }

                // It's a block (@media, @keyframes, @supports)
                $header = substr($css, $blockStart, $i - $blockStart);
                $isMedia = (stripos($header, '@media') === 0 || stripos($header, '@supports') === 0);

                // Extract the block content
                $i++; // skip '{'
                $depth = 1;
                $innerContentStart = $i;

                while ($i < $length && $depth > 0) {
                    if ($css[$i] === '{') $depth++;
                    else if ($css[$i] === '}') $depth--;
                    $i++;
                }

                $innerContent = substr($css, $innerContentStart, $i - $innerContentStart - 1);

                if ($isMedia) {
                    // RECURSION: Filter the content INSIDE the @media block
                    $filteredInner = $this->parseAndFilter($innerContent);
                    if (trim($filteredInner) !== '') {
                        $buffer .= $header . '{' . $filteredInner . '}';
                    }
                } else {
                    // For @keyframes, @font-face, keep them whole (safe mode)
                    $buffer .= $header . '{' . $innerContent . '}';
                }
                continue;
            }

            // 2. Handle Standard CSS Rules
            // Scan for the start of a selector
            if (!ctype_space($char) && $char !== '}') {
                $selectorStart = $i;
                while ($i < $length && $css[$i] !== '{') {
                    $i++;
                }

                $selectorString = substr($css, $selectorStart, $i - $selectorStart);

                // Extract body
                $i++; // skip '{'
                $depth = 1;
                $bodyStart = $i;
                while ($i < $length && $depth > 0) {
                    if ($css[$i] === '{') $depth++;
                    else if ($css[$i] === '}') $depth--;
                    $i++;
                }

                $body = substr($css, $bodyStart, $i - $bodyStart - 1);

                // FILTER: Check if selectors are used
                $validSelectors = [];
                $selectors = explode(',', $selectorString);

                foreach ($selectors as $sel) {
                    if ($this->isSelectorUsed(trim($sel))) {
                        $validSelectors[] = trim($sel);
                    }
                }

                if (!empty($validSelectors)) {
                    $buffer .= implode(',', $validSelectors) . '{' . $body . '}';
                }
                continue;
            }

            $i++;
        }

        return $buffer;
    }

    /**
     * Determines if a CSS selector is relevant to the current HTML.
     */
    private function isSelectorUsed(string $selector): bool
    {
        // 1. Always keep pseudo-elements/classes that imply dynamic state
        // (We can't detect hover/focus/before state from static HTML)
        if (str_contains($selector, ':')) {
            // Strip the pseudo part to check the base element
            // e.g. "a:hover" -> check if "a" exists
            $baseSelector = preg_split('/:+/', $selector)[0];
            if (empty($baseSelector)) return true; // Safety
            return $this->checkSimpleSelector($baseSelector);
        }

        // 2. Complex Selectors: "div.container > span.active"
        // Heuristic: Check if the *right-most* (key) part exists.
        // This is a trade-off. Full DOM tree matching is too slow for PHP.
        // We assume if the target class exists, the rule is likely relevant.
        $parts = preg_split('/[\s>+~]+/', $selector);
        $keyPart = end($parts);

        return $this->checkSimpleSelector($keyPart);
    }

    /**
     * Checks a single component (e.g. "div#id.class")
     */
    private function checkSimpleSelector(string $part): bool
    {
        // Remove attribute selectors [type="text"] as they are hard to match via regex
        $part = preg_replace('/\[.*?\]/', '', $part);

        // Check ID
        if (str_contains($part, '#')) {
            $segments = explode('#', $part);
            $id = $segments[1] ?? '';
            // If ID is not in our list, rule is unused
            if (!empty($id) && !isset($this->used_ids[$id])) {
                return false;
            }
        }

        // Check Classes
        if (str_contains($part, '.')) {
            $classes = explode('.', $part);
            array_shift($classes); // remove empty or tag part

            $foundAny = false;
            foreach ($classes as $cls) {
                // If ANY class in the chain exists, we consider it a potential match.
                // Strict mode would require ALL, but that breaks multi-class logic often in PHP.
                if (isset($this->used_classes[$cls])) {
                    $foundAny = true;
                    break;
                }
            }
            if (!$foundAny && !empty($classes)) {
                return false;
            }
        }

        // Check Tag (if no ID and no Class present)
        if (!str_contains($part, '.') && !str_contains($part, '#')) {
            $tag = strtolower($part);
            // Universal selector
            if ($tag === '*') return true;
            if (!isset($this->used_tags[$tag]) && !empty($tag)) {
                return false;
            }
        }

        return true;
    }
}
