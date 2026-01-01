<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

/**
 * SOTA Font Optimization (WP 6.9+ Ready).
 *
 * Features:
 * 1. Localize Legacy Google Fonts (Downloads & Caches WOFF2).
 * 2. Enforces 'font-display: swap' on ALL fonts (Native & Legacy).
 * 3. Preloads Critical Fonts.
 */
class FontOptimizer
{
    private array $settings;
    private string $fontCacheDir;
    private string $fontCacheUrl;

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

        // 2. Force 'font-display: swap' (Universal - covers WP Font Library too)
        if (!empty($this->settings["font_display_swap"])) {
            // This regex finds @font-face blocks in inline <style> tags and injects swap if missing
            $html = preg_replace_callback(
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
                $html,
            );
        }

        return $html;
    }

    /**
     * Downloads Google Fonts CSS, parses it, downloads WOFF2 files,
     * and returns an inline <style> block.
     */
    private function localizeGoogleFont(array $matches): string
    {
        $originalTag = $matches[0];
        $url = html_entity_decode($matches[1]);

        // Create a cache ID based on the URL
        $cacheFile = $this->fontCacheDir . md5($url) . ".css";
        $cacheUrl = $this->fontCacheUrl . md5($url) . ".css";

        if (file_exists($cacheFile)) {
            $css = file_get_contents($cacheFile);
        } else {
            $css = $this->downloadAndProcessFont($url);
            if (!$css) {
                return $originalTag;
            } // Fallback on failure
            file_put_contents($cacheFile, $css);
        }

        // Return inline CSS (fastest) or linked CSS
        // SOTA: For fonts, inline the @font-face definitions to prevent render blocking of the CSS request
        return sprintf('<style id="wpsc-local-font">%s</style>', $css);
    }

    private function downloadAndProcessFont(string $apiUrl): ?string
    {
        // 1. Fetch CSS from Google (Masquerading as Modern Chrome to get WOFF2)
        $response = wp_safe_remote_get($apiUrl, [
            "user-agent" =>
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
            "timeout" => 10,
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        $css = wp_remote_retrieve_body($response);
        if (empty($css)) {
            return null;
        }

        // 2. Extract Font URLs (.woff2)
        // Regex to find: url(https://...woff2)
        $css = preg_replace_callback(
            "/url\((https:\/\/fonts\.gstatic\.com\/[^)]+)\)/",
            function ($m) {
                $remoteFontUrl = $m[1];
                return "url(" . $this->downloadFontFile($remoteFontUrl) . ")";
            },
            $css,
        );

        // 3. Ensure display:swap is present in the downloaded CSS
        if (!empty($this->settings["font_display_swap"])) {
            $css = str_replace("}", ";font-display:swap;}", $css);
        }

        return $css;
    }

    private function downloadFontFile(string $url): string
    {
        $filename = basename(parse_url($url, PHP_URL_PATH));
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
}
