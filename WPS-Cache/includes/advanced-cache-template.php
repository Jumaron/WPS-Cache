<?php

/**
 * WPS Cache - Advanced Cache Drop-in
 * Supports Query Strings via hashed filenames.
 */

if (!defined('ABSPATH')) {
    exit('Direct access not allowed.');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', dirname(__FILE__));
}

class WPSAdvancedCache
{
    private const CACHE_LIFETIME = 3600;
    private const COOKIE_HEADER = 'wordpress_logged_in_';

    public function execute(): void
    {
        if ($this->shouldBypass()) {
            return;
        }

        $file = $this->getCacheFilePath();

        if (file_exists($file)) {
            $mtime = filemtime($file);
            if ((time() - $mtime) > self::CACHE_LIFETIME) {
                return;
            }
            $this->serve($file, $mtime);
        }
    }

    private function shouldBypass(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return true;
        }

        // Note: We removed the generic Query String bypass check here.
        // We now rely on the file existence check. 
        // If query params exist but no file matches the hash, it falls through to WP.

        foreach ($_COOKIE as $key => $value) {
            if (strpos($key, self::COOKIE_HEADER) === 0 || $key === 'wp-postpass_' || $key === 'comment_author_') {
                return true;
            }
        }

        // Special paths
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (strpos($uri, '/wp-admin') !== false || strpos($uri, '/xmlrpc.php') !== false) {
            return true;
        }

        return false;
    }

    private function getCacheFilePath(): string
    {
        $host = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $_SERVER['HTTP_HOST'] ?? 'unknown');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        $path = parse_url($uri, PHP_URL_PATH);
        if (substr($path, -1) !== '/' && !preg_match('/\.[a-z0-9]{2,4}$/i', $path)) {
            $path .= '/';
        }
        $path = str_replace('..', '', $path); // Security

        // Determine Filename
        $query = parse_url($uri, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $queryParams);
            ksort($queryParams);
            $filename = 'index-' . md5(http_build_query($queryParams)) . '.html';
        } else {
            $filename = 'index.html';
        }

        return WP_CONTENT_DIR . "/cache/wps-cache/html/" . $host . $path . $filename;
    }

    private function serve(string $file, int $mtime): void
    {
        $etag = '"' . $mtime . '"';
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }

        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        header('ETag: ' . $etag);
        header('X-WPS-Cache: HIT');

        readfile($file);
        exit;
    }
}

(new WPSAdvancedCache())->execute();
