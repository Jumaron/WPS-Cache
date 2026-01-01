<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use WPSCache\Optimization\JSOptimizer;
use WPSCache\Optimization\AsyncCSS;
use WPSCache\Optimization\MediaOptimizer;
use WPSCache\Optimization\FontOptimizer;

final class HTMLCache extends AbstractCacheDriver
{
    private string $cacheDir;
    private ?string $exclusionRegex = null;

    private const BYPASS_PARAMS = ["add-to-cart", "wp_nonce", "preview", "s"];

    public function __construct()
    {
        parent::__construct();
        $this->cacheDir = defined("WPSC_CACHE_DIR")
            ? WPSC_CACHE_DIR . "html/"
            : WP_CONTENT_DIR . "/cache/wps-cache/html/";
        $this->ensureDirectory($this->cacheDir);

        // Compile exclusion patterns into a single regex for O(1) matching
        $excluded = $this->settings["excluded_urls"] ?? [];
        if (!empty($excluded)) {
            // Filter empty strings to prevent "match all" regex (e.g. "foo|")
            $excluded = array_filter($excluded);
            if (!empty($excluded)) {
                // Deduplicate and escape for regex
                $quoted = array_map(
                    fn($s) => preg_quote($s, "/"),
                    array_unique($excluded),
                );
                // Use case-sensitive matching to align with str_contains behavior
                $this->exclusionRegex = "/" . implode("|", $quoted) . "/";
            }
        }
    }

    public function initialize(): void
    {
        if ($this->initialized || !$this->shouldCacheRequest()) {
            return;
        }
        ob_start([$this, "processOutput"]);
        $this->initialized = true;
    }

    private function shouldCacheRequest(): bool
    {
        if (empty($this->settings["html_cache"])) {
            return false;
        }
        if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
            return false;
        }
        if (is_user_logged_in() || is_admin()) {
            return false;
        }
        if (!empty($_GET)) {
            $keys = array_keys($_GET);
            foreach ($keys as $key) {
                if (in_array($key, self::BYPASS_PARAMS, true)) {
                    return false;
                }
            }
        }
        $uri = $_SERVER["REQUEST_URI"] ?? "/";

        // Optimized: Single regex match instead of loop + str_contains (O(1) vs O(N))
        if ($this->exclusionRegex && preg_match($this->exclusionRegex, $uri)) {
            return false;
        }
        return true;
    }

    public function processOutput(string $buffer): string
    {
        if (empty($buffer) || http_response_code() !== 200) {
            return $buffer;
        }

        $content = $buffer;

        // --- OPTIMIZATION PIPELINE ---

        // 1. Font Optimization (New)
        // We do this early so subsequent CSS minification sees the new inline styles
        try {
            $fontOpt = new FontOptimizer($this->settings);
            $content = $fontOpt->process($content);
        } catch (\Throwable $e) {
            // Log error
        }

        // 2. Media Optimization
        try {
            $mediaOpt = new MediaOptimizer($this->settings);
            $content = $mediaOpt->process($content);
        } catch (\Throwable $e) {
            // Log error, keep content
        }

        // 3. JS Delay/Defer
        if (
            !empty($this->settings["js_delay"]) ||
            !empty($this->settings["js_defer"])
        ) {
            try {
                $jsOpt = new JSOptimizer($this->settings);
                $content = $jsOpt->process($content);
            } catch (\Throwable $e) {
                // Fail safe: if optimizer crashes, keep original content
                $content = $buffer;
            }
        }

        // 4. CSS Async
        if (!empty($this->settings["css_async"])) {
            try {
                $cssOpt = new AsyncCSS($this->settings);
                $content = $cssOpt->process($content);
            } catch (\Throwable $e) {
            }
        }

        // Add Timestamp
        $content .= sprintf("\n<!-- WPS Cache: %s -->", gmdate("Y-m-d H:i:s"));

        // Write to Disk
        $this->writeCacheFile($content);

        return $content;
    }

    private function writeCacheFile(string $content): void
    {
        $host = $_SERVER["HTTP_HOST"] ?? "unknown";
        $host = explode(":", $host)[0];
        $host = preg_replace("/[^a-z0-9\-\.]/i", "", $host);
        $host = preg_replace("/\.+/", ".", $host);
        $host = trim($host, ".");
        if (empty($host)) {
            $host = "unknown";
        }
        $uri = $_SERVER["REQUEST_URI"] ?? "/";
        $path = $this->sanitizePath(parse_url($uri, PHP_URL_PATH));
        if (
            substr($path, -1) !== "/" &&
            !preg_match('/\.[a-z0-9]{2,4}$/i', $path)
        ) {
            $path .= "/";
        }
        $query = parse_url($uri, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $queryParams);
            ksort($queryParams);
            $filename =
                "index-" . md5(http_build_query($queryParams)) . ".html";
        } else {
            $filename = "index.html";
        }
        $fullPath = $this->cacheDir . $host . $path;
        if (substr($fullPath, -1) !== "/") {
            $fullPath .= "/";
        }
        $this->atomicWrite($fullPath . $filename, $content);
    }
    private function sanitizePath(string $path): string
    {
        $path = str_replace(chr(0), "", $path);
        $parts = explode("/", $path);
        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === "" || $part === ".") {
                continue;
            }
            if ($part === "..") {
                array_pop($safeParts);
            } else {
                $safeParts[] = $part;
            }
        }
        return "/" . implode("/", $safeParts);
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
