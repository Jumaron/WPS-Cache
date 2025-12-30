<?php

/**
 * WPS Cache - Ultra Fast Advanced Cache Drop-in
 * 
 * SOTA Optimization:
 * 1. Zero DB Calls (No get_option)
 * 2. Zero File Reads for Validation (No md5_file)
 * 3. Strict Path Matching
 */

if (!defined('ABSPATH')) {
    exit('Direct access not allowed.');
}

// Safety check: Ensure cache directory exists constants are available
// We hardcode the path logic here to avoid loading WP dependencies
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', dirname(__FILE__)); // Fallback assumption
}

class WPSAdvancedCache
{
    // Hardcoded to avoid DB call. 3600 is safe default.
    // If you need dynamic settings here, they should be written to a wpsc-config.php file,
    // NOT read from the DB.
    private const CACHE_LIFETIME = 3600;

    // Cookie name prefix for logged-in users
    private const COOKIE_HEADER = 'wordpress_logged_in_';

    public function execute(): void
    {
        if ($this->shouldBypass()) {
            return;
        }

        $file = $this->getCacheFilePath();

        if (file_exists($file)) {
            // Check expiration using metadata only (Instant)
            $mtime = filemtime($file);
            if ((time() - $mtime) > self::CACHE_LIFETIME) {
                return; // Expired, let WP handle it
            }

            $this->serve($file, $mtime);
        }
    }

    private function shouldBypass(): bool
    {
        // 1. Method Check
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return true;
        }

        // 2. Query String Check (Bypass on any query param for safety)
        if (!empty($_SERVER['QUERY_STRING'])) {
            return true;
        }

        // 3. Cookie Check (Logged in users)
        // We check $_COOKIE directly to avoid WP function overhead
        foreach ($_COOKIE as $key => $value) {
            if (strpos($key, self::COOKIE_HEADER) === 0 || $key === 'wp-postpass_' || $key === 'comment_author_') {
                return true;
            }
        }

        // 4. Special Paths
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (strpos($uri, '/wp-admin') !== false || strpos($uri, '/xmlrpc.php') !== false) {
            return true;
        }

        return false;
    }

    private function getCacheFilePath(): string
    {
        // Hostname Sanitization (Must match HTMLCache.php EXACTLY)
        // Removes ports and special chars: localhost:8080 -> localhost8080
        $host = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $_SERVER['HTTP_HOST'] ?? 'unknown');

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Ensure path ends with slash for directory structure
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }

        // Sanitize path to prevent traversal
        $path = str_replace('..', '', $path);

        return WP_CONTENT_DIR . "/cache/wps-cache/html/" . $host . $path . "index.html";
    }

    private function serve(string $file, int $mtime): void
    {
        // Use MTime as ETag (Fastest possible validation)
        $etag = '"' . $mtime . '"';

        // Browser Caching Check
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }

        // Headers
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: public, max-age=3600'); // Let browser cache for 1 hour
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('X-WPS-Cache: HIT');

        // Output file
        readfile($file);
        exit;
    }
}

// Execute immediately
(new WPSAdvancedCache())->execute();
