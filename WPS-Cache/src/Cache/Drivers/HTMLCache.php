<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use DOMDocument;
use WPSCache\Optimization\JSOptimizer;
use WPSCache\Optimization\AsyncCSS;
use WPSCache\Optimization\MediaOptimizer;
use WPSCache\Optimization\FontOptimizer;
use WPSCache\Optimization\CdnManager;
use WPSCache\Optimization\CriticalCSSManager;
use WPSCache\Compatibility\CommerceManager;

final class HTMLCache extends AbstractCacheDriver
{
    private string $cacheDir;
    private ?string $exclusionRegex = null;

    private ?CommerceManager $commerceManager;
    private const BYPASS_PARAMS = ["add-to-cart", "wp_nonce", "preview", "s"];

    public function __construct(?CommerceManager $commerceManager = null)
    {
        parent::__construct();
        $this->commerceManager = $commerceManager;
        $this->cacheDir = defined("WPSC_CACHE_DIR")
            ? WPSC_CACHE_DIR . "html/"
            : WP_CONTENT_DIR . "/cache/wps-cache/html/";
        $this->ensureDirectory($this->cacheDir);

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

        if ($this->commerceManager && $this->commerceManager->shouldBypass()) {
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

        // --- PHASE 1: DOM MANIPULATION (Robust & Safe) ---
        // We load the DOM once and pass it to all structure-modifying optimizers.

        $useDomPipeline =
            !empty($this->settings["remove_unused_css"]) ||
            !empty($this->settings["js_delay"]) ||
            !empty($this->settings["js_defer"]) ||
            !empty($this->settings["css_async"]);

        $content = $buffer;

        if ($useDomPipeline) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            // Hack: force UTF-8
            @$dom->loadHTML(
                '<?xml encoding="utf-8" ?>' . $content,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
            libxml_clear_errors();

            // 1. Remove Unused CSS
            if (!empty($this->settings["remove_unused_css"])) {
                try {
                    $cssShaker = new CriticalCSSManager($this->settings);
                    $cssShaker->processDom($dom);
                } catch (\Throwable $e) {
                }
            }

            // 2. JS Optimization (Delay/Defer)
            if (
                !empty($this->settings["js_delay"]) ||
                !empty($this->settings["js_defer"])
            ) {
                try {
                    $jsOpt = new JSOptimizer($this->settings);
                    $jsOpt->processDom($dom);
                } catch (\Throwable $e) {
                }
            }

            // 3. Async CSS
            if (!empty($this->settings["css_async"])) {
                try {
                    $cssOpt = new AsyncCSS($this->settings);
                    $cssOpt->processDom($dom);
                } catch (\Throwable $e) {
                }
            }

            $content = $dom->saveHTML();
            // Remove UTF-8 hack
            $content = str_replace('<?xml encoding="utf-8" ?>', "", $content);
        }

        // --- PHASE 2: STRING/REGEX MANIPULATION ---
        // These are safer as regex or don't require full DOM awareness

        // 4. CDN Rewrite
        try {
            $cdnManager = new CdnManager($this->settings);
            $content = $cdnManager->process($content);
        } catch (\Throwable $e) {
        }

        // 5. Font Optimization
        try {
            $fontOpt = new FontOptimizer($this->settings);
            $content = $fontOpt->process($content);
        } catch (\Throwable $e) {
        }

        // 6. Media Optimization
        try {
            $mediaOpt = new MediaOptimizer($this->settings);
            $content = $mediaOpt->process($content);
        } catch (\Throwable $e) {
        }

        // Add Timestamp & Signature
        $deviceType = $this->getMobileSuffix() ? "Mobile" : "Desktop";
        $content .= sprintf(
            "\n<!-- WPS Cache: %s (%s) -->",
            gmdate("Y-m-d H:i:s"),
            $deviceType,
        );

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

        $suffix = $this->getMobileSuffix();
        $query = parse_url($uri, PHP_URL_QUERY);

        if ($query) {
            parse_str($query, $queryParams);
            ksort($queryParams);
            $filename =
                "index" .
                $suffix .
                "-" .
                md5(http_build_query($queryParams)) .
                ".html";
        } else {
            $filename = "index" . $suffix . ".html";
        }

        $fullPath = $this->cacheDir . $host . $path;
        if (substr($fullPath, -1) !== "/") {
            $fullPath .= "/";
        }

        $this->atomicWrite($fullPath . $filename, $content);
    }

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
