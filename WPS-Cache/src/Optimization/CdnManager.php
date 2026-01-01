<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

/**
 * Handles CDN Rewrites and Cloudflare Edge Cache Clearing.
 *
 * Features:
 * 1. Rewrites static assets (CSS, JS, Images) to CDN URL.
 * 2. Supports 'srcset' for responsive images.
 * 3. Connects to Cloudflare API v4 to purge Edge Cache when local cache is cleared.
 */
class CdnManager
{
    private array $settings;
    private string $siteUrl;
    private string $cdnUrl;
    private array $extensions = [
        "webp",
        "avif",
        "png",
        "jpg",
        "jpeg",
        "gif",
        "svg",
        "css",
        "js",
        "mp4",
        "webm",
        "woff",
        "woff2",
        "ttf",
    ];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->siteUrl = site_url();
        $this->cdnUrl = rtrim($this->settings["cdn_url"] ?? "", "/");
    }

    public function initialize(): void
    {
        // Hook into Cache Clearing to trigger Cloudflare Purge
        add_action("wpsc_cache_cleared", [$this, "purgeCloudflare"], 10, 2);
    }

    /**
     * Rewrites HTML content to point to CDN.
     */
    public function process(string $html): string
    {
        if (empty($this->cdnUrl) || empty($this->settings["cdn_enable"])) {
            return $html;
        }

        // Hostnames
        $siteHost = parse_url($this->siteUrl, PHP_URL_HOST);
        $cdnHost = parse_url($this->cdnUrl, PHP_URL_HOST);

        if (!$siteHost || !$cdnHost || $siteHost === $cdnHost) {
            return $html;
        }

        // Build Extension Regex
        $exts = implode("|", $this->extensions);

        // Regex Explanation:
        // 1. Match attributes: src, href, srcset, data-src (lazy load)
        // 2. Match standard WP paths: wp-content or wp-includes
        // 3. Match allowed extensions
        // 4. Capture the Quote to ensure valid HTML parsing
        $regex =
            '#\b(src|href|srcset|data-src|data-srcset)=([\'"])(https?:\/\/' .
            preg_quote($siteHost, "#") .
            ')?\/([^"\']+\.(' .
            $exts .
            '))([?#][^"\']*)?\2#i';

        return preg_replace_callback(
            $regex,
            function ($matches) {
                $attr = $matches[1];
                $quote = $matches[2];
                $path = $matches[4]; // The path after domain
                $query = $matches[6] ?? "";

                // Exclude Admin or specific keywords
                if (
                    strpos($path, "wp-admin") !== false ||
                    strpos($path, "preview=true") !== false
                ) {
                    return $matches[0];
                }

                // Specific exclusion settings logic could go here

                // Rebuild URL
                $newUrl = $this->cdnUrl . "/" . $path . $query;

                return sprintf("%s=%s%s%s", $attr, $quote, $newUrl, $quote);
            },
            $html,
        );
    }

    /**
     * Purges Cloudflare Cache via API v4.
     */
    public function purgeCloudflare(bool $success, array $log): void
    {
        if (
            empty($this->settings["cf_enable"]) ||
            empty($this->settings["cf_zone_id"]) ||
            empty($this->settings["cf_api_token"])
        ) {
            return;
        }

        $zoneId = $this->settings["cf_zone_id"];
        $token = $this->settings["cf_api_token"];
        $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";

        $body = json_encode(["purge_everything" => true]);

        $response = wp_remote_post($url, [
            "method" => "POST",
            "headers" => [
                "Authorization" => "Bearer " . $token,
                "Content-Type" => "application/json",
            ],
            "body" => $body,
            "timeout" => 5,
        ]);

        if (is_wp_error($response)) {
            error_log(
                "WPS Cache: Cloudflare Purge Failed - " .
                    $response->get_error_message(),
            );
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                error_log(
                    "WPS Cache: Cloudflare API Error (" .
                        $code .
                        ") - " .
                        wp_remote_retrieve_body($response),
                );
            }
        }
    }
}
