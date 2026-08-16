<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Config\Settings;
use WPSCache\Infrastructure\WordPress\DropInManager;
use WPSCache\Infrastructure\WordPress\ObjectCacheCompatibility;

final class SettingsValidator
{
    private const PROTECTED_KEYS = ["redis_password", "cf_api_token", "pagespeed_api_key", "uptime_heartbeat_url", "media_offload_access_key", "media_offload_secret_key", "openai_api_key"];

    public function __construct(
        private readonly ?ObjectCacheCompatibility $objectCacheCompatibility = null,
        private readonly ?DropInManager $dropIns = null,
        private readonly ?string $wpConfigFile = null,
    ) {
    }

    public function sanitizeSettings(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $current = get_option("wpsc_settings", []);
        if (!is_array($current)) {
            $current = [];
        }

        $defaults = Settings::defaults();
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

        if ($input !== [] && $clean !== $current) {
            $history = get_option('wpsc_settings_history', []);
            $history = is_array($history) ? $history : [];
            $history[] = ['created_at' => gmdate(DATE_ATOM), 'settings' => $current];
            update_option('wpsc_settings_history', array_slice($history, -10), false);
        }

        // WordPress supports one persistent object-cache drop-in. If both are
        // submitted, the newly introduced Memcached backend wins deterministically.
        if (!empty($clean['memcached_cache'])) {
            $clean['redis_cache'] = false;
        }
        $this->enforceDependencies($clean, $current, $input);
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
            case "cache_stale_ttl":
                $max = 86400;
                break;
            case "cache_regeneration_lock":
                $min = 1;
                $max = 300;
                break;
            case "rest_cache_ttl":
                $min = 10;
                $max = 86400;
                break;
            case "preload_concurrency":
                $min = 1;
                $max = 10;
                break;
            case "preload_batch_size":
                $min = 1;
                $max = 500;
                break;
            case "js_delay_timeout":
                $min = 0;
                $max = 30000;
                break;
            case "image_quality":
            case "image_adaptive_quality":
                $min = 1;
                $max = 100;
                break;
            case "image_max_width":
            case "image_max_height":
                $min = 0;
                $max = 12000;
                break;
            case "image_adaptive_max_width":
                $min = 1;
                $max = 12000;
                break;
            case "image_background_batch_size":
                $min = 1;
                $max = 100;
                break;
            case "css_profile_retention":
                $min = 1;
                $max = 365;
                break;
            case "image_watermark_id":
                $max = PHP_INT_MAX;
                break;
            case "redis_port":
            case "varnish_port":
            case "nginx_port":
            case "memcached_port":
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

        if ($key === "redis_host" || $key === "varnish_host" || $key === "nginx_host" || $key === "memcached_host") {
            return $this->sanitizeHost($val);
        }
        if (in_array($key, ["cdn_url", "cdn_css_url", "cdn_js_url", "cdn_media_url"], true)) {
            $url = esc_url_raw($val);
            if ($url && !preg_match("/^(https?:)?\/\//", $url)) {
                return "";
            }
            return $url;
        }
        if ($key === 'uptime_heartbeat_url') {
            $url = esc_url_raw($val);
            return is_string($url) && str_starts_with($url, 'https://') ? substr($url, 0, 2048) : '';
        }
        if (in_array($key, ['media_offload_endpoint', 'media_offload_public_url'], true)) {
            $url = esc_url_raw($val);
            return is_string($url) && str_starts_with($url, 'https://') ? rtrim(substr($url, 0, 2048), '/') : '';
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
        if ($key === "pagespeed_api_key") {
            return preg_replace('/[^a-zA-Z0-9_\-]/', '', substr(sanitize_text_field($val), 0, 128));
        }
        if ($key === 'openai_api_key') {
            return preg_replace('/[^a-zA-Z0-9_\-.]/', '', substr(sanitize_text_field($val), 0, 256));
        }
        if ($key === 'openai_vision_model') {
            $model = substr(sanitize_text_field($val), 0, 64);
            return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $model) === 1 ? $model : 'gpt-5.6';
        }
        if ($key === "redis_prefix") {
            $val = sanitize_text_field($val);
            return preg_replace("/[^a-zA-Z0-9_:.-]/", "", substr($val, 0, 64));
        }
        if ($key === "memcached_prefix") {
            $val = sanitize_text_field($val);
            return preg_replace("/[^a-zA-Z0-9_:.-]/", "", substr($val, 0, 64));
        }
        if ($key === 'memcached_persistent_id') {
            return preg_replace('/[^a-zA-Z0-9_.-]/', '', substr(sanitize_text_field($val), 0, 64));
        }
        if ($key === "redis_password") {
            // Sentinel Fix: Allow special characters in passwords (e.g. < > &)
            // sanitize_text_field strips tags, corrupting complex passwords.
            // We only trim whitespace and null bytes.
            return substr(trim(str_replace(chr(0), "", (string) $val)), 0, 1024);
        }
        if ($key === 'media_offload_secret_key') {
            return substr(trim(str_replace(chr(0), '', $val)), 0, 256);
        }
        if ($key === 'media_offload_access_key') {
            return preg_replace('/[^a-zA-Z0-9_\-]/', '', substr(sanitize_text_field($val), 0, 128));
        }
        if ($key === 'media_offload_region') {
            return preg_replace('/[^a-z0-9-]/', '', strtolower(substr(sanitize_text_field($val), 0, 64)));
        }
        if ($key === 'media_offload_bucket') {
            $bucket = strtolower(substr(sanitize_text_field($val), 0, 63));
            return preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $bucket) === 1 ? $bucket : '';
        }
        if ($key === "preload_interval") {
            return $this->sanitizeEnum(
                $val,
                ["hourly", "daily", "weekly", "disabled"],
                "daily",
            );
        }
        if ($key === 'nginx_purge_path') {
            $path = '/' . ltrim(sanitize_text_field($val), '/');
            return preg_match('~^/[a-zA-Z0-9/_-]*$~', $path) === 1 ? $path : '/purge';
        }
        if ($key === "preload_source") {
            return $this->sanitizeEnum($val, ["sitemap", "wordpress", "both"], "sitemap");
        }
        if ($key === "cache_query_mode") {
            return $this->sanitizeEnum($val, ["ignore", "variants", "allowlist"], "variants");
        }
        if ($key === "cache_device_mode") {
            return $this->sanitizeEnum($val, ["shared", "mobile", "tablet"], "mobile");
        }
        if ($key === "uptime_interval") {
            return $this->sanitizeEnum($val, ["hourly", "daily"], "hourly");
        }
        if ($key === "settings_preset") {
            return $this->sanitizeEnum($val, ["custom", "safe", "balanced", "aggressive"], "custom");
        }
        if ($key === "multisite_mode") {
            return $this->sanitizeEnum($val, ["site", "network"], "site");
        }
        if ($key === "db_schedule") {
            return $this->sanitizeEnum(
                $val,
                ["disabled", "daily", "weekly", "monthly"],
                "disabled",
            );
        }
        if ($key === 'pagespeed_strategy') {
            return $this->sanitizeEnum($val, ['mobile', 'desktop'], 'mobile');
        }
        if ($key === 'js_delay_strategy') {
            return $this->sanitizeEnum($val, ['interaction', 'idle', 'consent'], 'interaction');
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
        $lines = array_map("trim", $input);
        $lines = array_filter($lines);
        return array_map("sanitize_text_field", $lines);
    }

    /**
     * Reject environment-dependent features when their prerequisites are not
     * present. Existing unchanged remote integrations are not disabled merely
     * because a service has a temporary outage.
     *
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $current
     * @param array<string, mixed> $submitted
     */
    private function enforceDependencies(array &$clean, array $current, array $submitted): void
    {
        if ($this->objectCacheCompatibility !== null && $this->objectCacheChanged($clean, $current)) {
            $check = $this->objectCacheCompatibility->inspect(new Settings($clean), true);
            if (!$check->compatible()) {
                foreach (['redis_cache', 'redis_host', 'redis_port', 'redis_db', 'redis_password', 'redis_prefix', 'redis_tls', 'memcached_cache', 'memcached_host', 'memcached_port', 'memcached_prefix', 'memcached_persistent_id'] as $key) {
                    $clean[$key] = $current[$key];
                }
                $this->settingsError('object_cache', $check->message() . ' The previous object-cache settings were retained.');
            }
        }

        if (!empty($clean['html_cache']) && $this->wasEnabled('html_cache', $clean, $current, $submitted)) {
            $issue = $this->dropIns?->advancedCacheInstallationIssue();
            if ($issue !== null) {
                $this->reject($clean, 'html_cache', $issue);
            } elseif ($this->wpConfigFile !== null && (!is_file($this->wpConfigFile) || !is_writable($this->wpConfigFile) || !is_writable(dirname($this->wpConfigFile)))) {
                $this->reject($clean, 'html_cache', 'Page caching was not enabled because wp-config.php is not writable.');
            } elseif (defined('WPSC_CACHE_DIR') && !$this->pathCanBeWritten(WPSC_CACHE_DIR . 'runtime.php')) {
                $this->reject($clean, 'html_cache', 'Page caching was not enabled because its cache directory cannot be written.');
            }
        }

        $cacheDependent = [
            'css_minify', 'css_combine', 'js_minify', 'js_combine',
            'css_rendered_profiles', 'css_critical_rendered', 'css_linked_prune',
            'gravatar_local_cache', 'image_adaptive_delivery',
        ];
        if (defined('WPSC_CACHE_DIR') && !$this->pathCanBeWritten(WPSC_CACHE_DIR . 'capability-check.tmp')) {
            foreach ($cacheDependent as $feature) {
                if ($this->wasEnabled($feature, $clean, $current, $submitted)) {
                    $this->reject($clean, $feature, $this->label($feature) . ' requires a writable WPS Cache directory.');
                }
            }
        }

        $hasImagick = extension_loaded('imagick') && class_exists('Imagick');
        $hasGd = extension_loaded('gd');
        foreach (['image_optimize_upload', 'image_background_optimization', 'image_adaptive_delivery'] as $feature) {
            if (!$hasImagick && !$hasGd && $this->wasEnabled($feature, $clean, $current, $submitted)) {
                $this->reject($clean, $feature, $this->label($feature) . ' requires Imagick or GD.');
            }
        }
        if (!empty($clean['image_generate_webp']) && array_key_exists('image_generate_webp', $submitted)
            && !$this->imageFormatAvailable('WEBP', 'imagewebp')) {
            $this->reject($clean, 'image_generate_webp', 'WebP generation requires WebP support in Imagick or GD.');
        }
        if (!empty($clean['image_generate_avif']) && array_key_exists('image_generate_avif', $submitted)
            && !$this->imageFormatAvailable('AVIF', 'imageavif')) {
            $this->reject($clean, 'image_generate_avif', 'AVIF generation requires AVIF support in Imagick or GD.');
        }

        if (!empty($clean['css_critical_rendered']) && empty($clean['css_rendered_profiles'])) {
            $this->reject($clean, 'css_critical_rendered', 'Rendered critical CSS requires rendered CSS profiles.');
        }
        if (!empty($clean['css_linked_prune']) && empty($clean['css_rendered_profiles'])) {
            $this->reject($clean, 'css_linked_prune', 'Linked CSS pruning requires rendered CSS profiles.');
        }
        if (!empty($clean['cf_edge_cache']) && empty($clean['cf_enable'])) {
            $this->reject($clean, 'cf_edge_cache', 'Cloudflare edge-cache management requires the Cloudflare integration.');
        }
        if ($this->wasEnabled('cf_enable', $clean, $current, $submitted)
            && ((string) $clean['cf_api_token'] === '' || (string) $clean['cf_zone_id'] === '')) {
            $this->reject($clean, 'cf_enable', 'Cloudflare requires both an API token and a valid zone ID.');
            $clean['cf_edge_cache'] = false;
        }
        if ($this->wasEnabled('cdn_enable', $clean, $current, $submitted) && (string) $clean['cdn_url'] === '') {
            $this->reject($clean, 'cdn_enable', 'CDN rewriting requires a valid CDN URL.');
        }

        if (!empty($clean['image_ai_alt_openai']) && empty($clean['image_ai_alt_provider'])) {
            $this->reject($clean, 'image_ai_alt_openai', 'The built-in OpenAI provider requires alt-text generation to be enabled.');
        }
        $openAiKey = defined('WPSC_OPENAI_API_KEY') ? (string) WPSC_OPENAI_API_KEY : (string) $clean['openai_api_key'];
        if ($this->wasEnabled('image_ai_alt_openai', $clean, $current, $submitted) && $openAiKey === '') {
            $this->reject($clean, 'image_ai_alt_openai', 'The built-in OpenAI alt-text provider requires an API key.');
        }

        if (!empty($clean['media_offload_delete_local']) && empty($clean['media_offload_provider'])) {
            $this->reject($clean, 'media_offload_delete_local', 'Deleting local offloaded files requires an enabled offload provider.');
        }
        if (!empty($clean['media_offload_s3']) && empty($clean['media_offload_provider'])) {
            $this->reject($clean, 'media_offload_s3', 'The S3 adapter requires media offload to be enabled.');
        }
        if ($this->wasEnabled('media_offload_s3', $clean, $current, $submitted)) {
            $access = defined('WPSC_S3_ACCESS_KEY') ? (string) WPSC_S3_ACCESS_KEY : (string) $clean['media_offload_access_key'];
            $secret = defined('WPSC_S3_SECRET_KEY') ? (string) WPSC_S3_SECRET_KEY : (string) $clean['media_offload_secret_key'];
            if ((string) $clean['media_offload_bucket'] === '' || $access === '' || $secret === '' || !str_starts_with((string) $clean['media_offload_endpoint'], 'https://')) {
                $this->reject($clean, 'media_offload_s3', 'The S3 adapter requires an HTTPS endpoint, bucket, access key, and secret key.');
            }
        }

        if ((!empty($clean['woo_disable_cart_fragments']) || !empty($clean['woo_unload_assets']))
            && array_key_exists('woo_disable_cart_fragments', $submitted)
            && !class_exists('WooCommerce')) {
            $clean['woo_disable_cart_fragments'] = false;
            $clean['woo_unload_assets'] = false;
            $this->settingsError('woocommerce', 'WooCommerce-specific optimizations were not enabled because WooCommerce is inactive.');
        }

        if ($clean['cache_logged_in_roles'] !== [] && !$this->privateCacheDirectoryAvailable()) {
            $clean['cache_logged_in_roles'] = [];
            $this->settingsError('private_cache', 'Logged-in page caching requires WPSC_PRIVATE_CACHE_DIR to point to a writable directory outside the public web root.');
        }
    }

    /** @param array<string, mixed> $clean @param array<string, mixed> $current */
    private function objectCacheChanged(array $clean, array $current): bool
    {
        $keys = ['redis_cache', 'redis_host', 'redis_port', 'redis_db', 'redis_password', 'redis_prefix', 'redis_tls', 'memcached_cache', 'memcached_host', 'memcached_port', 'memcached_prefix', 'memcached_persistent_id'];
        foreach ($keys as $key) {
            if (($clean[$key] ?? null) !== ($current[$key] ?? null)) {
                return !empty($clean['redis_cache']) || !empty($clean['memcached_cache']);
            }
        }
        return false;
    }

    /** @param array<string, mixed> $clean @param array<string, mixed> $current @param array<string, mixed> $submitted */
    private function wasEnabled(string $key, array $clean, array $current, array $submitted): bool
    {
        return !empty($clean[$key]) && array_key_exists($key, $submitted) && empty($current[$key]);
    }

    /** @param array<string, mixed> $clean */
    private function reject(array &$clean, string $key, string $message): void
    {
        $clean[$key] = false;
        $this->settingsError($key, $message);
    }

    private function settingsError(string $key, string $message): void
    {
        if (function_exists('add_settings_error')) {
            add_settings_error(Settings::OPTION, 'wpsc_dependency_' . $key, $message, 'error');
        }
    }

    private function imageFormatAvailable(string $format, string $gdFunction): bool
    {
        if (function_exists($gdFunction)) {
            return true;
        }
        if (!extension_loaded('imagick') || !class_exists('Imagick')) {
            return false;
        }
        try {
            return in_array($format, array_map('strtoupper', \Imagick::queryFormats($format)), true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function privateCacheDirectoryAvailable(): bool
    {
        if (!defined('WPSC_PRIVATE_CACHE_DIR')) {
            return false;
        }
        $private = realpath((string) WPSC_PRIVATE_CACHE_DIR);
        $public = defined('ABSPATH') ? realpath(ABSPATH) : false;
        return is_string($private) && is_dir($private) && is_writable($private)
            && (!is_string($public) || !str_starts_with($private . DIRECTORY_SEPARATOR, rtrim($public, '/\\') . DIRECTORY_SEPARATOR));
    }

    private function pathCanBeWritten(string $file): bool
    {
        $directory = is_dir($file) ? $file : dirname($file);
        while (!is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }
        return is_writable($directory);
    }

    private function label(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }
}
