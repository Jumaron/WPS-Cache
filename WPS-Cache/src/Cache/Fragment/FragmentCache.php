<?php

declare(strict_types=1);

namespace WPSCache\Cache\Fragment;

/** Public fragment-cache API for themes and integrations. */
final class FragmentCache
{
    public static function remember(string $key, callable $producer, int $ttl = 300, string $group = 'wps-cache-fragments'): mixed
    {
        $found = false;
        $value = wp_cache_get($key, $group, false, $found);
        if ($found) {
            return $value;
        }
        $value = $producer();
        wp_cache_set($key, $value, $group, max(1, $ttl));
        return $value;
    }

    public static function forget(string $key, string $group = 'wps-cache-fragments'): bool
    {
        return wp_cache_delete($key, $group);
    }

    public static function placeholder(string $key, string $fallback = ''): string
    {
        $key = sanitize_key($key);
        if ($key === '') {
            return $fallback;
        }
        $endpoint = add_query_arg('token', self::token($key), rest_url('wps-cache/v1/fragment/' . $key));
        return '<span data-wpsc-fragment="' . esc_url($endpoint) . '">' . $fallback . '</span>';
    }

    public static function token(string $key): string
    {
        return hash_hmac('sha256', home_url('/') . '|fragment|' . sanitize_key($key), wp_salt('nonce'));
    }
}
