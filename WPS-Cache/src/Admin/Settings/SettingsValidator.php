<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

/**
 * Handles security, validation, and sanitization of user inputs.
 * Ensures no malformed data enters the database configuration.
 */
class SettingsValidator
{
    /**
     * Sanitizes the entire settings array.
     * Merges with existing settings to prevent data loss on partial updates.
     */
    public function sanitizeSettings(array $input): array
    {
        // Fetch current settings to handle password masking or missing fields
        $current = get_option('wpsc_settings', []);

        $clean = [];

        // --- Boolean Switches ---
        $booleans = [
            'html_cache',
            'enable_metrics',
            'redis_cache',
            'varnish_cache',
            'css_minify',
            'css_async',
            'js_minify',
            'js_defer',
            'js_delay',
            'redis_persistent',
            'redis_compression'
        ];

        foreach ($booleans as $key) {
            // Checkboxes send '1' if checked, nothing if unchecked
            $clean[$key] = isset($input[$key]) && (string)$input[$key] === '1';
        }

        // --- Numeric Values ---
        $clean['cache_lifetime']    = $this->sanitizeInt($input['cache_lifetime'] ?? 3600, 60, 31536000);
        $clean['metrics_retention'] = $this->sanitizeInt($input['metrics_retention'] ?? 30, 1, 365);
        $clean['redis_port']        = $this->sanitizeInt($input['redis_port'] ?? 6379, 1, 65535);
        $clean['redis_db']          = $this->sanitizeInt($input['redis_db'] ?? 0, 0, 15);
        $clean['varnish_port']      = $this->sanitizeInt($input['varnish_port'] ?? 6081, 1, 65535);

        // --- Text / Strings ---
        $clean['redis_host']   = $this->sanitizeHost($input['redis_host'] ?? '127.0.0.1');
        $clean['redis_prefix'] = sanitize_text_field($input['redis_prefix'] ?? 'wpsc:');
        $clean['varnish_host'] = $this->sanitizeHost($input['varnish_host'] ?? '127.0.0.1');

        // Selects
        $clean['preload_interval'] = in_array($input['preload_interval'] ?? '', ['hourly', 'daily', 'weekly'])
            ? $input['preload_interval']
            : 'daily';

        // --- Password Handling (Masking) ---
        // If input is empty, user might mean "no change" or "no password".
        // In a real UI, we typically don't pre-fill the password input for security.
        // If user sends nothing, keep existing. If user sends value, update it.
        if (!empty($input['redis_password'])) {
            $clean['redis_password'] = sanitize_text_field($input['redis_password']);
        } else {
            // Keep existing password if not provided
            $clean['redis_password'] = $current['redis_password'] ?? '';
        }

        // --- Arrays (Textarea -> Array) ---
        $clean['excluded_urls'] = $this->sanitizeLines($input['excluded_urls'] ?? []);
        $clean['excluded_css']  = $this->sanitizeLines($input['excluded_css'] ?? []);
        $clean['excluded_js']   = $this->sanitizeLines($input['excluded_js'] ?? []);
        $clean['preload_urls']  = $this->sanitizeUrlList($input['preload_urls'] ?? []);

        return $clean;
    }

    private function sanitizeInt(mixed $value, int $min, int $max): int
    {
        $val = absint($value);
        return max($min, min($max, $val));
    }

    private function sanitizeHost(string $host): string
    {
        $host = sanitize_text_field(trim($host));
        // Allow IP or Hostname, remove potentially dangerous chars
        return preg_replace('/[^a-zA-Z0-9\-\.:]/', '', $host);
    }

    private function sanitizeLines(array|string $input): array
    {
        if (is_string($input)) {
            $input = explode("\n", $input);
        }

        $lines = array_map('trim', $input);
        $lines = array_filter($lines); // Remove empty lines
        return array_map('sanitize_text_field', $lines);
    }

    private function sanitizeUrlList(array|string $input): array
    {
        $lines = $this->sanitizeLines($input);
        $urls = [];

        foreach ($lines as $line) {
            // Allow relative paths or full URLs
            if (str_starts_with($line, '/') || filter_var($line, FILTER_VALIDATE_URL)) {
                $urls[] = $line;
            }
        }
        return $urls;
    }
}
