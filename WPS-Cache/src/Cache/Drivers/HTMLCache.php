<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use WPSCache\Optimization\JSOptimizer;
use WPSCache\Optimization\AsyncCSS;

/**
 * Handles Full Page Caching (Static HTML).
 * Writes files to disk in a structure that allows Nginx/Apache to serve them directly.
 */
final class HTMLCache extends AbstractCacheDriver
{
    private string $cacheDir;
    private array $ignoredTags = [];

    // SOTA: Ignore tracking params but CACHE the page (ignore them in key generation if you wanted advanced strictness)
    // BUT for file based caching, we MUST distinguish distinct query strings unless we canonicalize.
    // Here we define params that should BYPASS cache generation entirely.
    private const BYPASS_PARAMS = [
        'add-to-cart',
        'wp_nonce',
        'preview',
        's' // Search results often dynamic
    ];

    public function __construct()
    {
        parent::__construct();
        $this->cacheDir = defined('WPSC_CACHE_DIR') ? WPSC_CACHE_DIR . 'html/' : WP_CONTENT_DIR . '/cache/wps-cache/html/';
        $this->ensureDirectory($this->cacheDir);
    }

    public function initialize(): void
    {
        if ($this->initialized || !$this->shouldCacheRequest()) {
            return;
        }

        // Start buffering immediately
        ob_start([$this, 'processOutput']);
        $this->initialized = true;
    }

    /**
     * Determines if the current request is suitable for caching.
     * fail-fast logic to avoid overhead.
     */
    private function shouldCacheRequest(): bool
    {
        if (empty($this->settings['html_cache'])) return false;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
        if (is_user_logged_in() || is_admin()) return false;

        // Check for specific Bypass params
        if (!empty($_GET)) {
            $keys = array_keys($_GET);
            foreach ($keys as $key) {
                if (in_array($key, self::BYPASS_PARAMS, true)) {
                    return false;
                }
            }
        }

        // Standard Exclusions
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        foreach ($this->settings['excluded_urls'] ?? [] as $pattern) {
            if (!empty($pattern) && str_contains($uri, $pattern)) return false;
        }

        return true;
    }

    /**
     * Callback for ob_start. Processes, optimizes, and writes HTML.
     */
    public function processOutput(string $buffer): string
    {
        if (empty($buffer) || http_response_code() !== 200) return $buffer;

        // Optimize
        $content = $this->minifyHTML($buffer);

        $jsOpt = new JSOptimizer($this->settings);
        $content = $jsOpt->process($content);

        $cssOpt = new AsyncCSS($this->settings);
        $content = $cssOpt->process($content);

        $content .= sprintf("\n<!-- WPS Cache: %s -->", gmdate('Y-m-d H:i:s'));

        $this->writeCacheFile($content);

        return $content;
    }

    /**
     * Generates the path-based filename matching the URL structure.
     * Schema: /cache/hostname/path/index.html
     */
    private function writeCacheFile(string $content): void
    {
        $host = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $_SERVER['HTTP_HOST'] ?? 'unknown');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        // Ensure leading/trailing slash for folder structure
        if (substr($path, -1) !== '/') {
            // Check if it looks like a file extension
            if (!preg_match('/\.[a-z0-9]{2,4}$/i', $path)) {
                $path .= '/';
            }
        }

        // Handle Query Strings
        $query = parse_url($uri, PHP_URL_QUERY);
        if ($query) {
            // Sort query params to ensure ?a=1&b=2 hits same cache as ?b=2&a=1
            parse_str($query, $queryParams);
            ksort($queryParams);
            // SOTA: Filename includes hash of query
            $filename = 'index-' . md5(http_build_query($queryParams)) . '.html';
        } else {
            $filename = 'index.html';
        }

        $fullPath = $this->cacheDir . $host . $path;

        // Ensure path ends in slash for directory creation if we are appending a filename
        if (substr($fullPath, -1) !== '/') {
            $fullPath .= '/';
        }

        $this->atomicWrite($fullPath . $filename, $content);
    }

    private function minifyHTML(string $html): string
    {
        // ... (Same minification logic as before) ...
        // Re-included for completeness
        $this->ignoredTags = [];
        $html = preg_replace_callback('/<(script|style|pre|textarea|code)[^>]*>.*?<\/\1>/si', function ($m) {
            $k = "<!--WP_P_" . count($this->ignoredTags) . "-->";
            $this->ignoredTags[$k] = $m[0];
            return $k;
        }, $html);

        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);
        $html = preg_replace('/\s+/', ' ', $html);

        if (!empty($this->ignoredTags)) $html = strtr($html, $this->ignoredTags);
        return trim($html);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {}
    public function get(string $key): mixed
    {
        return null;
    }
    public function delete(string $key): void {}
    public function clear(): void
    {
        $this->recursiveDelete($this->cacheDir);
    }
}
