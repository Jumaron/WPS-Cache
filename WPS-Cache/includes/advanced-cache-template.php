<?php

/**
 * WPS Cache — Advanced Cache Drop-in (High-Performance Edition)
 *
 * This drop-in is loaded by WordPress BEFORE the entire core boots.
 * Every microsecond here is multiplied by every page view.
 *
 * Architecture: Flat procedural — no class instantiation, no method dispatch.
 * All checks ordered by cost (cheapest/most-likely-to-bail first).
 *
 * Supports: Query string caching, mobile separation, precomputed gzip/brotli,
 *           ETag/304, Content-Length, HTTP keep-alive pipelining.
 */

if (!defined('ABSPATH')) {
    return;
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', dirname(__FILE__));
}

$runtimeConfig = [
    'ttl' => 3600,
    'bypass_cookies' => [
        'wordpress_logged_in_',
        'wp-postpass_',
        'comment_author_',
        'woocommerce_items_in_cart',
        'woocommerce_cart_hash',
        'wp_woocommerce_session_',
    ],
    'excluded_urls' => [],
    'bypass_user_agents' => [],
    'query_mode' => 'variants',
    'query_allowlist' => [],
    'query_denylist' => ['add-to-cart', 'wp_nonce', 'preview', 's'],
    'ignored_query_params' => ['utm_*', 'fbclid', 'gclid', 'dclid', 'msclkid', '_ga'],
    'device_mode' => 'mobile',
    'stale_ttl' => 300,
    'regeneration_lock' => 30,
    'cache_feeds' => false,
    'metrics_enabled' => false,
];
$runtimeFile = WP_CONTENT_DIR . '/cache/wps-cache/runtime.php';
$loadedConfig = is_file($runtimeFile) ? @include $runtimeFile : null;
if (is_array($loadedConfig)) {
    $runtimeConfig = array_replace($runtimeConfig, $loadedConfig);
}
$cacheTtl = max(60, min(31536000, (int) ($runtimeConfig['ttl'] ?? 3600)));

// ─── 0. FAST EXITS (cheapest checks first) ─────────────────────────────────

// Non-GET? Bail immediately — no further work.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    return;
}

// Logged-in or special WP cookies? Check raw header string — O(1) scan,
// avoids PHP parsing $_COOKIE into an array + iterating it.
$rawCookie = $_SERVER['HTTP_COOKIE'] ?? '';
if ($rawCookie !== '') {
    foreach ((array) ($runtimeConfig['bypass_cookies'] ?? []) as $cookieFragment) {
        if (is_string($cookieFragment) && $cookieFragment !== '' && str_contains($rawCookie, $cookieFragment)) {
            return;
        }
    }
}

// Admin / XMLRPC paths? Single check on REQUEST_URI before any parsing.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
if (
    str_contains($requestUri, '/wp-admin') ||
    str_contains($requestUri, '/xmlrpc.php') ||
    str_contains($requestUri, '/wp-login.php')
) {
    return;
}

foreach ((array) ($runtimeConfig['excluded_urls'] ?? []) as $excludedUrl) {
    if (is_string($excludedUrl) && $excludedUrl !== '' && str_contains($requestUri, $excludedUrl)) {
        return;
    }
}

$userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
foreach ((array) ($runtimeConfig['bypass_user_agents'] ?? []) as $blockedAgent) {
    if (is_string($blockedAgent) && $blockedAgent !== '' && stripos($userAgent, $blockedAgent) !== false) {
        return;
    }
}

// ─── 1. RESOLVE CACHE FILE PATH ────────────────────────────────────────────

// Parse URI once — extract both path and query in a single call.
$parsed = parse_url($requestUri);
$path   = $parsed['path'] ?? '/';
$query  = $parsed['query'] ?? '';

$matchesQueryPattern = static function (string $value, array $patterns): bool {
    foreach ($patterns as $pattern) {
        if (!is_string($pattern) || $pattern === '') {
            continue;
        }
        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i';
        if (preg_match($regex, $value) === 1) {
            return true;
        }
    }
    return false;
};

$queryParameters = [];
if ($query !== '') {
    if (strlen($query) > 2048) {
        return;
    }
    parse_str($query, $queryParameters);
    if (count($queryParameters) > 25) {
        return;
    }
    foreach (array_keys($queryParameters) as $queryKey) {
        if ($matchesQueryPattern((string) $queryKey, (array) ($runtimeConfig['query_denylist'] ?? []))) {
            return;
        }
        if (
            ($runtimeConfig['query_mode'] ?? 'variants') === 'allowlist' &&
            !$matchesQueryPattern((string) $queryKey, (array) ($runtimeConfig['query_allowlist'] ?? [])) &&
            !$matchesQueryPattern((string) $queryKey, (array) ($runtimeConfig['ignored_query_params'] ?? []))
        ) {
            return;
        }
    }
    if (($runtimeConfig['query_mode'] ?? 'variants') === 'ignore') {
        $queryParameters = [];
    } else {
        foreach (array_keys($queryParameters) as $queryKey) {
            if ($matchesQueryPattern((string) $queryKey, (array) ($runtimeConfig['ignored_query_params'] ?? []))) {
                unset($queryParameters[$queryKey]);
            }
        }
        ksort($queryParameters);
    }
    $query = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);
}

// Lightweight path sanitization — the web server already normalizes most of this.
// We only defend against directory traversal (null bytes + ".." segments).
$path = str_replace("\0", '', $path);
if (str_contains($path, '..')) {
    // Full sanitization only when ".." is actually present (extremely rare)
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($parts); }
        else { $parts[] = $seg; }
    }
    $path = '/' . implode('/', $parts);
}

// Ensure trailing slash for directory-like paths
if ($path[-1] !== '/' && !str_contains(basename($path), '.')) {
    $path .= '/';
}

// Host — fast sanitization (no regex on hot path)
$host = $_SERVER['HTTP_HOST'] ?? 'unknown';
$colonPos = strpos($host, ':');
if ($colonPos !== false) {
    $host = substr($host, 0, $colonPos);
}
// Validate host characters — only allow alphanumeric, hyphens, dots
$host = preg_replace('/[^a-zA-Z0-9\-.]/', '', $host);
if ($host === '') {
    $host = 'unknown';
}

// Determine device suffix — fast strpos checks before regex fallback
$mobileSuffix = '';
$deviceMode = (string) ($runtimeConfig['device_mode'] ?? 'mobile');
if ($deviceMode !== 'shared' && $userAgent !== '') {
    $isTablet = preg_match('/(iPad|Tablet|Nexus (?:7|9|10)|Kindle|Silk\/)(?!.*Mobile)/i', $userAgent) === 1;
    if ($deviceMode === 'tablet' && $isTablet) {
        $mobileSuffix = '-tablet';
    } elseif ($isTablet || preg_match('/(Mobile|Android|iPhone|iPod|BlackBerry|Opera Mini|Opera Mobi)/i', $userAgent) === 1) {
        $mobileSuffix = '-mobile';
    }
}

// Build filename
if ($query !== '') {
    $filename = 'index' . $mobileSuffix . '-' . md5($query) . '.html';
} else {
    $filename = 'index' . $mobileSuffix . '.html';
}

$cacheBase = WP_CONTENT_DIR . '/cache/wps-cache/html/' . $host . $path;
$cacheFile = $cacheBase . $filename;

// ─── 2. SERVE CACHED FILE ──────────────────────────────────────────────────

// Single stat() call — @filemtime returns false if file doesn't exist.
// This replaces the old file_exists() + filemtime() (2 stat calls → 1).
$mtime = @filemtime($cacheFile);
if ($mtime === false) {
    return; // Cache miss — fall through to WordPress
}

// TTL is generated from the validated WordPress setting.
$age = time() - $mtime;
$isStale = false;
if ($age > $cacheTtl) {
    $staleTtl = max(0, min(86400, (int) ($runtimeConfig['stale_ttl'] ?? 0)));
    $lockTtl = max(1, min(300, (int) ($runtimeConfig['regeneration_lock'] ?? 30)));
    $lockFile = $cacheFile . '.lock';
    $lockMtime = @filemtime($lockFile);
    if ($lockMtime !== false && time() - $lockMtime > $lockTtl) {
        @unlink($lockFile);
        $lockMtime = false;
    }
    if ($lockMtime === false) {
        $lock = @fopen($lockFile, 'x');
        if (is_resource($lock)) {
            fclose($lock);
            return; // This request owns regeneration; WordPress will refresh the file.
        }
    }
    if ($staleTtl === 0 || $age > $cacheTtl + $staleTtl) {
        return;
    }
    $isStale = true;
}

// Cache hits exit before WordPress's send_headers hook, so preserve the same
// security headers here without relying on web-server configuration.
$sendSecurityHeaders = static function (): void {
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    if ($https || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        header('Strict-Transport-Security: max-age=31536000');
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), payment=(), geolocation=(), browsing-topics=(), interest-cohort=(), magnetometer=(), gyroscope=(), usb=(), bluetooth=(), serial=(), midi=(), picture-in-picture=()');
};

// ─── 3. ETag / 304 Not Modified ─────────────────────────────────────────────

// Use file size + mtime for fast, unique ETag (no hashing needed)
$size = @filesize($cacheFile);
$etag = '"' . dechex($mtime) . '-' . dechex($size ?: 0) . '"';

if (
    isset($_SERVER['HTTP_IF_NONE_MATCH']) &&
    trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag
) {
    http_response_code(304);
    $sendSecurityHeaders();
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=' . $cacheTtl);
    header('X-WPS-Cache: ' . ($isStale ? 'STALE' : 'HIT'));
    exit;
}

// ─── 4. DETERMINE BEST ENCODING ────────────────────────────────────────────

$acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
$serveFile      = $cacheFile;
$encoding       = '';
$serveSize      = $size;

// Try brotli first (better compression), then gzip
if (str_contains($acceptEncoding, 'br')) {
    $brFile = $cacheFile . '.br';
    $brSize = @filesize($brFile);
    if ($brSize !== false) {
        $serveFile = $brFile;
        $encoding  = 'br';
        $serveSize = $brSize;
    }
}

if ($encoding === '' && str_contains($acceptEncoding, 'gzip')) {
    $gzFile = $cacheFile . '.gz';
    $gzSize = @filesize($gzFile);
    if ($gzSize !== false) {
        $serveFile = $gzFile;
        $encoding  = 'gzip';
        $serveSize = $gzSize;
    }
}

// ─── 5. SEND RESPONSE ──────────────────────────────────────────────────────

if (!empty($runtimeConfig['metrics_enabled'])) {
    $metricsFile = WP_CONTENT_DIR . '/cache/wps-cache/page-metrics.json';
    $metricsHandle = @fopen($metricsFile, 'c+');
    if (is_resource($metricsHandle) && flock($metricsHandle, LOCK_EX)) {
        $metricsRaw = stream_get_contents($metricsHandle);
        $metrics = is_string($metricsRaw) ? json_decode($metricsRaw, true) : [];
        $metrics = is_array($metrics) ? $metrics : [];
        $metrics['hits'] = (int) ($metrics['hits'] ?? 0) + 1;
        $metrics['stale_hits'] = (int) ($metrics['stale_hits'] ?? 0) + ($isStale ? 1 : 0);
        $metrics['bytes_served'] = (int) ($metrics['bytes_served'] ?? 0) + (int) ($serveSize ?: 0);
        $metrics['last_hit'] = time();
        rewind($metricsHandle);
        ftruncate($metricsHandle, 0);
        fwrite($metricsHandle, json_encode($metrics));
        fflush($metricsHandle);
        flock($metricsHandle, LOCK_UN);
    }
    if (is_resource($metricsHandle)) {
        fclose($metricsHandle);
    }
}

// Clean any output buffers that WordPress or plugins may have started
// to avoid double-encoding or buffer overhead.
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Status + core headers
http_response_code(200);
$sendSecurityHeaders();
$isFeedCache = !empty($runtimeConfig['cache_feeds']) && (str_contains($path, '/feed') || isset($queryParameters['feed']));
header('Content-Type: ' . ($isFeedCache ? 'application/rss+xml' : 'text/html') . '; charset=UTF-8');
header('Cache-Control: public, max-age=' . $cacheTtl);
header('ETag: ' . $etag);
header('Vary: Accept-Encoding, Cookie');
header('X-WPS-Cache: ' . ($isStale ? 'STALE' : 'HIT'));

// Compression header (only if serving precompressed file)
if ($encoding !== '') {
    header('Content-Encoding: ' . $encoding);
}

// Content-Length enables HTTP keep-alive pipelining
if ($serveSize !== false && $serveSize > 0) {
    header('Content-Length: ' . $serveSize);
}

// Serve the file — readfile() is the fastest PHP method (single syscall, no userspace buffering)
readfile($serveFile);
exit;
