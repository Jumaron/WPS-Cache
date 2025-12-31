<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use WPSCache\Optimization\JSOptimizer;
use WPSCache\Optimization\AsyncCSS;

final class HTMLCache extends AbstractCacheDriver
{
    private string $cacheDir;

    private const BYPASS_PARAMS = ['add-to-cart', 'wp_nonce', 'preview', 's'];

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
        ob_start([$this, 'processOutput']);
        $this->initialized = true;
    }

    private function shouldCacheRequest(): bool
    {
        if (empty($this->settings['html_cache'])) return false;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
        if (is_user_logged_in() || is_admin()) return false;

        if (!empty($_GET)) {
            $keys = array_keys($_GET);
            foreach ($keys as $key) {
                if (in_array($key, self::BYPASS_PARAMS, true)) return false;
            }
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        foreach ($this->settings['excluded_urls'] ?? [] as $pattern) {
            if (!empty($pattern) && str_contains($uri, $pattern)) return false;
        }

        return true;
    }

    public function processOutput(string $buffer): string
    {
        if (empty($buffer) || http_response_code() !== 200) return $buffer;

        // --- DISABLE HTML OPTIMIZATION FOR STABILITY ---
        // Elementor and other builders rely on whitespace for inline-block layout.
        // We skip $this->optimizeHTML($buffer) entirely to ensure 100% visual compatibility.
        $content = $buffer;

        // --- ASSET OPTIMIZATION ---
        // We still apply JS and CSS optimizations as they are handled safely by SOTA drivers.

        // 1. JS Delay/Defer
        if (!empty($this->settings['js_delay']) || !empty($this->settings['js_defer'])) {
            try {
                $jsOpt = new JSOptimizer($this->settings);
                $content = $jsOpt->process($content);
            } catch (\Throwable $e) {
                // Fail safe: if optimizer crashes, keep original content
                $content = $buffer;
            }
        }

        // 2. CSS Async
        if (!empty($this->settings['css_async'])) {
            try {
                $cssOpt = new AsyncCSS($this->settings);
                $content = $cssOpt->process($content);
            } catch (\Throwable $e) {
                // Fail safe
            }
        }

        // Add Timestamp
        $content .= sprintf("\n<!-- WPS Cache: %s -->", gmdate('Y-m-d H:i:s'));

        // Write to Disk
        $this->writeCacheFile($content);

        return $content;
    }

    private function writeCacheFile(string $content): void
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'unknown';

        // Sentinel Fix: Prevent Directory Traversal via Host Header
        // 1. Remove port
        $host = explode(':', $host)[0];
        // 2. Strict whitelist (alphanumeric, dot, dash)
        $host = preg_replace('/[^a-z0-9\-\.]/i', '', $host);
        // 3. Remove consecutive dots (..) to prevent traversal
        $host = preg_replace('/\.+/', '.', $host);
        // 4. Trim leading/trailing dots
        $host = trim($host, '.');

        if (empty($host)) {
            $host = 'unknown';
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Sentinel: Prevent path traversal by sanitizing the path
        $path = $this->sanitizePath(parse_url($uri, PHP_URL_PATH));

        if (substr($path, -1) !== '/' && !preg_match('/\.[a-z0-9]{2,4}$/i', $path)) {
            $path .= '/';
        }

        $query = parse_url($uri, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $queryParams);
            ksort($queryParams);
            $filename = 'index-' . md5(http_build_query($queryParams)) . '.html';
        } else {
            $filename = 'index.html';
        }

        $fullPath = $this->cacheDir . $host . $path;
        if (substr($fullPath, -1) !== '/') $fullPath .= '/';

        $this->atomicWrite($fullPath . $filename, $content);
    }

    /**
     * Sentinel: Sanitize path to prevent Directory Traversal.
     * Removes dot segments (./ and ../) and enforces strict path structure.
     */
    private function sanitizePath(string $path): string
    {
        // 1. Remove null bytes
        $path = str_replace(chr(0), '', $path);

        // 2. Explode and filter parts
        $parts = explode('/', $path);
        $safeParts = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                // Determine behavior: skip or pop?
                // Standard URL resolution pops the last segment.
                // If we are at root, we ignore it.
                array_pop($safeParts);
            } else {
                $safeParts[] = $part;
            }
        }

        // 3. Rebuild path
        return '/' . implode('/', $safeParts);
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
