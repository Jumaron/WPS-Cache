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

// ─── 1. RESOLVE CACHE FILE PATH ────────────────────────────────────────────

// Parse URI once — extract both path and query in a single call.
$parsed = parse_url($requestUri);
$path   = $parsed['path'] ?? '/';
$query  = $parsed['query'] ?? '';

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
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua !== '' && (
    str_contains($ua, 'Mobile') ||
    str_contains($ua, 'Android') ||
    str_contains($ua, 'Kindle') ||
    str_contains($ua, 'BlackBerry') ||
    str_contains($ua, 'Opera Mini') ||
    str_contains($ua, 'Opera Mobi') ||
    str_contains($ua, 'Silk/')
)) {
    $mobileSuffix = '-mobile';
}

// Build filename
if ($query !== '') {
    parse_str($query, $qp);
    ksort($qp);
    $filename = 'index' . $mobileSuffix . '-' . md5(http_build_query($qp)) . '.html';
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
if (time() - $mtime > $cacheTtl) {
    return; // Expired — fall through to WordPress for regeneration
}

// ─── 3. ETag / 304 Not Modified ─────────────────────────────────────────────

// Use file size + mtime for fast, unique ETag (no hashing needed)
$size = @filesize($cacheFile);
$etag = '"' . dechex($mtime) . '-' . dechex($size ?: 0) . '"';

if (
    isset($_SERVER['HTTP_IF_NONE_MATCH']) &&
    trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag
) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=' . $cacheTtl);
    header('X-WPS-Cache: HIT');
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

// Clean any output buffers that WordPress or plugins may have started
// to avoid double-encoding or buffer overhead.
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Status + core headers
http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=' . $cacheTtl);
header('ETag: ' . $etag);
header('Vary: Accept-Encoding, Cookie');
header('X-WPS-Cache: HIT');

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
