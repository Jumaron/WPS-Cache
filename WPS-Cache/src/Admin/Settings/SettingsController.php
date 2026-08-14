<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Config\Settings;

/** Owns settings registration and settings-page POST actions. */
final class SettingsController
{
    public function __construct(private readonly SettingsValidator $validator)
    {
    }

    public function boot(): void
    {
        add_action('admin_init', [$this, 'register']);
        add_action('admin_post_wpsc_refresh_stats', [$this, 'refreshStats']);
    }

    public function register(): void
    {
        register_setting('wpsc_settings', Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this->validator, 'sanitizeSettings'],
            'default' => Settings::defaults(),
        ]);
    }

    public function refreshStats(): never
    {
        check_admin_referer('wpsc_refresh_stats');
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
        }

        delete_transient('wpsc_stats_cache');
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wps-cache'));
        exit;
    }
}
