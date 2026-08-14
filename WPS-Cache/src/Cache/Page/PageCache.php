<?php

declare(strict_types=1);

namespace WPSCache\Cache\Page;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;
use WPSCache\Contracts\Purgeable;
use WPSCache\Integration\WooCommerce\CacheBypass;
use WPSCache\Support\AbstractFilesystemModule;

final class PageCache extends AbstractFilesystemModule implements Module, Purgeable
{
    private string $cacheDir;
    private ?string $exclusionRegex = null;
    private ?string $mobileSuffix = null;
    private ?string $sanitizedHost = null;

    /** Gzip compression level — 6 is the best speed/ratio trade-off */
    private const GZIP_LEVEL = 6;
    /** Brotli compression quality — 4 is fast with good ratio */
    private const BROTLI_QUALITY = 4;

    private bool $booted = false;
    private ?CacheBypass $cacheBypass;

    // Optimization: Use hash map for O(1) lookups
    private const BYPASS_PARAMS = [
        "add-to-cart" => true,
        "wp_nonce" => true,
        "preview" => true,
        "s" => true,
    ];

    // Sentinel: Limits to prevent Cache DoS (Disk Exhaustion)
    private const MAX_QUERY_LEN = 512;
    private const MAX_QUERY_PARAMS = 10;

    // SOTA: Explicitly ignore static extensions to prevent "Soft 404" caching
    // Optimization: Use hash map for O(1) lookups
    private const IGNORED_EXTENSIONS = [
        "xml" => true,
        "json" => true,
        "map" => true,
        "css" => true,
        "js" => true,
        "png" => true,
        "jpg" => true,
        "jpeg" => true,
        "gif" => true,
        "ico" => true,
        "svg" => true,
        "webp" => true,
        "avif" => true,
        "woff" => true,
        "woff2" => true,
        "ttf" => true,
        "eot" => true,
        "otf" => true,
        "txt" => true,
        "md" => true,
        "xsl" => true,
    ];

    public function __construct(Settings|array $settings, ?CacheBypass $cacheBypass = null)
    {
        parent::__construct($settings);
        $this->cacheBypass = $cacheBypass;
        $this->cacheDir = defined("WPSC_CACHE_DIR")
            ? WPSC_CACHE_DIR . "html/"
            : WP_CONTENT_DIR . "/cache/wps-cache/html/";
        // Optimization: Removed ensureDirectory here. It's handled lazily in atomicWrite.

        $excluded = $this->settings["excluded_urls"] ?? [];
        if (!empty($excluded)) {
            $excluded = array_filter($excluded);
            if (!empty($excluded)) {
                $quoted = array_map(
                    fn($s) => preg_quote($s, "/"),
                    array_unique($excluded),
                );
                $this->exclusionRegex = "/" . implode("|", $quoted) . "/";
            }
        }
    }

    public function id(): string
    {
        return 'page';
    }

    public function boot(): void
    {
        if ($this->booted || !$this->shouldCacheRequest()) {
            return;
        }
        ob_start([$this, "processOutput"]);
        $this->booted = true;
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

        // Sentinel Fix: Host Header Validation
        // Prevent Cache Poisoning: Ensure the request Host matches the site's configured Host.
        $configuredHost = parse_url(home_url(), PHP_URL_HOST);
        $requestHost = $_SERVER["HTTP_HOST"] ?? "";

        // Strip port number if present
        if (($pos = strpos($requestHost, ":")) !== false) {
            $requestHost = substr($requestHost, 0, $pos);
        }

        if ($configuredHost && strcasecmp($configuredHost, $requestHost) !== 0) {
            return false;
        }

        // 1. EXTENSION GUARD
        $path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (isset(self::IGNORED_EXTENSIONS[$ext])) {
            return false;
        }

        if ($this->cacheBypass && $this->cacheBypass->shouldBypass()) {
            return false;
        }

        if (!empty($_GET)) {
            // Sentinel Fix: Prevent Cache DoS (Disk Exhaustion)
            // Limit complexity of query strings to prevent infinite cache file generation
            if (count($_GET) > self::MAX_QUERY_PARAMS) {
                return false;
            }
            $qs = $_SERVER["QUERY_STRING"] ?? http_build_query($_GET);
            if (strlen($qs) > self::MAX_QUERY_LEN) {
                return false;
            }

            $keys = array_keys($_GET);
            foreach ($keys as $key) {
                if (isset(self::BYPASS_PARAMS[$key])) {
                    return false;
                }
            }
        }

        if (
            $this->exclusionRegex &&
            preg_match($this->exclusionRegex, $_SERVER["REQUEST_URI"] ?? "/")
        ) {
            return false;
        }

        return true;
    }

    public function processOutput(string $buffer): string
    {
        if (
            empty($buffer) ||
            http_response_code() !== 200 ||
            stripos($buffer, '</html>') === false
        ) {
            return $buffer;
        }

        // Add Timestamp & Signature
        $deviceType = $this->getMobileSuffix() ? "Mobile" : "Desktop";
        $content = $buffer . sprintf(
            "\n<!-- WPS Cache: %s (%s) -->",
            gmdate("Y-m-d H:i:s"),
            $deviceType,
        );

        $this->writeCacheFile($content);

        return $content;
    }

    private function writeCacheFile(string $content): void
    {
        $host = $this->getSanitizedHost();

        $uri = $_SERVER["REQUEST_URI"] ?? "/";
        $parsed = parse_url($uri);
        $path = $this->sanitizePath($parsed['path'] ?? '/');
        $query = $parsed['query'] ?? '';

        if (
            $path[-1] !== '/' &&
            !str_contains(basename($path), '.')
        ) {
            $path .= '/';
        }

        $suffix = $this->getMobileSuffix();

        if ($query !== '') {
            parse_str($query, $queryParams);
            ksort($queryParams);
            $filename = 'index' . $suffix . '-' . md5(http_build_query($queryParams)) . '.html';
        } else {
            $filename = 'index' . $suffix . '.html';
        }

        $fullPath = $this->cacheDir . $host . $path;
        if ($fullPath[-1] !== '/') {
            $fullPath .= '/';
        }

        $filepath = $fullPath . $filename;

        // 1. Write plain HTML
        $this->atomicWrite($filepath, $content);

        // 2. Write precomputed gzip (moves compression from serve-time to write-time)
        $gzContent = gzencode($content, self::GZIP_LEVEL);
        if ($gzContent !== false) {
            $this->atomicWrite($filepath . '.gz', $gzContent);
        }

        // 3. Write precomputed brotli if extension is available (PHP 8.4+)
        if (function_exists('brotli_compress')) {
            $brContent = brotli_compress($content, self::BROTLI_QUALITY);
            if ($brContent !== false) {
                $this->atomicWrite($filepath . '.br', $brContent);
            }
        }
    }

    /**
     * Memoized host sanitization — computed once per request.
     */
    private function getSanitizedHost(): string
    {
        if ($this->sanitizedHost !== null) {
            return $this->sanitizedHost;
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'unknown';
        $colonPos = strpos($host, ':');
        if ($colonPos !== false) {
            $host = substr($host, 0, $colonPos);
        }
        $host = preg_replace('/[^a-zA-Z0-9\-.]/', '', $host);
        if ($host === '') {
            $host = 'unknown';
        }

        return $this->sanitizedHost = $host;
    }

    private function getMobileSuffix(): string
    {
        if ($this->mobileSuffix !== null) {
            return $this->mobileSuffix;
        }

        $ua = $_SERVER["HTTP_USER_AGENT"] ?? "";
        if (empty($ua)) {
            return $this->mobileSuffix = "";
        }
        if (
            preg_match(
                "/(Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi)/i",
                $ua,
            )
        ) {
            return $this->mobileSuffix = "-mobile";
        }
        return $this->mobileSuffix = "";
    }

    private function sanitizePath(string $path): string
    {
        $path = str_replace("\0", '', $path);

        // Fast path: no traversal segments → skip expensive parsing
        if (!str_contains($path, '..')) {
            // Just normalize double slashes
            while (str_contains($path, '//')) {
                $path = str_replace('//', '/', $path);
            }
            return $path === '' ? '/' : $path;
        }

        // Slow path: full sanitization only when ".." present
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($parts); }
            else { $parts[] = $seg; }
        }
        return '/' . implode('/', $parts);
    }

    public function purge(): void
    {
        $this->recursiveDelete($this->cacheDir);
    }
}
