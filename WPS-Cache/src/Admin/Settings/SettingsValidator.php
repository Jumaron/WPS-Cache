<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Plugin;

/**
 * Handles security, validation, and sanitization of user inputs.
 * Implements "Patch" logic to prevent overwriting settings from other tabs.
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

        // Merge current DB with defaults to ensure we have a complete set of keys
        // We use Plugin::DEFAULT_SETTINGS as the schema source of truth.
        $defaults = Plugin::DEFAULT_SETTINGS;
        $current = array_merge($defaults, $current);

        $clean = [];

        foreach ($defaults as $key => $defaultValue) {
            // "Patch" Logic:
            // If the key is present in $input, we update it.
            // If the key is missing from $input, we keep the existing value ($current).
            //
            // Note for Booleans (Checkboxes):
            // The Renderer outputs a hidden input with value="0" before the checkbox.
            // So if a checkbox is on the active tab, the key WILL be present in $input (0 or 1).
            // If it is on a different tab, the key will be missing.

            if (array_key_exists($key, $input)) {
                $clean[$key] = $this->sanitizeValue($key, $input[$key], $defaultValue);
            } else {
                $clean[$key] = $current[$key];
            }
        }

        return $clean;
    }

    private function sanitizeValue(string $key, mixed $value, mixed $defaultValue): mixed
    {
        // Determine type based on default value
        $type = gettype($defaultValue);

        switch ($type) {
            case 'boolean':
                return (string)$value === '1';

            case 'integer':
                // Apply specific ranges for known keys
                return $this->sanitizeInt($key, $value);

            case 'array':
                return $this->sanitizeLines($value);

            case 'string':
                return $this->sanitizeString($key, $value);

            default:
                // Fallback for unknown types (shouldn't happen with strict typing in Plugin)
                return sanitize_text_field((string)$value);
        }
    }

    private function sanitizeInt(string $key, mixed $value): int
    {
        $val = absint($value);

        // Define specific ranges
        // Defaults: 0 to PHP_INT_MAX
        $min = 0;
        $max = PHP_INT_MAX;

        switch ($key) {
            case 'cache_lifetime':
                $min = 60;
                $max = 31536000;
                break;
            case 'metrics_retention':
                $min = 1;
                $max = 365;
                break;
            case 'redis_port':
            case 'varnish_port':
                $min = 1;
                $max = 65535;
                break;
            case 'redis_db':
                $min = 0;
                $max = 15;
                break;
        }

        return max($min, min($max, $val));
    }

    private function sanitizeString(string $key, mixed $value): string
    {
        $val = (string)$value;

        if ($key === 'redis_host' || $key === 'varnish_host') {
            return $this->sanitizeHost($val);
        }

        // Special handling for password:
        // We allow clearing the password if the user submits an empty string.
        // We do NOT implement masking logic here anymore; masking is a UI concern.
        // If the value is submitted, it is what the user intends.

        return sanitize_text_field($val);
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

        // Ensure it's an array before mapping
        if (!is_array($input)) {
            return [];
        }

        $lines = array_map('trim', $input);
        $lines = array_filter($lines);
        return array_map('sanitize_text_field', $lines);
    }
}
