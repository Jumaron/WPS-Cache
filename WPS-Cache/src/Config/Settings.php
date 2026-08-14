<?php

declare(strict_types=1);

namespace WPSCache\Config;

/**
 * Immutable, normalized plugin configuration.
 */
final class Settings
{
    public const OPTION = 'wpsc_settings';

    private const DEFAULTS = [
        'html_cache' => true,
        'cache_lifetime' => 3600,
        'preload_interval' => 'daily',
        'excluded_urls' => [],
        'redis_cache' => false,
        'redis_host' => '127.0.0.1',
        'redis_port' => 6379,
        'redis_db' => 0,
        'redis_password' => '',
        'redis_prefix' => 'wpsc:',
        'varnish_cache' => false,
        'varnish_host' => '127.0.0.1',
        'varnish_port' => 6081,
        'css_minify' => false,
        'excluded_css_minify' => [],
        'remove_unused_css' => false,
        'css_safelist' => [],
        'js_minify' => false,
        'excluded_js_minify' => [],
        'js_defer' => false,
        'js_delay' => false,
        'excluded_js_execution' => ['jquery.js', 'jquery.min.js'],
        'speculative_loading' => false,
        'media_lazy_load' => true,
        'media_lazy_load_iframes' => true,
        'media_lazy_load_exclude_count' => 3,
        'media_add_dimensions' => false,
        'media_youtube_facade' => false,
        'font_localize_google' => true,
        'font_display_swap' => true,
        'cdn_enable' => false,
        'cdn_url' => '',
        'cf_enable' => false,
        'cf_api_token' => '',
        'cf_zone_id' => '',
        'bloat_disable_emojis' => true,
        'bloat_disable_embeds' => true,
        'bloat_disable_xmlrpc' => true,
        'bloat_disable_user_enumeration' => true,
        'bloat_hide_wp_version' => true,
        'bloat_remove_wlw_rsd' => true,
        'bloat_remove_shortlink' => true,
        'bloat_disable_rss' => false,
        'bloat_disable_self_pingbacks' => true,
        'bloat_remove_jquery_migrate' => true,
        'bloat_remove_dashicons' => true,
        'bloat_remove_query_strings' => true,
        'heartbeat_frequency' => 60,
        'heartbeat_disable_admin' => false,
        'heartbeat_disable_dashboard' => false,
        'heartbeat_disable_editor' => false,
        'heartbeat_disable_frontend' => true,
        'enable_metrics' => true,
        'metrics_retention' => 14,
        'db_schedule' => 'disabled',
        'db_clean_revisions' => true,
        'db_clean_auto_drafts' => true,
        'db_clean_trashed_posts' => true,
        'db_clean_spam_comments' => true,
        'db_clean_trashed_comments' => true,
        'db_clean_expired_transients' => true,
        'db_clean_all_transients' => false,
        'db_clean_optimize_tables' => true,
        'woo_support' => true,
    ];

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        $this->values = array_replace(self::DEFAULTS, $values);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->values[$key] ?? $fallback;
    }

    public function enabled(string $key): bool
    {
        return !empty($this->values[$key]);
    }

    public function integer(string $key): int
    {
        return (int) ($this->values[$key] ?? 0);
    }

    public function string(string $key): string
    {
        return (string) ($this->values[$key] ?? '');
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $value = $this->values[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
