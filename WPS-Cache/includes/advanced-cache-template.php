<?php

/**
 * WPS Cache - Advanced Cache Drop-in
 * Supports Query Strings via hashed filenames.
 * Supports Mobile Cache Separation.
 */

if (!defined("ABSPATH")) {
    exit("Direct access not allowed.");
}

if (!defined("WP_CONTENT_DIR")) {
    define("WP_CONTENT_DIR", dirname(__FILE__));
}

class WPSAdvancedCache
{
    private const CACHE_LIFETIME = 3600;
    private const COOKIE_HEADER = "wordpress_logged_in_";

    public function execute(): void
    {
        if ($this->shouldBypass()) {
            return;
        }

        $file = $this->getCacheFilePath();

        if (file_exists($file)) {
            $mtime = filemtime($file);
            if (time() - $mtime > self::CACHE_LIFETIME) {
                return;
            }
            $this->serve($file, $mtime);
        }
    }

    private function shouldBypass(): bool
    {
        if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
            return true;
        }

        // Note: We removed the generic Query String bypass check here.
        // We now rely on the file existence check.
        // If query params exist but no file matches the hash, it falls through to WP.

        foreach ($_COOKIE as $key => $value) {
            if (
                strpos($key, self::COOKIE_HEADER) === 0 ||
                $key === "wp-postpass_" ||
                $key === "comment_author_"
            ) {
                return true;
            }
        }

        // Special paths
        $uri = $_SERVER["REQUEST_URI"] ?? "/";
        if (
            strpos($uri, "/wp-admin") !== false ||
            strpos($uri, "/xmlrpc.php") !== false
        ) {
            return true;
        }

        return false;
    }

    private function getCacheFilePath(): string
    {
        // Host Sanitization (Must match HTMLCache.php)
        $host = $_SERVER["HTTP_HOST"] ?? "unknown";
        $host = explode(":", $host)[0]; // Strip port
        $host = preg_replace("/[^a-z0-9\-\.]/i", "", $host);
        $host = preg_replace("/\.+/", ".", $host);
        $host = trim($host, ".");

        if (empty($host)) {
            $host = "unknown";
        }

        $uri = $_SERVER["REQUEST_URI"] ?? "/";

        $path = parse_url($uri, PHP_URL_PATH);
        // $path = str_replace("..", "", $path); // Security
        $path = $this->sanitizePath($path);

        if (
            substr($path, -1) !== "/" &&
            !preg_match('/\.[a-z0-9]{2,4}$/i', $path)
        ) {
            $path .= "/";
        }

        // Determine Mobile Suffix
        $suffix = $this->getMobileSuffix();

        // Determine Filename
        $query = parse_url($uri, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $queryParams);
            ksort($queryParams);
            // Append suffix to query-string based filenames
            $filename =
                "index" .
                $suffix .
                "-" .
                md5(http_build_query($queryParams)) .
                ".html";
        } else {
            // Append suffix to standard filenames
            $filename = "index" . $suffix . ".html";
        }

        // Ensure directory ends with slash before appending filename
        // (Must match HTMLCache path construction)
        $dir = WP_CONTENT_DIR . "/cache/wps-cache/html/" . $host . $path;
        if (substr($dir, -1) !== "/") {
            $dir .= "/";
        }

        return $dir . $filename;
    }

    /**
     * Efficiently detects mobile devices based on User-Agent.
     * Must match logic in HTMLCache.php
     */
    private function getMobileSuffix(): string
    {
        $ua = $_SERVER["HTTP_USER_AGENT"] ?? "";
        if (empty($ua)) {
            return "";
        }
        if (
            preg_match(
                "/(Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi)/i",
                $ua,
            )
        ) {
            return "-mobile";
        }
        return "";
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

    private function serve(string $file, int $mtime): void
    {
        $etag = '"' . $mtime . '"';
        if (
            isset($_SERVER["HTTP_IF_NONE_MATCH"]) &&
            trim($_SERVER["HTTP_IF_NONE_MATCH"]) === $etag
        ) {
            header("HTTP/1.1 304 Not Modified");
            exit();
        }

        header("Content-Type: text/html; charset=UTF-8");
        header("Cache-Control: public, max-age=3600");
        header("ETag: " . $etag);
        header("X-WPS-Cache: HIT");

        readfile($file);
        exit();
    }
}

new WPSAdvancedCache()->execute();
