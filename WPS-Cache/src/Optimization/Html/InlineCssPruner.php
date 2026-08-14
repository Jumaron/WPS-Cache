<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use DOMDocument;
use WPSCache\Contracts\HtmlProcessor;

/**
 * CSS Tree-Shaking: Removes unused CSS rules from inline <style> blocks.
 *
 * Collects all IDs, classes, tags, and data-attributes from the DOM,
 * then prunes CSS rules whose selectors don't match anything in the page.
 *
 * Correctly handles nested at-rules (@media, @keyframes, @supports, @layer)
 * using a brace-depth parser instead of naive regex.
 */
final class InlineCssPruner implements HtmlProcessor
{
    private array $settings;
    private array $selectorCache = [];
    private string $safelistRegex;
    private array $domStats = [];

    /** Built-in safelist — selectors/patterns that must NEVER be removed */
    private const SAFELIST = [
        'active',
        'open',
        'show',
        'visible',
        'hidden',
        'error',
        'success',
        'admin-bar',
        'cookie',
        ':hover',
        ':focus',
        ':focus-within',
        ':focus-visible',
        ':target',
        ':checked',
        ':disabled',
        ':visited',
        '::before',
        '::after',
        '::placeholder',
        '::selection',
        '@media',
        '@keyframes',
        '@font-face',
        '@layer',
        '@supports',
        '@container',
    ];

    public function __construct(array $settings)
    {
        $this->settings = $settings;

        // Merge hardcoded safelist with user safelist
        $userList = $this->settings['css_safelist'] ?? [];
        $merged = array_unique(array_merge(self::SAFELIST, $userList));

        $patterns = [];
        foreach ($merged as $item) {
            $item = trim($item);
            if ($item === '') continue;

            // Support wildcard patterns (e.g. "wp-block-*")
            if (str_contains($item, '*')) {
                $patterns[] = str_replace('\\*', '.*', preg_quote($item, '/'));
            } else {
                $patterns[] = preg_quote($item, '/');
            }
        }
        $this->safelistRegex = '/' . implode('|', $patterns) . '/';
    }

    public function processDom(DOMDocument $dom): void
    {
        if (empty($this->settings['remove_unused_css'])) {
            return;
        }

        $this->prepareDomStats($dom);
        $this->selectorCache = [];

        $styles = $dom->getElementsByTagName('style');
        $nodesToRemove = [];

        foreach ($styles as $style) {
            $css = $style->nodeValue;
            if (empty($css)) {
                continue;
            }
            $optimizedCss = $this->treeShakeCss($css);
            if (trim($optimizedCss) === '') {
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
        if (empty($this->settings['remove_unused_css'])) {
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
        return str_replace('<?xml encoding="utf-8" ?>', '', $dom->saveHTML());
    }

    /**
     * Collects DOM statistics: all tags, IDs, classes, and data-attributes.
     */
    private function prepareDomStats(DOMDocument $dom): void
    {
        $this->domStats = [
            'ids' => [],
            'classes' => [],
            'tags' => [],
            'attrs' => [],
        ];

        $xpath = new \DOMXPath($dom);

        // 1. Collect Tags
        $nodes = $dom->getElementsByTagName('*');
        foreach ($nodes as $node) {
            $this->domStats['tags'][strtolower($node->nodeName)] = true;
        }

        // 2. Collect IDs
        foreach ($xpath->query('//@id') as $attr) {
            $value = $attr->nodeValue;
            if ($value !== '') {
                $this->domStats['ids']['#' . $value] = true;
            }
        }

        // 3. Collect Classes
        foreach ($xpath->query('//@class') as $attr) {
            $trimmed = trim($attr->nodeValue);
            if ($trimmed === '') {
                continue;
            }
            $classes = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($classes as $c) {
                $this->domStats['classes']['.' . $c] = true;
            }
        }

        // 4. Collect data-* attributes (for [data-attr] selectors)
        foreach ($xpath->query('//@*[starts-with(name(), "data-")]') as $attr) {
            $this->domStats['attrs'][$attr->nodeName] = true;
        }
    }

    /**
     * Tree-shakes CSS using a brace-depth parser.
     * Correctly handles nested at-rules (@media, @keyframes, @supports, @layer).
     */
    private function treeShakeCss(string $css): string
    {
        $rules = $this->parseCssRules($css);
        $kept = [];

        foreach ($rules as $rule) {
            if ($rule['type'] === 'at-rule') {
                // At-rules: recursively tree-shake their content
                $innerShaken = $this->treeShakeCss($rule['content']);
                if (trim($innerShaken) !== '') {
                    $kept[] = $rule['selector'] . '{' . $innerShaken . '}';
                }
            } elseif ($rule['type'] === 'at-rule-simple') {
                // Simple at-rules without blocks (@charset, @import, @layer statement)
                $kept[] = $rule['raw'];
            } elseif ($rule['type'] === 'rule') {
                // Regular CSS rule — check if selector is used
                $subSelectors = explode(',', $rule['selector']);
                $keptSubs = [];
                foreach ($subSelectors as $sel) {
                    $sel = trim($sel);
                    if ($sel !== '' && $this->shouldKeep($sel)) {
                        $keptSubs[] = $sel;
                    }
                }
                if (!empty($keptSubs)) {
                    $kept[] = implode(',', $keptSubs) . '{' . $rule['content'] . '}';
                }
            }
        }

        return implode('', $kept);
    }

    /**
     * Parses CSS into structured rules using brace-depth tracking.
     * Handles nested at-rules correctly unlike regex-based parsers.
     *
     * @return array<array{type: string, selector?: string, content?: string, raw?: string}>
     */
    private function parseCssRules(string $css): array
    {
        $rules = [];
        $len = strlen($css);
        $i = 0;

        while ($i < $len) {
            // Skip whitespace
            $i += strspn($css, " \t\n\r", $i);
            if ($i >= $len) break;

            // Skip comments
            if ($css[$i] === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }

            // Find the selector/at-rule preamble (everything before the opening brace)
            $selectorStart = $i;
            $foundBrace = false;

            while ($i < $len) {
                $ch = $css[$i];

                // Handle strings inside selectors (rare but possible in content properties)
                if ($ch === '"' || $ch === "'") {
                    $i++;
                    while ($i < $len && $css[$i] !== $ch) {
                        if ($css[$i] === '\\') $i++;
                        $i++;
                    }
                    if ($i < $len) $i++;
                    continue;
                }

                if ($ch === '{') {
                    $foundBrace = true;
                    break;
                }

                // Simple at-rule without block (e.g., @import, @charset)
                if ($ch === ';') {
                    $raw = trim(substr($css, $selectorStart, $i - $selectorStart + 1));
                    if ($raw !== '') {
                        $rules[] = ['type' => 'at-rule-simple', 'raw' => $raw];
                    }
                    $i++;
                    $foundBrace = false;
                    break;
                }

                $i++;
            }

            if (!$foundBrace || $i >= $len) continue;

            $selector = trim(substr($css, $selectorStart, $i - $selectorStart));
            $i++; // Skip the opening brace

            // Now find the matching closing brace (respecting nesting)
            $contentStart = $i;
            $depth = 1;

            while ($i < $len && $depth > 0) {
                $ch = $css[$i];

                // Skip strings
                if ($ch === '"' || $ch === "'") {
                    $i++;
                    while ($i < $len && $css[$i] !== $ch) {
                        if ($css[$i] === '\\') $i++;
                        $i++;
                    }
                    if ($i < $len) $i++;
                    continue;
                }

                // Skip comments inside rules
                if ($ch === '/' && ($css[$i + 1] ?? '') === '*') {
                    $end = strpos($css, '*/', $i + 2);
                    $i = $end === false ? $len : $end + 2;
                    continue;
                }

                if ($ch === '{') $depth++;
                elseif ($ch === '}') $depth--;

                if ($depth > 0) $i++;
            }

            $content = substr($css, $contentStart, $i - $contentStart);
            $i++; // Skip the closing brace

            if (str_starts_with($selector, '@')) {
                // At-rules with nested content (@media, @keyframes, @supports, @layer, @container)
                $rules[] = ['type' => 'at-rule', 'selector' => $selector, 'content' => $content];
            } else {
                $rules[] = ['type' => 'rule', 'selector' => $selector, 'content' => $content];
            }
        }

        return $rules;
    }

    /**
     * Determines if a CSS selector should be kept based on DOM presence or safelist.
     */
    private function shouldKeep(string $selector): bool
    {
        if (isset($this->selectorCache[$selector])) {
            return $this->selectorCache[$selector];
        }

        // Check safelist first (includes user patterns with wildcard support)
        if (preg_match($this->safelistRegex, $selector)) {
            return $this->selectorCache[$selector] = true;
        }

        $cleanSelector = $selector;

        // Strip pseudo-classes/elements for matching
        if (str_contains($selector, ':')) {
            $cleanSelector = preg_replace('/::[a-zA-Z-]+(\(.*?\))?/', '', $selector);
            $cleanSelector = preg_replace('/:[a-zA-Z-]+(\(.*?\))?/', '', $cleanSelector);
        }
        $cleanSelector = trim($cleanSelector);

        if ($cleanSelector === '') {
            return $this->selectorCache[$selector] = true;
        }

        // Extract the rightmost simple selector (the key target)
        if (strpbrk($cleanSelector, " >+~\t\n\r\f\v") !== false) {
            $parts = preg_split('/[\s>+~]+/', $cleanSelector);
            $target = end($parts);
        } else {
            $target = $cleanSelector;
        }

        if ($target === false || $target === '') {
            return $this->selectorCache[$selector] = true;
        }

        // Check against DOM stats
        if (str_starts_with($target, '#')) {
            $result = isset($this->domStats['ids'][$target]);
        } elseif (str_starts_with($target, '.')) {
            // Handle compound class selectors like .foo.bar
            if (strpos($target, '.', 1) !== false) {
                $classes = explode('.', ltrim($target, '.'));
                $result = true;
                foreach ($classes as $c) {
                    if ($c !== '' && !isset($this->domStats['classes']['.' . $c])) {
                        $result = false;
                        break;
                    }
                }
            } else {
                $result = isset($this->domStats['classes'][$target]);
            }
        } elseif (str_starts_with($target, '[')) {
            // Attribute selectors — check data-* attributes
            if (preg_match('/^\[([a-zA-Z0-9_-]+)/', $target, $m)) {
                $attrName = $m[1];
                $result = isset($this->domStats['attrs'][$attrName]) ||
                          isset($this->domStats['tags'][$attrName]) ||
                          !str_starts_with($attrName, 'data-'); // Keep non-data attr selectors (conservative)
            } else {
                $result = true;
            }
        } elseif (ctype_alnum(str_replace('-', '', $target))) {
            $result = isset($this->domStats['tags'][strtolower($target)]);
        } else {
            // Unknown selector type — keep it (conservative)
            $result = true;
        }

        return $this->selectorCache[$selector] = $result;
    }
}
