<?php

declare(strict_types=1);

namespace WPSCache\Cache\ReverseProxy;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;
use WPSCache\Contracts\Purgeable;
use WPSCache\Support\AbstractFilesystemModule;

/**
 * Handles Varnish HTTP Caching via Purge Headers and Tagging.
 * Implements 'Smart Purge' by tagging posts/archives.
 */
final class VarnishCache extends AbstractFilesystemModule implements Module, Purgeable
{
    private string $host;
    private int $port;
    private int $defaultTtl;

    public function __construct(
        Settings|array $settings,
        string $host = "127.0.0.1",
        int $port = 6081,
        int $defaultTtl = 604800,
    ) {
        // 1 week
        parent::__construct($settings);
        $this->host = $host;
        $this->port = $port;
        $this->defaultTtl = $defaultTtl;
    }

    public function id(): string
    {
        return 'varnish';
    }

    public function boot(): void
    {
        // Add headers on frontend requests
        if (!is_admin()) {
            add_action("send_headers", [$this, "addCacheHeaders"]);
        }

    }

    /**
     * Sends X-Cache-Tags and Cache-Control headers.
     * Varnish uses these tags to know which cached pages belong to which ID.
     */
    public function addCacheHeaders(): void
    {
        if (is_user_logged_in() || is_feed() || is_trackback()) {
            header("X-Do-Not-Cache: true");
            return;
        }

        $tags = ["global"];

        if (is_singular()) {
            $id = get_the_ID();
            $tags[] = "post-{$id}";

            // Add category tags
            $categories = get_the_category($id);
            if ($categories) {
                foreach ($categories as $cat) {
                    $tags[] = "term-{$cat->term_id}";
                }
            }
        } elseif (is_archive() || is_home()) {
            $tags[] = "archive";
            if (is_category() || is_tag() || is_tax()) {
                $id = get_queried_object_id();
                $tags[] = "term-{$id}";
            }
        }

        header("X-Cache-Tags: " . implode(",", $tags));
        header("Cache-Control: public, max-age=" . $this->defaultTtl);
    }

    /**
     * Purges a specific post and its associated taxonomy archives.
     */
    public function purgePost(int $post_id): void
    {
        if (defined("DOING_AUTOSAVE") && DOING_AUTOSAVE) {
            return;
        }

        // 1. Purge the post itself
        $this->sendPurgeRequest(["X-Purge-Tags" => "post-{$post_id}"]);

        // 2. Purge Homepage/Archives (generic)
        $this->sendPurgeRequest(["X-Purge-Tags" => "archive"]);

        // 3. Purge Categories associated with this post
        $categories = get_the_category($post_id);
        if ($categories) {
            foreach ($categories as $cat) {
                $this->sendPurgeRequest([
                    "X-Purge-Tags" => "term-{$cat->term_id}",
                ]);
            }
        }
    }

    /**
     * Purges everything by banning all tags.
     */
    public function purgeAll(): void
    {
        $this->sendPurgeRequest([
            "X-Purge-Method" => "regex",
            "X-Purge-Tags" => ".*",
        ]);
    }

    /**
     * Sends the raw HTTP PURGE request to Varnish.
     */
    private function sendPurgeRequest(array $headers): void
    {
        $url = sprintf("http://%s:%d/", $this->host, $this->port);

        // Merge standard headers
        $headers = array_merge(
            [
                "Host" => $_SERVER["HTTP_HOST"] ?? "localhost",
                "User-Agent" => "WPS-Cache-Purger/1.0",
            ],
            $headers,
        );

        wp_remote_request($url, [
            "method" => "PURGE",
            "headers" => $headers,
            "blocking" => false, // Async: Don't wait for Varnish to reply
            "timeout" => 1,
            "sslverify" => false,
        ]);
    }

    public function purge(): void
    {
        $this->purgeAll();
    }
}
