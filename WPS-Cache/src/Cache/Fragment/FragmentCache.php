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
}
