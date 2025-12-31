<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

/**
 * Handles Asynchronous CSS Loading and Heuristic Critical CSS Generation.
 * 
 * SOTA Strategy:
 * 1. Convert blocking <link> tags to non-blocking preload links.
 * 2. Generate lightweight Critical CSS in-line to prevent FOUC (Flash of Unstyled Content).
 * 3. Provide <noscript> fallbacks.
 */
class AsyncCSS
{
    private array $settings;

    // Structural selectors to hunt for in local CSS files for "Heuristic Critical CSS"
    private const CRITICAL_KEYWORDS = [
        'body',
        'html',
        ':root',
        'header',
        'nav',
        'menu',
        '.site-header',
        '.main-navigation',
        '#masthead',
        '.container',
        '.wrapper',
        'display: none',
        'visibility: hidden' // Important for layout stability
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

        $critical_buffer = '';
        $processed_html = $html;

        foreach ($matches as $match) {
            $original_tag = $match[0];
            $url = $match[1];

            // Skip exclusions
            if ($this->isExcluded($url)) {
                continue;
            }

            // 2. Generate Critical CSS (if local file)
            // We accumulate this to inject it in the <head> later
            $critical_buffer .= $this->generateHeuristicCriticalCSS($url);

            // 3. Convert to Async Load
            // Strategy: preload -> onload -> switch to stylesheet
            $async_tag = str_replace(
                ['rel="stylesheet"', "rel='stylesheet'"],
                'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'" data-wpsc-async="1"',
                $original_tag
            );

            // 4. Add Noscript Fallback
            $noscript = "<noscript><link rel='stylesheet' href='{$url}'></noscript>";

            $processed_html = str_replace($original_tag, $async_tag . $noscript, $processed_html);
        }

        // 5. Inject Critical CSS Block
        if (!empty($critical_buffer)) {
            $style_block = sprintf('<style id="wpsc-critical-css">%s</style>', $this->minifyCritical($critical_buffer));
            $processed_html = preg_replace('/(<head[^>]*>)/i', '$1' . $style_block, $processed_html, 1);
        }

        return $processed_html;
    }

    /**
     * Attempts to read local CSS files and extract structural rules.
     * This is faster than Puppeteer/API and prevents FOUC for 80% of themes.
     */
    private function generateHeuristicCriticalCSS(string $url): string
    {
        // Only process local files
        if (strpos($url, site_url()) !== 0) {
            return '';
        }

        // 1. Check Transient Cache
        // We use the full URL (including version query strings) for the key
        // This ensures that if the file version changes, we regenerate the cache.
        $cache_key = 'wpsc_ccss_' . md5($url);
        $cached_css = get_transient($cache_key);

        if ($cached_css !== false) {
            return $cached_css;
        }

        $path = str_replace(site_url(), ABSPATH, $url);
        // Remove query strings (ver=1.2.3)
        $path = strtok($path, '?');

        if (!file_exists($path)) {
            return '';
        }

        $css = @file_get_contents($path);
        if (!$css) return '';

        $critical = '';

        // Simple tokenizer to find rules
        // We look for selectors containing our keywords
        // This is a rough heuristic, but effective for initial paint stability
        $parts = explode('}', $css);
        foreach ($parts as $part) {
            foreach (self::CRITICAL_KEYWORDS as $keyword) {
                // Check if selector contains keyword (before the { )
                $check = explode('{', $part);
                if (isset($check[0]) && stripos($check[0], $keyword) !== false) {
                    $critical .= $part . '}';
                    break; // Found one keyword, add rule and move to next rule
                }
            }
        }

        // 2. Set Transient Cache (1 Week)
        // Even if empty, we cache it to avoid re-reading/re-parsing the file.
        set_transient($cache_key, $critical, WEEK_IN_SECONDS);

        return $critical;
    }

    private function minifyCritical(string $css): string
    {
        // Basic stripping for the inline block
        $css = preg_replace('/\/\*((?!\*\/).)*\*\//s', '', $css); // comments
        $css = preg_replace('/\s+/', ' ', $css); // whitespace
        return str_replace([': ', '; ', ', ', ' {', '} '], [':', ';', ',', '{', '}'], trim($css));
    }

    private function isExcluded(string $url): bool
    {
        $exclusions = $this->settings['excluded_css'] ?? [];
        foreach ($exclusions as $pattern) {
            if (!empty($pattern) && stripos($url, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}
