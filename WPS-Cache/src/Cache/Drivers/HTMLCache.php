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

    public function __construct()
    {
        parent::__construct();
        $this->cacheDir = defined('WPSC_CACHE_DIR')
            ? WPSC_CACHE_DIR . 'html/'
            : WP_CONTENT_DIR . '/cache/wps-cache/html/';
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
        // 1. Check Global Settings
        if (empty($this->settings['html_cache'])) {
            return false;
        }

        // 2. Method Check
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return false;
        }

        // 3. User & Admin Checks
        if (is_user_logged_in() || is_admin()) {
            return false;
        }

        // 4. Query Strings (Strict Mode: Don't cache if params exist)
        if (!empty($_SERVER['QUERY_STRING'])) {
            return false;
        }

        // 5. Special WP Requests
        if (
            (defined('DOING_CRON') && DOING_CRON) ||
            (defined('WP_CLI') && WP_CLI) ||
            (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
            is_feed() ||
            is_trackback() ||
            is_robots()
        ) {
            return false;
        }

        // 6. Exclusions from Settings
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        foreach ($this->settings['excluded_urls'] ?? [] as $pattern) {
            if (!empty($pattern) && str_contains($uri, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Callback for ob_start. Processes, optimizes, and writes HTML.
     */
    public function processOutput(string $buffer): string
    {
        // Safety: Don't cache errors or empty pages
        if (empty($buffer) || http_response_code() !== 200) {
            return $buffer;
        }

        // 1. Minify HTML (Safe Mode)
        $content = $this->minifyHTML($buffer);

        // 2. Add Signature
        $signature = sprintf(
            "\n<!-- Cached by WPS-Cache on %s - Compression: %.2f%% -->",
            gmdate('Y-m-d H:i:s'),
            (1 - (strlen($content) / strlen($buffer))) * 100
        );
        $content .= $signature;

        // 3. Write to Disk
        $this->writeCacheFile($content);

        return $content;
    }

    /**
     * Generates the path-based filename matching the URL structure.
     * Schema: /cache/hostname/path/index.html
     */
    private function writeCacheFile(string $content): void
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'unknown';
        // Sanitize Host (prevent traversal)
        $host = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $host);

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Ensure path ends with slash to create directory structure
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }

        $fullPath = $this->cacheDir . $host . $path . 'index.html';

        $this->atomicWrite($fullPath, $content);
    }

    /**
     * Safe HTML Minifier.
     * Protects <script>, <style>, <pre>, <textarea> before removing whitespace.
     */
    private function minifyHTML(string $html): string
    {
        // 1. Extract protected tags
        $this->ignoredTags = [];
        $html = preg_replace_callback(
            '/<(script|style|pre|textarea|code)[^>]*>.*?<\/\1>/si',
            function ($matches) {
                $token = "<!--WPSC_PROTECT_" . count($this->ignoredTags) . "-->";
                $this->ignoredTags[$token] = $matches[0];
                return $token;
            },
            $html
        );

        // 2. Remove HTML comments (except IE conditionals)
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);

        // 3. Collapse whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        $html = preg_replace('/>\s+</', '><', $html);

        // 4. Restore protected tags
        if (!empty($this->ignoredTags)) {
            $html = strtr($html, $this->ignoredTags);
        }

        return trim($html);
    }

    /**
     * CacheDriverInterface Implementations
     */

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        // HTML Cache uses automated URI mapping via processOutput.
        // Direct set() is rarely used but implemented for interface compliance.
        $this->atomicWrite($this->cacheDir . md5($key) . '.html', (string) $value);
    }

    public function get(string $key): mixed
    {
        $file = $this->cacheDir . md5($key) . '.html';
        return file_exists($file) ? file_get_contents($file) : null;
    }

    public function delete(string $key): void
    {
        $file = $this->cacheDir . md5($key) . '.html';
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public function clear(): void
    {
        // Clean the entire HTML cache directory
        if (is_dir($this->cacheDir)) {
            $this->recursiveDelete($this->cacheDir);
        }
    }
}
