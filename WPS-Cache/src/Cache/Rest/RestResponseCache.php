<?php

declare(strict_types=1);

namespace WPSCache\Cache\Rest;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;
use WPSCache\Contracts\Purgeable;

/** Caches successful anonymous GET responses for explicitly allowed REST routes. */
final class RestResponseCache implements Module, Purgeable
{
    private const INDEX_OPTION = 'wpsc_rest_cache_keys';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'rest';
    }

    public function boot(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'read'], 10, 3);
        add_filter('rest_post_dispatch', [$this, 'write'], 10, 3);
    }

    public function read(mixed $result, mixed $server, mixed $request): mixed
    {
        if ($result !== null || !$this->cacheableRequest($request)) {
            return $result;
        }
        $cached = get_transient($this->key($request));
        if (!is_array($cached) || !array_key_exists('data', $cached)) {
            return null;
        }
        $response = rest_ensure_response($cached['data']);
        if (method_exists($response, 'set_status')) {
            $response->set_status((int) ($cached['status'] ?? 200));
        }
        if (method_exists($response, 'header')) {
            $response->header('X-WPS-REST-Cache', 'HIT');
        }
        return $response;
    }

    public function write(mixed $response, mixed $server, mixed $request): mixed
    {
        if (!$this->cacheableRequest($request) || !is_object($response)) {
            return $response;
        }
        $status = method_exists($response, 'get_status') ? (int) $response->get_status() : 200;
        if ($status < 200 || $status >= 300 || !method_exists($response, 'get_data')) {
            return $response;
        }
        $data = $response->get_data();
        $encoded = wp_json_encode($data);
        if (!is_string($encoded) || strlen($encoded) > 1024 * 1024) {
            return $response;
        }
        $key = $this->key($request);
        set_transient($key, ['status' => $status, 'data' => $data], $this->settings->integer('rest_cache_ttl'));
        $keys = get_option(self::INDEX_OPTION, []);
        $keys = is_array($keys) ? $keys : [];
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            update_option(self::INDEX_OPTION, array_slice($keys, -5000), false);
        }
        if (method_exists($response, 'header')) {
            $response->header('X-WPS-REST-Cache', 'MISS');
        }
        return $response;
    }

    public function purge(): void
    {
        $keys = get_option(self::INDEX_OPTION, []);
        foreach (is_array($keys) ? $keys : [] as $key) {
            if (is_string($key)) {
                delete_transient($key);
            }
        }
        delete_option(self::INDEX_OPTION);
    }

    private function cacheableRequest(mixed $request): bool
    {
        if (!$this->settings->enabled('rest_cache') || is_user_logged_in() || !is_object($request)) {
            return false;
        }
        $method = method_exists($request, 'get_method') ? strtoupper((string) $request->get_method()) : '';
        $route = method_exists($request, 'get_route') ? (string) $request->get_route() : '';
        if ($method !== 'GET' || $route === '') {
            return false;
        }
        $patterns = $this->settings->strings('rest_cache_routes');
        if ($patterns === []) {
            return !str_starts_with($route, '/wp/v2/users');
        }
        foreach ($patterns as $pattern) {
            if (preg_match('/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i', $route) === 1) {
                return true;
            }
        }
        return false;
    }

    private function key(object $request): string
    {
        $route = method_exists($request, 'get_route') ? (string) $request->get_route() : '';
        $params = method_exists($request, 'get_query_params') ? (array) $request->get_query_params() : [];
        ksort($params);
        return 'wpsc_rest_' . md5($route . '?' . http_build_query($params));
    }
}
