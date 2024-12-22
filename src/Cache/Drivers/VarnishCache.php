<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Varnish cache implementation for HTTP-level caching
 */
final class VarnishCache implements CacheDriverInterface {
    private string $cache_tag_prefix;
    
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6081,
        private readonly int $cache_lifetime = 604800
    ) {
        $this->cache_tag_prefix = 'c714'; // Get from settings if needed
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
    
    public function delete(string $key): void {
        // In Varnish context, delete is equivalent to purging a specific URL
        try {
            $this->purgeUrl($key);
        } catch (\Exception $e) {
            error_log('Varnish delete failed: ' . $e->getMessage());
        }
    }
    
    public function initialize(): void {
        // Add Varnish cache headers
        add_action('send_headers', function() {
            if (!is_user_logged_in() && !is_admin()) {
                header('X-Cache-Tags: ' . $this->cache_tag_prefix);
                header('Cache-Control: public, max-age=' . $this->cache_lifetime);
            }
        });
    }
    
    public function get(string $key): mixed {
        // Varnish handles caching at the HTTP level
        return null;
    }
    
    public function set(string $key, mixed $value, int $ttl = 3600): void {
        // Varnish handles caching at the HTTP level
    }
    
    public function clear(): void {
        $this->purgeAll();
    }
    
    private function purgeAll(): void {
        try {
            // Purge by host
            $site_url = get_site_url();
            $host = parse_url($site_url, PHP_URL_HOST);
            $this->purgeByHost($host);
            
            // Purge by cache tag
            if (!empty($this->cache_tag_prefix)) {
                $this->purgeByTag($this->cache_tag_prefix);
            }
        } catch (\Exception $e) {
            error_log('Varnish purge failed: ' . $e->getMessage());
        }
    }
    
    private function purgeByHost(string $host): void {
        $request_url = sprintf('http://%s:%d', $this->host, $this->port);
        
        $response = wp_remote_request($request_url, [
            'method' => 'PURGE',
            'headers' => [
                'Host' => $host,
                'X-Purge-Method' => 'regex',
                'X-Purge-Debug' => 'true'
            ],
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            throw new \Exception("Varnish purge by host failed with code: $http_code");
        }
    }
    
    private function purgeByTag(string $tag): void {
        $request_url = sprintf('http://%s:%d', $this->host, $this->port);
        
        $response = wp_remote_request($request_url, [
            'method' => 'PURGE',
            'headers' => [
                'X-Cache-Tags' => $tag,
                'X-Purge-Method' => 'regex',
                'X-Purge-Debug' => 'true'
            ],
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            throw new \Exception("Varnish purge by tag failed with code: $http_code");
        }
    }

    public function purgeUrl(string $url): void {
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['host'])) {
            throw new \Exception('Invalid URL');
        }

        $request_url = sprintf('http://%s:%d', $this->host, $this->port);
        if (isset($parsed_url['path'])) {
            $path = $parsed_url['path'];
            $request_url .= '/' === $path ? '' : '/' . ltrim($path, '/');
        }

        if (isset($parsed_url['query'])) {
            $request_url .= '?' . $parsed_url['query'];
        }

        $response = wp_remote_request($request_url, [
            'method' => 'PURGE',
            'headers' => [
                'Host' => $parsed_url['host'],
                'X-Purge-Method' => 'exact',
                'X-Purge-Debug' => 'true'
            ],
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            throw new \Exception("Varnish purge URL failed with code: $http_code");
        }
    }
}