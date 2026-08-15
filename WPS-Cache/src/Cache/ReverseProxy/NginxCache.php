<?php

declare(strict_types=1);

namespace WPSCache\Cache\ReverseProxy;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;
use WPSCache\Contracts\Purgeable;

final class NginxCache implements Module, Purgeable
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'nginx';
    }

    public function boot(): void
    {
        add_action('wpsc_cache_url_cleared', [$this, 'purgeUrl'], 10, 1);
    }

    public function purge(): void
    {
        $this->request('/*');
    }

    public function purgeUrl(string $url): void
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $this->request($path);
    }

    private function request(string $path): void
    {
        $url = sprintf('http://%s:%d%s?path=%s', $this->settings->string('nginx_host'), $this->settings->integer('nginx_port'), $this->settings->string('nginx_purge_path'), rawurlencode($path));
        $response = wp_remote_request($url, ['method' => 'PURGE', 'timeout' => 3, 'blocking' => false]);
        if (is_wp_error($response)) {
            error_log('[WPS-Cache] Nginx purge failed: ' . $response->get_error_message());
        }
    }
}
