<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

/**
 * Handles security, validation, and sanitization of user inputs.
 * FIX: Implements "Patch" logic to prevent overwriting settings from other tabs.
 */
class SettingsValidator
{
    /**
     * Sanitizes the settings array.
     * Merges input with existing DB values to support partial form submissions.
     */
    public function sanitizeSettings(array $input): array
    {
        // 1. Fetch current DB state to preserve values from other tabs
        $current = get_option('wpsc_settings', []);
        if (!is_array($current)) {
            $current = [];
        }

        $clean = [];

        // --- 1. Boolean Switches ---
        // Note: The Renderer outputs a hidden "0" field before the checkbox.
        // So if the field is on screen, we receive "0" or "1".
        // If the field is NOT on screen (different tab), the key is missing entirely.
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
            if (array_key_exists($key, $input)) {
                // Field was present in form -> update it
                $clean[$key] = (string)$input[$key] === '1';
            } else {
                // Field missing -> keep existing DB value
                $clean[$key] = isset($current[$key]) ? (bool)$current[$key] : false;
            }
        }

        // --- 2. Numeric Values ---
        $numerics = [
            'cache_lifetime'    => [60, 31536000],
            'metrics_retention' => [1, 365],
            'redis_port'        => [1, 65535],
            'redis_db'          => [0, 15],
            'varnish_port'      => [1, 65535]
        ];

        foreach ($numerics as $key => [$min, $max]) {
            if (isset($input[$key])) {
                $clean[$key] = $this->sanitizeInt($input[$key], $min, $max);
            } else {
                $clean[$key] = isset($current[$key]) ? (int)$current[$key] : 0;
            }
        }

        // --- 3. Strings ---
        $strings = ['redis_host', 'redis_prefix', 'varnish_host', 'preload_interval'];
        foreach ($strings as $key) {
            if (isset($input[$key])) {
                if ($key === 'redis_host' || $key === 'varnish_host') {
                    $clean[$key] = $this->sanitizeHost($input[$key]);
                } else {
                    $clean[$key] = sanitize_text_field($input[$key]);
                }
            } else {
                $clean[$key] = $current[$key] ?? '';
            }
        }

        // --- 4. Password (Masking Handling) ---
        if (isset($input['redis_password'])) {
            // Only update if it's not empty/masked.
            // If user clears it, we might need logic, but usually empty = no change for passwords in admin
            if (!empty($input['redis_password'])) {
                $clean['redis_password'] = sanitize_text_field($input['redis_password']);
            } else {
                $clean['redis_password'] = $current['redis_password'] ?? '';
            }
        } else {
            $clean['redis_password'] = $current['redis_password'] ?? '';
        }

        // --- 5. Arrays (Textarea Lines) ---
        $arrays = ['excluded_urls', 'excluded_css', 'excluded_js', 'preload_urls'];
        foreach ($arrays as $key) {
            if (isset($input[$key])) {
                $clean[$key] = $this->sanitizeLines($input[$key]);
            } else {
                $clean[$key] = $current[$key] ?? [];
            }
        }

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
        return preg_replace('/[^a-zA-Z0-9\-\.:]/', '', $host);
    }

    private function sanitizeLines(array|string $input): array
    {
        if (is_string($input)) {
            $input = explode("\n", $input);
        }

        $lines = array_map('trim', $input);
        $lines = array_filter($lines);
        return array_map('sanitize_text_field', $lines);
    }
}
