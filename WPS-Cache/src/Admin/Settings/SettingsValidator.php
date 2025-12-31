<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

/**
 * Validates and sanitizes settings for WPS Cache
 */
class SettingsValidator
{
    /**
     * Sanitizes the entire settings array
     *
     * @param array $input Raw settings array
     * @return array Sanitized settings
     */
    public function sanitizeSettings($input): array
    {
        // If input is not an array, initialize it
        if (!is_array($input)) {
            $input = [];
        }

        // Extract settings from the wpsc_settings wrapper if present
        $settings = isset($input['wpsc_settings']) ? $input['wpsc_settings'] : $input;

        return [
            // Basic settings
            'html_cache'    => (bool)($settings['html_cache'] ?? false),
            'redis_cache'   => (bool)($settings['redis_cache'] ?? false),
            'varnish_cache' => (bool)($settings['varnish_cache'] ?? false),
            'css_minify'    => (bool)($settings['css_minify'] ?? false),
            'js_minify'     => (bool)($settings['js_minify'] ?? false),

            // --- NEW SOTA SETTINGS ---
            'css_async'     => (bool)($settings['css_async'] ?? false),
            'js_defer'      => (bool)($settings['js_defer'] ?? false),
            'js_delay'      => (bool)($settings['js_delay'] ?? false),
            // -------------------------

            'cache_lifetime' => $this->sanitizeCacheLifetime($settings['cache_lifetime'] ?? 3600),

            // URL and CSS settings
            'excluded_urls' => $this->sanitizeUrls($settings['excluded_urls'] ?? []),
            'excluded_css' => $this->sanitizeCssSelectors($settings['excluded_css'] ?? []),
            'excluded_js' => $this->sanitizeJsSelectors($settings['excluded_js'] ?? []),

            // Redis settings
            'redis_host' => $this->sanitizeHost($settings['redis_host'] ?? '127.0.0.1'),
            'redis_port' => $this->sanitizePort($settings['redis_port'] ?? 6379),
            'redis_db' => $this->sanitizeRedisDb($settings['redis_db'] ?? 0),
            'redis_password' => $this->sanitizeRedisPassword($settings),
            'redis_prefix' => $this->sanitizeRedisPrefix($settings['redis_prefix'] ?? 'wpsc:'),
            'redis_persistent' => (bool)($settings['redis_persistent'] ?? false),
            'redis_compression' => (bool)($settings['redis_compression'] ?? true),

            // Varnish settings
            'varnish_host' => $this->sanitizeHost($settings['varnish_host'] ?? '127.0.0.1'),
            'varnish_port' => $this->sanitizePort($settings['varnish_port'] ?? 6081),

            // Preload settings
            'preload_urls' => $this->sanitizeUrls($settings['preload_urls'] ?? []),
            'preload_interval' => $this->sanitizeInterval($settings['preload_interval'] ?? 'daily'),

            // Metrics settings
            'enable_metrics' => (bool)($settings['enable_metrics'] ?? true),
            'metrics_retention' => $this->sanitizeMetricsRetention($settings['metrics_retention'] ?? 30),

            // Advanced settings
            'advanced_settings' => $this->sanitizeAdvancedSettings($settings['advanced_settings'] ?? [])
        ];
    }

    private function sanitizeCacheLifetime(mixed $lifetime): int
    {
        $lifetime = (int)$lifetime;
        return max(60, min(2592000, $lifetime));
    }

    private function sanitizeUrls(array|string $urls): array
    {
        if (is_string($urls)) {
            $urls = explode("\n", $urls);
        }

        return array_filter(array_map(function ($url) {
            $url = trim($url);
            return filter_var($url, FILTER_VALIDATE_URL) ? esc_url_raw($url) : '';
        }, $urls));
    }

    private function sanitizeCssSelectors(array|string $selectors): array
    {
        if (is_string($selectors)) {
            $selectors = explode("\n", $selectors);
        }

        return array_filter(array_map(function ($selector) {
            return sanitize_text_field(trim($selector));
        }, $selectors));
    }

    private function sanitizeJsSelectors(array|string $selectors): array
    {
        if (is_string($selectors)) {
            $selectors = explode("\n", $selectors);
        }

        return array_filter(array_map(function ($selector) {
            return sanitize_text_field(trim($selector));
        }, $selectors));
    }

    private function sanitizeHost(string $host): string
    {
        $host = trim($host);
        if ($host === 'localhost') {
            return $host;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) || filter_var($host, FILTER_VALIDATE_DOMAIN)) {
            return $host;
        }
        return '127.0.0.1';
    }

    private function sanitizePort(mixed $port): int
    {
        $port = (int)$port;
        return max(1, min(65535, $port));
    }

    private function sanitizeRedisDb(mixed $db): int
    {
        $db = (int)$db;
        return max(0, min(15, $db));
    }

    private function sanitizeRedisPassword(array $settings): string
    {
        if (isset($settings['redis_password'])) {
            if ($settings['redis_password'] === '••••••••') {
                $old_settings = get_option('wpsc_settings', []);
                return $old_settings['redis_password'] ?? '';
            }
            return sanitize_text_field($settings['redis_password']);
        }
        return '';
    }

    private function sanitizeRedisPrefix(string $prefix): string
    {
        $prefix = sanitize_text_field($prefix);
        return empty($prefix) ? 'wpsc:' : $prefix;
    }

    private function sanitizeInterval(string $interval): string
    {
        return in_array($interval, ['hourly', 'daily', 'weekly']) ? $interval : 'daily';
    }

    private function sanitizeMetricsRetention(mixed $days): int
    {
        $days = (int)$days;
        return max(1, min(90, $days));
    }

    private function sanitizeAdvancedSettings(array $settings): array
    {
        return [
            'object_cache_alloptions_limit' => $this->sanitizeAllOptionsLimit($settings['object_cache_alloptions_limit'] ?? 1000),
            'max_ttl' => $this->sanitizeMaxTTL($settings['max_ttl'] ?? 86400),
            'cache_groups' => $this->sanitizeCacheGroups($settings['cache_groups'] ?? []),
            'ignored_groups' => $this->sanitizeCacheGroups($settings['ignored_groups'] ?? [])
        ];
    }

    private function sanitizeAllOptionsLimit(mixed $limit): int
    {
        $limit = (int)$limit;
        return max(100, min(5000, $limit));
    }

    private function sanitizeMaxTTL(mixed $ttl): int
    {
        $ttl = (int)$ttl;
        return max(3600, min(2592000, $ttl));
    }

    private function sanitizeCacheGroups(array $groups): array
    {
        return array_filter(array_map(function ($group) {
            return sanitize_text_field(trim($group));
        }, $groups));
    }
}
