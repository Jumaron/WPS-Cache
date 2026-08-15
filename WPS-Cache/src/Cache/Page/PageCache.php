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
    private ?string $privateCacheDir;
    private ?string $exclusionRegex = null;
    private ?string $mobileSuffix = null;
    private ?string $sanitizedHost = null;
    private QueryPolicy $queryPolicy;

    /** Gzip compression level — 6 is the best speed/ratio trade-off */
    private const GZIP_LEVEL = 6;
    /** Brotli compression quality — 4 is fast with good ratio */
    private const BROTLI_QUALITY = 4;

    private bool $booted = false;
    private ?CacheBypass $cacheBypass;

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
        $this->queryPolicy = new QueryPolicy($this->settings);
        $this->cacheDir = defined("WPSC_CACHE_DIR")
            ? WPSC_CACHE_DIR . "html/"
            : WP_CONTENT_DIR . "/cache/wps-cache/html/";
        $this->privateCacheDir = defined('WPSC_PRIVATE_CACHE_DIR')
            ? rtrim((string) WPSC_PRIVATE_CACHE_DIR, '/\\') . DIRECTORY_SEPARATOR
            : null;
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
        add_action('send_headers', [$this, 'sendMissHeaders']);
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
        if (is_admin()) {
            return false;
        }

        if (is_user_logged_in() && !$this->allowsCurrentRole()) {
            return false;
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $looksLikeFeed = str_contains((string) parse_url($requestUri, PHP_URL_PATH), '/feed') || isset($_GET['feed']);
        if ($looksLikeFeed && empty($this->settings['cache_feeds'])) {
            return false;
        }
        if (isset($_GET['s']) && empty($this->settings['cache_search'])) {
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

        $cookieHeader = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
        foreach ($this->stringSetting('cache_bypass_cookies') as $fragment) {
            if ($fragment !== '' && str_contains($cookieHeader, $fragment)) {
                return false;
            }
        }
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        foreach ($this->stringSetting('cache_bypass_user_agents') as $pattern) {
            if ($pattern !== '' && @preg_match('/' . preg_quote($pattern, '/') . '/i', $userAgent) === 1) {
                return false;
            }
        }

        if (!empty($_GET)) {
            $qs = $_SERVER["QUERY_STRING"] ?? http_build_query($_GET);
            if ($this->queryPolicy->shouldBypass($_GET, $qs)) {
                return false;
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
            stripos($buffer, '</html>') === false &&
            (empty($this->settings['cache_feeds']) || (stripos($buffer, '</rss>') === false && stripos($buffer, '</feed>') === false))
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
        $this->recordMiss();

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
            $query = $this->queryPolicy->canonical($queryParams);
            $filename = $query === ''
                ? 'index' . $suffix . '.html'
                : 'index' . $suffix . '-' . md5($query) . '.html';
        } else {
            $filename = 'index' . $suffix . '.html';
        }

        $baseDirectory = is_user_logged_in() && $this->privateCacheDir !== null ? $this->privateCacheDir : $this->cacheDir;
        $fullPath = $baseDirectory . $host . $path;
        if ($fullPath[-1] !== '/') {
            $fullPath .= '/';
        }

        $filepath = $fullPath . $filename;

        // 1. Write plain HTML
        $this->atomicWrite($filepath, $content);
        @unlink($filepath . '.lock');

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

        $suffix = DeviceClassifier::suffix(
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            (string) ($this->settings['cache_device_mode'] ?? 'mobile'),
        );
        if (is_user_logged_in()) {
            $suffix .= '-user-' . $this->currentUserId() . '-role-' . $this->currentRole();
        }
        return $this->mobileSuffix = $suffix;
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
        if ($this->privateCacheDir !== null) {
            $this->recursiveDelete($this->privateCacheDir);
        }
    }

    private function recordMiss(): void
    {
        if (empty($this->settings['enable_metrics'])) {
            return;
        }
        $file = dirname($this->cacheDir) . '/page-metrics.json';
        $handle = @fopen($file, 'c+');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            is_resource($handle) && fclose($handle);
            return;
        }
        $raw = stream_get_contents($handle);
        $metrics = is_string($raw) ? json_decode($raw, true) : [];
        $metrics = is_array($metrics) ? $metrics : [];
        $metrics['misses'] = (int) ($metrics['misses'] ?? 0) + 1;
        $metrics['last_miss'] = time();
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($metrics));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function sendMissHeaders(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: public, max-age=' . max(60, min(31536000, (int) ($this->settings['cache_lifetime'] ?? 3600))));
            header('X-WPS-Cache: MISS');
            header('Vary: Accept-Encoding, Cookie');
        }
    }

    public function purgeUrl(string $url): bool
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: parse_url(home_url('/'), PHP_URL_HOST));
        $host = preg_replace('/[^a-zA-Z0-9\-.]/', '', $host) ?: 'unknown';
        $path = $this->sanitizePath((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        if ($path[-1] !== '/' && !str_contains(basename($path), '.')) {
            $path .= '/';
        }
        $targets = [$this->cacheDir . $host . $path];
        if ($this->privateCacheDir !== null) {
            $targets[] = $this->privateCacheDir . $host . $path;
        }
        foreach ($targets as $target) {
            $this->recursiveDelete($target);
        }
        do_action('wpsc_url_purged', $url);
        return count(array_filter($targets, 'is_dir')) === 0;
    }

    /** @return list<string> */
    private function stringSetting(string $key): array
    {
        $value = $this->settings[$key] ?? [];
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function allowsCurrentRole(): bool
    {
        $role = $this->currentRole();
        return $this->privateCacheDir !== null && $role !== '' && in_array($role, $this->stringSetting('cache_logged_in_roles'), true);
    }

    private function currentRole(): string
    {
        if (!function_exists('wp_get_current_user')) {
            return '';
        }
        $roles = wp_get_current_user()->roles ?? [];
        return sanitize_key(is_array($roles) ? (string) ($roles[0] ?? '') : '');
    }

    private function currentUserId(): int
    {
        if (!function_exists('get_current_user_id')) {
            return 0;
        }
        return max(0, (int) get_current_user_id());
    }
}
