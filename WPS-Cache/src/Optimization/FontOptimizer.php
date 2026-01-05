<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

/**
 * SOTA Font Optimization.
 *
 * Features:
 * 1. Localize Legacy Google Fonts (Downloads & Caches WOFF2).
 * 2. Enforces 'font-display: swap' on ALL fonts.
 * 3. Handles Unicode Ranges correctly (prevents duplicates).
 * 4. Canonicalizes URLs to prevent cache bloat from ?ver= parameters.
 */
class FontOptimizer
{
    private array $settings;
    private string $fontCacheDir;
    private string $fontCacheUrl;
    private array $cssCache = [];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->fontCacheDir = WPSC_CACHE_DIR . "fonts/";
        $this->fontCacheUrl = content_url("cache/wps-cache/fonts/");

        if (!is_dir($this->fontCacheDir)) {
            @mkdir($this->fontCacheDir, 0755, true);
        }
    }

    public function process(string $html): string
    {
        // 1. Localize Legacy Google Fonts
        if (!empty($this->settings["font_localize_google"])) {
            $html = preg_replace_callback(
                '/<link[^>]*href=[\'"](https?:\/\/fonts\.googleapis\.com\/css[^"\']*)[\'"][^>]*>/i',
                [$this, "localizeGoogleFont"],
                $html,
            );
        }

        // 2. Force 'font-display: swap' (Universal)
        if (!empty($this->settings["font_display_swap"])) {
            // Optimization: Only scan <style> blocks for @font-face rules
            // This prevents scanning the entire HTML body (O(N) vs O(CSS)) and avoids modifying text content.
            $html = preg_replace_callback(
                '/(<style[^>]*>)(.*?)(<\/style>)/is',
                function ($styleMatches) {
                    $open = $styleMatches[1];
                    $content = $styleMatches[2];
                    $close = $styleMatches[3];

                    // Optimization: Fast fail if no @font-face is present
                    // This avoids the expensive regex engine startup for the vast majority of style blocks
                    if (stripos($content, "@font-face") === false) {
                        return $styleMatches[0];
                    }

                    $content = preg_replace_callback(
                        "/@font-face\s*{([^}]+)}/i",
                        function ($matches) {
                            $body = $matches[1];
                            if (stripos($body, "font-display") === false) {
                                return "@font-face {" .
                                    $body .
                                    "; font-display: swap; }";
                            }
                            return $matches[0];
                        },
                        $content,
                    );

                    return $open . $content . $close;
                },
                $html,
            );
        }

        return $html;
    }

    /**
     * Downloads Google Fonts CSS, parses it, downloads WOFF2 files.
     */
    private function localizeGoogleFont(array $matches): string
    {
        $originalTag = $matches[0];
        $rawUrl = html_entity_decode($matches[1]);

        // SOTA: Canonicalize URL to prevent duplicates (remove ver, sort params)
        $url = $this->canonicalizeUrl($rawUrl);

        // Create a cache ID based on the CLEAN URL
        $cacheFilename = md5($url) . ".css";
        $cacheKey = "wpsc_font_css_" . md5($url);

        // 1. Check Runtime Memory Cache
        if (isset($this->cssCache[$cacheKey])) {
            $css = $this->cssCache[$cacheKey];
            return $this->formatCss($css, $cacheFilename);
        }

        // 2. Check Object Cache (Transient)
        $css = get_transient($cacheKey);

        if ($css === false) {
            $cacheFile = $this->fontCacheDir . $cacheFilename;

            // 3. Fallback to File System
            if (file_exists($cacheFile)) {
                $css = file_get_contents($cacheFile);
                if ($css) {
                    set_transient($cacheKey, $css, MONTH_IN_SECONDS);
                }
            } else {
                // 4. Download and Process
                $css = $this->downloadAndProcessFont($url);
                if (!$css) {
                    return $originalTag;
                }
                file_put_contents($cacheFile, $css);
                set_transient($cacheKey, $css, MONTH_IN_SECONDS);
            }
        }

        $this->cssCache[$cacheKey] = $css;

        return $this->formatCss($css, $cacheFilename);
    }

    private function formatCss(string $css, string $filename): string
    {
        return sprintf(
            '<style id="wpsc-local-font-%s">%s</style>',
            substr($filename, 0, 8),
            $css,
        );
    }

    private function downloadAndProcessFont(string $apiUrl): ?string
    {
        // Fetch CSS masquerading as Chrome to get WOFF2 (Modern Format)
        $response = wp_safe_remote_get($apiUrl, [
            "user-agent" =>
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
            "timeout" => 15, // Increased timeout for font processing
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        $css = wp_remote_retrieve_body($response);
        if (empty($css)) {
            return null;
        }

        // Extract and Download Font URLs
        // Regex handles query strings inside url(...) if present
        $css = preg_replace_callback(
            "/url\((https:\/\/fonts\.gstatic\.com\/[^)]+)\)/",
            function ($m) {
                $remoteFontUrl = $m[1];
                return "url(" . $this->downloadFontFile($remoteFontUrl) . ")";
            },
            $css,
        );

        // Ensure display:swap
        if (!empty($this->settings["font_display_swap"])) {
            $css = str_replace("}", ";font-display:swap;}", $css);
        }

        return $css;
    }

    private function downloadFontFile(string $url): string
    {
        // SOTA: Use MD5 of the URL for the filename.
        // This ensures uniqueness even if Google serves different files with same basename,
        // and handles query strings in font URLs safely.
        $ext =
            pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?:
            "woff2";
        $filename = md5($url) . "." . $ext;

        $localPath = $this->fontCacheDir . $filename;
        $localUrl = $this->fontCacheUrl . $filename;

        if (!file_exists($localPath)) {
            $content = wp_safe_remote_get($url);
            if (!is_wp_error($content)) {
                $body = wp_remote_retrieve_body($content);
                file_put_contents($localPath, $body);
            } else {
                return $url; // Fallback to remote if download fails
            }
        }

        return $localUrl;
    }

    /**
     * Cleans Google Font URLs to ensure single cache file per unique font request.
     */
    private function canonicalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts["query"])) {
            return $url;
        }

        parse_str($parts["query"], $params);

        // Remove cache-busting parameters often added by WP themes
        unset(
            $params["ver"],
            $params["version"],
            $params["timestamp"],
            $params["time"],
        );

        // Sort parameters to ensure ?family=A&display=swap == ?display=swap&family=A
        ksort($params);

        // Rebuild URL
        $scheme = isset($parts["scheme"]) ? $parts["scheme"] . "://" : "//";
        $host = $parts["host"] ?? "";
        $path = $parts["path"] ?? "";
        $query = http_build_query($params);

        return $scheme . $host . $path . "?" . $query;
    }
}
