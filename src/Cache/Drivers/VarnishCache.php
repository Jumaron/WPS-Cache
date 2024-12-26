<?php
/**
 * Varnish cache implementation for HTTP-level caching
 */
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use WPSCache\Cache\Abstracts\AbstractCacheDriver;

final class VarnishCache extends AbstractCacheDriver {
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6081,
        private readonly string $cache_tag_prefix = 'c714'
    ) {
        parent::__construct(WPSC_CACHE_DIR . 'varnish');
    }

    protected function getFileExtension(): string {
        return '.vcl';
    }

    protected function doInitialize(): void {
        add_action('send_headers', function() {
            if (!is_user_logged_in() && !is_admin()) {
                header('X-Cache-Tags: ' . $this->cache_tag_prefix);
                header('Cache-Control: public, max-age=' . ($this->settings['cache_lifetime'] ?? 3600));
            }
        });
    }

    public function isConnected(): bool {
        try {
            $response = wp_remote_get(sprintf('http://%s:%d', $this->host, $this->port), [
                'timeout' => 5,
                'sslverify' => false
            ]);
            
            return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function get(string $key): mixed {
        // Varnish handles caching at the HTTP level
        return null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {
        // Varnish handles caching at the HTTP level
    }

    public function delete(string $key): void {
        try {
            $this->purgeUrl($key);
        } catch (\Exception $e) {
            error_log('Varnish purge failed: ' . $e->getMessage());
        }
    }

    public function clear(): void {
        try {
            $site_url = get_site_url();
            $host = parse_url($site_url, PHP_URL_HOST);
            
            $this->purgeByHost($host);
            
            if (!empty($this->cache_tag_prefix)) {
                $this->purgeByTag($this->cache_tag_prefix);
            }
        } catch (\Exception $e) {
            error_log('Varnish purge failed: ' . $e->getMessage());
        }
    }

    private function purgeByHost(string $host): void {
        $this->sendPurgeRequest([
            'Host' => $host,
            'X-Purge-Method' => 'regex',
            'X-Purge-Debug' => 'true'
        ]);
    }

    private function purgeByTag(string $tag): void {
        $this->sendPurgeRequest([
            'X-Cache-Tags' => $tag,
            'X-Purge-Method' => 'regex',
            'X-Purge-Debug' => 'true'
        ]);
    }

    public function purgeUrl(string $url): void {
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['host'])) {
            throw new \Exception('Invalid URL');
        }

        $request_url = $this->buildPurgeUrl($parsed_url);
        $this->sendPurgeRequest([
            'Host' => $parsed_url['host'],
            'X-Purge-Method' => 'exact',
            'X-Purge-Debug' => 'true'
        ], $request_url);
    }

    private function buildPurgeUrl(array $parsed_url): string {
        $request_url = sprintf('http://%s:%d', $this->host, $this->port);
        
        if (isset($parsed_url['path'])) {
            $path = $parsed_url['path'];
            $request_url .= '/' === $path ? '' : '/' . ltrim($path, '/');
        }

        if (isset($parsed_url['query'])) {
            $request_url .= '?' . $parsed_url['query'];
        }

        return $request_url;
    }

    private function sendPurgeRequest(array $headers, ?string $url = null): void {
        $url ??= sprintf('http://%s:%d', $this->host, $this->port);
        
        $response = wp_remote_request($url, [
            'method' => 'PURGE',
            'headers' => $headers,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            throw new \Exception("Varnish purge failed with code: $http_code");
        }
    }
}