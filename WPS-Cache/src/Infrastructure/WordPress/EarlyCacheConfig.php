<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\WordPress;

use WPSCache\Config\Settings;

/** Writes the dependency-free configuration consumed by advanced-cache.php. */
final class EarlyCacheConfig
{
    public function __construct(private readonly string $file)
    {
    }

    public function write(Settings $settings): bool
    {
        $cookies = [
            'wordpress_logged_in_',
            'wp-postpass_',
            'comment_author_',
        ];

        if ($settings->enabled('woo_support')) {
            $cookies[] = 'woocommerce_items_in_cart';
            $cookies[] = 'woocommerce_cart_hash';
            $cookies[] = 'wp_woocommerce_session_';
        }
        $cookies = array_values(array_unique(array_merge(
            $cookies,
            $settings->strings('cache_bypass_cookies'),
        )));

        $deniedQueries = $settings->strings('cache_query_denylist');
        if (!$settings->enabled('cache_search')) {
            $deniedQueries[] = 's';
        }
        $configuration = [
            'version' => WPSC_VERSION,
            'ttl' => max(60, min(31536000, $settings->integer('cache_lifetime'))),
            'bypass_cookies' => $cookies,
            'bypass_user_agents' => $settings->strings('cache_bypass_user_agents'),
            'excluded_urls' => $settings->strings('excluded_urls'),
            'query_mode' => $settings->string('cache_query_mode'),
            'query_allowlist' => $settings->strings('cache_query_allowlist'),
            'query_denylist' => array_values(array_unique($deniedQueries)),
            'ignored_query_params' => $settings->strings('cache_ignored_query_params'),
            'device_mode' => $settings->string('cache_device_mode'),
            'cache_feeds' => $settings->enabled('cache_feeds'),
            'metrics_enabled' => $settings->enabled('enable_metrics'),
            'stale_ttl' => max(0, min(86400, $settings->integer('cache_stale_ttl'))),
            'regeneration_lock' => max(1, min(300, $settings->integer('cache_regeneration_lock'))),
        ];

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($configuration, true)
            . ";\n";

        if (is_file($this->file) && file_get_contents($this->file) === $content) {
            return true;
        }

        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        $temporary = tempnam($directory, 'wpsc_config_');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) === false) {
            return false;
        }

        @chmod($temporary, 0644);
        if (!@rename($temporary, $this->file)) {
            @unlink($this->file);
            if (!@rename($temporary, $this->file)) {
                @unlink($temporary);
                return false;
            }
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->file, true);
        }

        return true;
    }
}
