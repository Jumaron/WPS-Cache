<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

/**
 * SOTA Async CSS Loader with Heuristic Critical CSS Generation.
 * STRICT VERSION: Reduces HTML bloat by being pickier about what is "Critical".
 */
final class AsyncCSS
{
    private array $settings;

    // STRICTER selectors. Only grab structural elements, not generic buttons/inputs.
    private const CRITICAL_KEYWORDS = [
        'body',
        'html',
        ':root',
        'header',
        'nav',
        'menu',
        'logo',
        '.site-header',
        '.main-navigation',
        '.ast-container', // Theme specific helpers
        'h1',
        'h2',
        'a', // Typography
        'display: none',
        'visibility: hidden' // Crucial for layout stability
    ];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function process(string $html): string
    {
        if (empty($this->settings['css_async'])) {
            return $html;
        }

        // 1. Find all CSS <link> tags
        if (!preg_match_all('/<link[^>]*rel=["\']stylesheet["\'][^>]*href=["\'](.*?)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
            return $html;
        }

        $critical_css_buffer = '';

        foreach ($matches as $match) {
            $original_tag = $match[0];
            $url = $match[1];

            // Skip excluded CSS
            if ($this->isExcluded($url)) {
                continue;
            }

            // 2. Fetch Local CSS Content
            $css_content = $this->getLocalCSS($url);

            if ($css_content) {
                // 3. Minify briefly to make parsing easier
                $minified = $this->minify($css_content);

                // 4. Generate Heuristic Critical CSS
                // Limit buffer size to 50KB to prevent massive HTML bloat
                if (strlen($critical_css_buffer) < 50000) {
                    $critical_css_buffer .= $this->extractCriticalRules($minified);
                }
            }

            // 5. Convert to Async Tag
            // Check if already async to avoid double processing
            if (strpos($original_tag, 'data-wpsc-async') !== false) {
                continue;
            }

            $async_tag = str_replace(
                ['rel="stylesheet"', "rel='stylesheet'"],
                'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'" data-wpsc-async="1"',
                $original_tag
            );

            $noscript = "<noscript><link rel='stylesheet' href='{$url}'></noscript>";
            $html = str_replace($original_tag, $async_tag . $noscript, $html);
        }

        // 6. Inject Critical CSS at the top of <head>
        if (!empty($critical_css_buffer)) {
            $style_block = sprintf(
                '<style id="wpsc-critical-css">%s</style>',
                $critical_css_buffer
            );
            $html = preg_replace('/(<head[^>]*>)/i', '$1' . $style_block, $html, 1);
        }

        return $html;
    }

    private function extractCriticalRules(string $css): string
    {
        $buffer = '';
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);

        $parts = explode('}', $css);

        foreach ($parts as $part) {
            if (trim($part) === '') continue;

            $fragments = explode('{', $part);
            if (count($fragments) < 2) continue;

            $selector = trim($fragments[0]);
            $body = trim($fragments[1]);

            // Skip @font-face and @keyframes in Critical CSS to save space
            if (str_starts_with($selector, '@font-face') || str_starts_with($selector, '@keyframes')) {
                continue;
            }

            if ($this->isSelectorCritical($selector)) {
                $buffer .= $selector . '{' . $body . '}';
            }
        }

        return $buffer;
    }

    private function isSelectorCritical(string $selector): bool
    {
        $selector = strtolower($selector);

        // Keep simple structural rules
        foreach (self::CRITICAL_KEYWORDS as $keyword) {
            if (str_contains($selector, $keyword)) {
                return true;
            }
        }
        return false;
    }

    private function minify(string $css): string
    {
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        $css = preg_replace('/\s+/', ' ', $css);
        $css = str_replace([': ', '; ', ', ', ' {', '} '], [':', ';', ',', '{', '}'], $css);
        return trim($css);
    }

    private function getLocalCSS(string $url): ?string
    {
        if (strpos($url, site_url()) === 0) {
            $path = str_replace(site_url(), ABSPATH, $url);
            $path = strtok($path, '?');
            if (file_exists($path)) {
                return file_get_contents($path);
            }
        }
        return null;
    }

    private function isExcluded(string $url): bool
    {
        $excluded = $this->settings['excluded_css'] ?? [];
        foreach ($excluded as $pattern) {
            if (str_contains($url, $pattern)) return true;
        }
        return false;
    }
}
