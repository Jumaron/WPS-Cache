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
        'preload_source' => 'sitemap',
        'preload_concurrency' => 2,
        'preload_batch_size' => 25,
        'excluded_urls' => [],
        'cache_query_mode' => 'variants',
        'cache_query_allowlist' => [],
        'cache_query_denylist' => ['add-to-cart', 'wp_nonce', 'preview'],
        'cache_ignored_query_params' => ['utm_*', 'fbclid', 'gclid', 'dclid', 'msclkid', '_ga'],
        'cache_bypass_cookies' => [],
        'cache_bypass_user_agents' => [],
        'cache_device_mode' => 'mobile',
        'cache_logged_in_roles' => [],
        'cache_feeds' => false,
        'cache_search' => false,
        'cache_stale_ttl' => 300,
        'cache_regeneration_lock' => 30,
        'rest_cache' => false,
        'rest_cache_ttl' => 300,
        'rest_cache_routes' => [],
        'redis_cache' => false,
        'redis_host' => '127.0.0.1',
        'redis_port' => 6379,
        'redis_db' => 0,
        'redis_password' => '',
        'redis_prefix' => 'wpsc:',
        'redis_tls' => false,
        'varnish_cache' => false,
        'varnish_host' => '127.0.0.1',
        'varnish_port' => 6081,
        'nginx_cache' => false,
        'nginx_host' => '127.0.0.1',
        'nginx_port' => 80,
        'nginx_purge_path' => '/purge',
        'css_minify' => false,
        'css_combine' => false,
        'css_async' => false,
        'css_linked_prune' => false,
        'excluded_css_minify' => [],
        'remove_unused_css' => false,
        'css_safelist' => [],
        'js_minify' => false,
        'js_combine' => false,
        'excluded_js_minify' => [],
        'js_defer' => false,
        'js_delay' => false,
        'js_defer_inline' => false,
        'js_delay_timeout' => 8000,
        'excluded_js_execution' => ['jquery.js', 'jquery.min.js'],
        'speculative_loading' => false,
        'html_minify' => false,
        'lazy_render_selectors' => [],
        'asset_unload_rules' => [],
        'dns_prefetch_urls' => [],
        'preconnect_urls' => [],
        'resource_preload_urls' => [],
        'self_host_asset_urls' => [],
        'optimization_safe_mode' => false,
        'media_lazy_load' => true,
        'media_lazy_backgrounds' => false,
        'media_lazy_load_iframes' => true,
        'media_lazy_load_video' => true,
        'media_lazy_load_exclude_count' => 3,
        'media_add_dimensions' => false,
        'media_youtube_facade' => false,
        'media_vimeo_facade' => false,
        'media_maps_facade' => false,
        'media_lcp_preload' => true,
        'media_lqip' => false,
        'media_responsive_images' => true,
        'image_optimize_upload' => false,
        'image_quality' => 82,
        'image_lossless' => false,
        'image_backup_originals' => true,
        'image_max_width' => 2560,
        'image_max_height' => 2560,
        'image_generate_webp' => true,
        'image_generate_avif' => false,
        'image_preserve_exif' => false,
        'image_smart_crop' => false,
        'image_optimize_sizes' => [],
        'image_exclusions' => [],
        'image_custom_folders' => [],
        'image_watermark_id' => 0,
        'image_ai_alt_provider' => false,
        'font_localize_google' => true,
        'font_display_swap' => true,
        'font_preload_urls' => [],
        'font_system_stack' => false,
        'gravatar_local_cache' => false,
        'cdn_enable' => false,
        'cdn_url' => '',
        'cdn_css_url' => '',
        'cdn_js_url' => '',
        'cdn_media_url' => '',
        'cf_enable' => false,
        'cf_api_token' => '',
        'cf_zone_id' => '',
        'cf_edge_cache' => false,
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
        'woo_disable_cart_fragments' => false,
        'woo_unload_assets' => false,
        'heartbeat_frequency' => 60,
        'heartbeat_disable_admin' => false,
        'heartbeat_disable_dashboard' => false,
        'heartbeat_disable_editor' => false,
        'heartbeat_disable_frontend' => true,
        'enable_metrics' => true,
        'metrics_retention' => 14,
        'rum_enable' => false,
        'uptime_monitor' => false,
        'uptime_interval' => 'hourly',
        'db_schedule' => 'disabled',
        'db_clean_revisions' => true,
        'db_clean_auto_drafts' => true,
        'db_clean_trashed_posts' => true,
        'db_clean_spam_comments' => true,
        'db_clean_trashed_comments' => true,
        'db_clean_expired_transients' => true,
        'db_clean_all_transients' => false,
        'db_clean_optimize_tables' => true,
        'db_clean_orphan_postmeta' => false,
        'db_clean_orphan_commentmeta' => false,
        'db_clean_orphan_termmeta' => false,
        'db_clean_orphan_usermeta' => false,
        'woo_support' => true,
        'settings_preset' => 'balanced',
        'multisite_mode' => 'site',
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
