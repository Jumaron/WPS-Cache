<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Varnish cache implementation for HTTP-level caching
 */
final class VarnishCache extends AbstractCacheDriver {
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 6081;
    private const DEFAULT_CACHE_LIFETIME = 604800; // 1 week
    private const DEFAULT_TIMEOUT = 5;

    private string $host;
    private int $port;
    private int $cache_lifetime;
    private string $cache_tag_prefix;
    
    public function __construct(
        string $host = self::DEFAULT_HOST,
        int $port = self::DEFAULT_PORT,
        int $cache_lifetime = self::DEFAULT_CACHE_LIFETIME
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->cache_lifetime = $cache_lifetime;
        $this->cache_tag_prefix = 'c714'; // Get from settings if needed
    }

    public function initialize(): void {
        if (!$this->initialized) {
            add_action('send_headers', [$this, 'addCacheHeaders']);
            $this->initialized = true;
        }
    }

    public function addCacheHeaders(): void {
        if (!is_user_logged_in() && !is_admin()) {
            header('X-Cache-Tags: ' . $this->cache_tag_prefix);
            header('Cache-Control: public, max-age=' . $this->cache_lifetime);
        }
    }

    public function isConnected(): bool {
        try {
            $response = wp_remote_get(sprintf('http://%s:%d', $this->host, $this->port), [
                'timeout'   => self::DEFAULT_TIMEOUT,
                'sslverify' => false
            ]);
            
            return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        } catch (\Throwable $e) {
            $this->logError('Varnish connection check failed', $e);
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
        } catch (\Throwable $e) {
            $this->logError('Failed to purge URL: ' . $key, $e);
        }
    }
    
    public function clear(): void {
        try {
            $this->purgeAll();
        } catch (\Throwable $e) {
            $this->logError('Failed to purge all cache', $e);
        }
    }

    private function purgeAll(): void {
        try {
            // Purge by host
            $site_url = get_site_url();
            $host = wp_parse_url($site_url, PHP_URL_HOST);
            
            if ($host) {
                $this->purgeByHost($host);
            }
            
            // Purge by cache tag
            if (!empty($this->cache_tag_prefix)) {
                $this->purgeByTag($this->cache_tag_prefix);
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to purge all cache', $e);
            throw $e;
        }
    }

    private function purgeByHost(string $host): void {
        $this->sendPurgeRequest([
            'Host'            => $host,
            'X-Purge-Method'  => 'regex',
            'X-Purge-Debug'   => 'true'
        ]);
    }

    private function purgeByTag(string $tag): void {
        $this->sendPurgeRequest([
            'X-Cache-Tags'    => $tag,
            'X-Purge-Method'  => 'regex',
            'X-Purge-Debug'   => 'true'
        ]);
    }

    public function purgeUrl(string $url): void {
        $parsed_url = wp_parse_url($url);
        if (!isset($parsed_url['host'])) {
            throw new \Exception('Invalid URL provided for purge');
        }

        $request_url = $this->buildPurgeUrl($parsed_url);
        
        $this->sendPurgeRequest([
            'Host'            => $parsed_url['host'],
            'X-Purge-Method'  => 'exact',
            'X-Purge-Debug'   => 'true'
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

    private function sendPurgeRequest(array $headers, ?string $custom_url = null): void {
        $request_url = $custom_url ?? sprintf('http://%s:%d', $this->host, $this->port);
        
        $response = wp_remote_request($request_url, [
            'method'    => 'PURGE',
            'headers'   => $headers,
            'timeout'   => self::DEFAULT_TIMEOUT,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            throw new \Exception( esc_html($response->get_error_message()) );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            throw new \Exception(
                esc_html(sprintf(
                    "Varnish purge failed with code %d for URL: %s",
                    $http_code,
                    $request_url
                ))
            );
        }
    }
}