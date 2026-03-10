<?php

/**
 * WPS Cache - Advanced Cache Drop-in
 * Supports Query Strings via hashed filenames.
 * Supports Mobile Cache Separation.
 * Supports pre-compressed Gzip serving.
 */

if (!defined("ABSPATH")) {
    exit("Direct access not allowed.");
}

if (!defined("WP_CONTENT_DIR")) {
    define("WP_CONTENT_DIR", dirname(__FILE__));
}

class WPSAdvancedCache
{
    // PHP 8.3: typed class constants
    private const int    DEFAULT_CACHE_LIFETIME = 3600;
    private const string COOKIE_HEADER          = "wordpress_logged_in_";

    private int $cacheLifetime;

    public function __construct()
    {
        // Load plugin-written config so the drop-in respects the admin setting
        // without bootstrapping WordPress.
        $configFile = WP_CONTENT_DIR . "/cache/wps-cache/config.php";
        $config     = is_file($configFile) ? (@include $configFile) : [];
        $config     = is_array($config) ? $config : [];
        $this->cacheLifetime = isset($config["cache_lifetime"])
            ? (int) $config["cache_lifetime"]
            : self::DEFAULT_CACHE_LIFETIME;
    }

    public function execute(): void
    {
        if ($this->shouldBypass()) {
            return;
        }

        $file = $this->getCacheFilePath();

        // is_file() is faster than file_exists() – it skips the directory check.
        if (is_file($file)) {
            $mtime = filemtime($file);
            if (time() - $mtime > $this->cacheLifetime) {
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

        foreach ($_COOKIE as $key => $_value) {
            // PHP 8.0+: str_starts_with() avoids the strpos() === 0 idiom.
            if (
                str_starts_with($key, self::COOKIE_HEADER) ||
                $key === "wp-postpass_" ||
                $key === "comment_author_"
            ) {
                return true;
            }
        }

        // Special paths
        $uri = $_SERVER["REQUEST_URI"] ?? "/";
        // PHP 8.0+: str_contains() is cleaner and avoids !== false checks.
        if (str_contains($uri, "/wp-admin") || str_contains($uri, "/xmlrpc.php")) {
            return true;
        }

        return false;
    }

    private function getCacheFilePath(): string
    {
        $host = preg_replace(
            "/[^a-zA-Z0-9\-\.]/",
            "",
            $_SERVER["HTTP_HOST"] ?? "unknown",
        );
        $uri  = $_SERVER["REQUEST_URI"] ?? "/";
        $path = $this->sanitizePath(parse_url($uri, PHP_URL_PATH) ?: "/");

        // PHP 8.0+: str_ends_with() instead of substr($path, -1) !== "/".
        if (!str_ends_with($path, "/") && !preg_match('/\.[a-z0-9]{2,4}$/i', $path)) {
            $path .= "/";
        }

        $suffix = $this->getMobileSuffix();

        $query = parse_url($uri, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $queryParams);
            ksort($queryParams);
            $filename = "index" . $suffix . "-" . md5(http_build_query($queryParams)) . ".html";
        } else {
            $filename = "index" . $suffix . ".html";
        }

        return WP_CONTENT_DIR . "/cache/wps-cache/html/" . $host . $path . $filename;
    }

    /**
     * Efficiently detects mobile devices based on User-Agent.
     * Must match logic in HTMLCache.php.
     */
    private function getMobileSuffix(): string
    {
        $ua = $_SERVER["HTTP_USER_AGENT"] ?? "";
        if ($ua === "") {
            return "";
        }
        if (preg_match("/(Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi)/i", $ua)) {
            return "-mobile";
        }
        return "";
    }

    private function sanitizePath(string $path): string
    {
        $path     = str_replace(chr(0), "", $path);
        $parts    = explode("/", $path);
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
        $fileSize = (int) filesize($file);
        // Apache-style ETag combining last-modified time and file size.
        $etag         = sprintf('"%x-%x"', $mtime, $fileSize);
        $lastModified = gmdate("D, d M Y H:i:s", $mtime) . " GMT";

        // ETag-based conditional request (strong validator).
        if (
            isset($_SERVER["HTTP_IF_NONE_MATCH"]) &&
            trim($_SERVER["HTTP_IF_NONE_MATCH"]) === $etag
        ) {
            header("HTTP/1.1 304 Not Modified");
            exit();
        }

        // Date-based conditional request (weak validator).
        if (isset($_SERVER["HTTP_IF_MODIFIED_SINCE"])) {
            $ims = strtotime($_SERVER["HTTP_IF_MODIFIED_SINCE"]);
            if ($ims !== false && $mtime < $ims) {
                header("HTTP/1.1 304 Not Modified");
                exit();
            }
        }

        // Serve a pre-compressed gzip file when the client accepts it and the
        // file exists.  This eliminates on-the-fly compression overhead.
        $acceptEncoding = $_SERVER["HTTP_ACCEPT_ENCODING"] ?? "";
        $gzFile         = $file . ".gz";
        $useGzip        = str_contains($acceptEncoding, "gzip") && is_file($gzFile);

        // Prevent the web server / PHP from double-compressing the response.
        if ($useGzip && function_exists("ini_set")) {
            ini_set("zlib.output_compression", "0");
        }

        header("Content-Type: text/html; charset=UTF-8");
        header("Cache-Control: public, max-age=" . $this->cacheLifetime);
        header("ETag: " . $etag);
        header("Last-Modified: " . $lastModified);
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + $this->cacheLifetime) . " GMT");
        // Always advertise Vary so CDNs/proxies store separate copies per encoding.
        header("Vary: Accept-Encoding");
        header("X-WPS-Cache: HIT");

        if ($useGzip) {
            $gzSize = (int) filesize($gzFile);
            header("Content-Encoding: gzip");
            header("Content-Length: " . $gzSize);
            readfile($gzFile);
        } else {
            header("Content-Length: " . $fileSize);
            readfile($file);
        }

        exit();
    }
}

(new WPSAdvancedCache())->execute();
