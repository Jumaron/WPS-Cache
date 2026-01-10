<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Plugin;

class SettingsValidator
{
    private const PROTECTED_KEYS = ["redis_password", "cf_api_token"];

    public function sanitizeSettings(array $input): array
    {
        $current = get_option("wpsc_settings", []);
        if (!is_array($current)) {
            $current = [];
        }

        $defaults = Plugin::DEFAULT_SETTINGS;
        $current = array_merge($defaults, $current);

        $clean = [];

        foreach ($defaults as $key => $defaultValue) {
            if (array_key_exists($key, $input)) {
                if (
                    in_array($key, self::PROTECTED_KEYS, true) &&
                    empty($input[$key])
                ) {
                    $clean[$key] = $current[$key];
                } else {
                    $clean[$key] = $this->sanitizeValue(
                        $key,
                        $input[$key],
                        $defaultValue,
                    );
                }
            } else {
                $clean[$key] = $current[$key];
            }
        }

        do_action("wpscac_settings_updated", $clean);
        return $clean;
    }

    private function sanitizeValue(
        string $key,
        mixed $value,
        mixed $defaultValue,
    ): mixed {
        $type = gettype($defaultValue);

        switch ($type) {
            case "boolean":
                return (string) $value === "1";
            case "integer":
                return $this->sanitizeInt($key, $value);
            case "array":
                return $this->sanitizeLines($value);
            case "string":
                return $this->sanitizeString($key, $value);
            default:
                return sanitize_text_field((string) $value);
        }
    }

    private function sanitizeInt(string $key, mixed $value): int
    {
        $val = absint($value);
        $min = 0;
        $max = PHP_INT_MAX;

        switch ($key) {
            case "cache_lifetime":
                $min = 60;
                $max = 31536000;
                break;
            case "metrics_retention":
                $min = 1;
                $max = 365;
                break;
            case "redis_port":
            case "varnish_port":
                $min = 1;
                $max = 65535;
                break;
            case "redis_db":
                $min = 0;
                $max = 15;
                break;
        }
        return max($min, min($max, $val));
    }

    private function sanitizeEnum(
        mixed $value,
        array $allowed,
        mixed $default,
    ): mixed {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function sanitizeString(string $key, mixed $value): string
    {
        $val = (string) $value;

        if ($key === "redis_host" || $key === "varnish_host") {
            return $this->sanitizeHost($val);
        }
        if ($key === "cdn_url") {
            $url = esc_url_raw($val);
            if ($url && !preg_match("/^(https?:)?\/\//", $url)) {
                return "";
            }
            return $url;
        }
        if ($key === "cf_zone_id") {
            $val = sanitize_text_field($val);
            if (!preg_match('/^[a-fA-F0-9]{32}$/', $val)) {
                return "";
            }
            return $val;
        }
        if ($key === "cf_api_token") {
            $val = sanitize_text_field($val);
            return preg_replace(
                "/[^a-zA-Z0-9_\-\.]/",
                "",
                substr($val, 0, 128),
            );
        }
        if ($key === "redis_prefix") {
            $val = sanitize_text_field($val);
            return preg_replace("/[^a-zA-Z0-9_:.-]/", "", substr($val, 0, 64));
        }
        if ($key === "redis_password") {
            // Sentinel Fix: Allow special characters in passwords (e.g. < > &)
            // sanitize_text_field strips tags, corrupting complex passwords.
            // We only trim whitespace and null bytes.
            return substr(trim(str_replace(chr(0), "", (string) $val)), 0, 1024);
        }
        if ($key === "preload_interval") {
            return $this->sanitizeEnum(
                $val,
                ["hourly", "daily", "weekly", "disabled"],
                "daily",
            );
        }
        if ($key === "db_schedule") {
            return $this->sanitizeEnum(
                $val,
                ["disabled", "daily", "weekly", "monthly"],
                "disabled",
            );
        }

        return substr(sanitize_text_field($val), 0, 1024);
    }

    private function sanitizeHost(string $host): string
    {
        $host = sanitize_text_field(trim($host));
        return preg_replace("/[^a-zA-Z0-9\-\.:]/", "", $host);
    }

    private function sanitizeLines(array|string $input): array
    {
        if (is_string($input)) {
            $input = explode("\n", $input);
        }
        if (!is_array($input)) {
            return [];
        }
        $lines = array_map("trim", $input);
        $lines = array_filter($lines);
        return array_map("sanitize_text_field", $lines);
    }
}
