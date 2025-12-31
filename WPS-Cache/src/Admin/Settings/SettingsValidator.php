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
     * FIX: Merges existing settings to prevent data loss across tabs
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
        $new_settings = isset($input['wpsc_settings']) ? $input['wpsc_settings'] : $input;

        // 1. Fetch existing settings from DB to preserve values from other tabs
        $current_settings = get_option('wpsc_settings', []);

        // 2. Helper function to get value: Input > DB > Default
        $get_val = function ($key, $default) use ($new_settings, $current_settings) {
            if (isset($new_settings[$key])) {
                return $new_settings[$key];
            }
            return $current_settings[$key] ?? $default;
        };

        return [
            // Basic settings
            'html_cache'    => (bool)$get_val('html_cache', false),
            'redis_cache'   => (bool)$get_val('redis_cache', false),
            'varnish_cache' => (bool)$get_val('varnish_cache', false),
            'css_minify'    => (bool)$get_val('css_minify', false),
            'js_minify'     => (bool)$get_val('js_minify', false),

            // --- NEW SOTA SETTINGS ---
            'css_async'     => (bool)$get_val('css_async', false),
            'js_defer'      => (bool)$get_val('js_defer', false),
            'js_delay'      => (bool)$get_val('js_delay', false),
            // -------------------------

            'cache_lifetime' => $this->sanitizeCacheLifetime($get_val('cache_lifetime', 3600)),

            // URL and CSS settings
            'excluded_urls' => $this->sanitizeUrls($get_val('excluded_urls', [])),
            'excluded_css' => $this->sanitizeCssSelectors($get_val('excluded_css', [])),
            'excluded_js' => $this->sanitizeJsSelectors($get_val('excluded_js', [])),

            // Redis settings
            'redis_host' => $this->sanitizeHost($get_val('redis_host', '127.0.0.1')),
            'redis_port' => $this->sanitizePort($get_val('redis_port', 6379)),
            'redis_db' => $this->sanitizeRedisDb($get_val('redis_db', 0)),

            // Password needs special handling because it might be masked
            'redis_password' => $this->sanitizeRedisPassword($new_settings),

            'redis_prefix' => $this->sanitizeRedisPrefix($get_val('redis_prefix', 'wpsc:')),
            'redis_persistent' => (bool)$get_val('redis_persistent', false),
            'redis_compression' => (bool)$get_val('redis_compression', true),

            // Varnish settings
            'varnish_host' => $this->sanitizeHost($get_val('varnish_host', '127.0.0.1')),
            'varnish_port' => $this->sanitizePort($get_val('varnish_port', 6081)),

            // Preload settings
            'preload_urls' => $this->sanitizeUrls($get_val('preload_urls', [])),
            'preload_interval' => $this->sanitizeInterval($get_val('preload_interval', 'daily')),

            // Metrics settings
            'enable_metrics' => (bool)$get_val('enable_metrics', true),
            'metrics_retention' => $this->sanitizeMetricsRetention($get_val('metrics_retention', 30)),

            // Advanced settings (merged recursively)
            'advanced_settings' => $this->sanitizeAdvancedSettings($get_val('advanced_settings', []))
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
        // Fallback to existing
        $old_settings = get_option('wpsc_settings', []);
        return $old_settings['redis_password'] ?? '';
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
        // We also need to fetch old advanced settings to merge if partial update
        $old_settings = get_option('wpsc_settings', []);
        $old_advanced = $old_settings['advanced_settings'] ?? [];

        $get_adv_val = function ($key, $default) use ($settings, $old_advanced) {
            if (isset($settings[$key])) return $settings[$key];
            return $old_advanced[$key] ?? $default;
        };

        return [
            'object_cache_alloptions_limit' => $this->sanitizeAllOptionsLimit($get_adv_val('object_cache_alloptions_limit', 1000)),
            'max_ttl' => $this->sanitizeMaxTTL($get_adv_val('max_ttl', 86400)),
            'cache_groups' => $this->sanitizeCacheGroups($get_adv_val('cache_groups', [])),
            'ignored_groups' => $this->sanitizeCacheGroups($get_adv_val('ignored_groups', []))
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
