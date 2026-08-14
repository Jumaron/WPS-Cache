<?php

declare(strict_types=1);

namespace WPSCache\Integration\Cloudflare;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;

/** Invalidates Cloudflare's edge cache after a local cache purge. */
final class CloudflarePurger implements Module
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'cloudflare';
    }

    public function boot(): void
    {
        if ($this->configured()) {
            add_action('wpsc_cache_cleared', [$this, 'purge'], 10, 0);
        }
    }

    public function purge(): void
    {
        if (!$this->configured()) {
            return;
        }

        $url = sprintf(
            'https://api.cloudflare.com/client/v4/zones/%s/purge_cache',
            rawurlencode($this->settings->string('cf_zone_id')),
        );
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->settings->string('cf_api_token'),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode(['purge_everything' => true]),
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            error_log('[WPS-Cache] Cloudflare purge failed: ' . $response->get_error_message());
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            error_log(sprintf('[WPS-Cache] Cloudflare purge returned HTTP %d.', $status));
        }
    }

    private function configured(): bool
    {
        return $this->settings->enabled('cf_enable')
            && $this->settings->string('cf_zone_id') !== ''
            && $this->settings->string('cf_api_token') !== '';
    }
}
