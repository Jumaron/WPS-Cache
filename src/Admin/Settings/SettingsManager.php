<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Cache\CacheManager;

/**
 * Manages the settings functionality for WPS Cache
 */
class SettingsManager
{
    private CacheManager $cache_manager;
    private SettingsValidator $validator;
    private SettingsRenderer $renderer;

    public function __construct(CacheManager $cache_manager)
    {
        $this->cache_manager = $cache_manager;
        $this->validator = new SettingsValidator();
        $this->renderer = new SettingsRenderer();
        $this->initializeHooks();
    }

    /**
     * Initializes WordPress hooks for settings
     */
    private function initializeHooks(): void
    {
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * Registers all plugin settings with WordPress
     */
    public function registerSettings(): void
    {
        register_setting(
            'wpscac_settings',
            'wpscac_settings',
            [
                'type'              => 'array',
                'sanitize_callback' => [$this->validator, 'sanitizeSettings'],
                'default'           => $this->getDefaultSettings()
            ]
        );

        $this->registerSettingsSections();
        $this->registerSettingsFields();
    }

    /**
     * Registers settings sections
     */
    private function registerSettingsSections(): void
    {
        // Cache Settings Section
        add_settings_section(
            'wpscac_cache_settings',
            __('Cache Settings', 'wps-cache'),
            [$this->renderer, 'renderCacheSettingsInfo'],
            'wps-cache'
        );

        // Redis Settings Section
        add_settings_section(
            'wpscac_redis_settings',
            __('Redis Settings', 'wps-cache'),
            [$this->renderer, 'renderRedisSettingsInfo'],
            'wps-cache'
        );

        // Varnish Settings Section
        add_settings_section(
            'wpscac_varnish_settings',
            __('Varnish Settings', 'wps-cache'),
            [$this->renderer, 'renderVarnishSettingsInfo'],
            'wps-cache'
        );

        // Advanced Settings Section
        add_settings_section(
            'wpscac_advanced_settings',
            __('Advanced Settings', 'wps-cache'),
            [$this->renderer, 'renderAdvancedSettingsInfo'],
            'wps-cache'
        );
    }

    /**
     * Registers individual settings fields
     */
    private function registerSettingsFields(): void
    {
        // Cache Type Fields
        add_settings_field(
            'wpscac_html_cache',
            __('HTML Cache', 'wps-cache'),
            [$this->renderer, 'renderCheckboxField'],
            'wps-cache',
            'wpscac_cache_settings',
            [
                'label_for'   => 'wpscac_html_cache',
                'option_name' => 'html_cache',
                'description' => __('Enable static HTML caching', 'wps-cache')
            ]
        );
        add_settings_field(
            'wpscac_redis_cache',
            __('Redis Cache', 'wps-cache'),
            [$this->renderer, 'renderCheckboxField'],
            'wps-cache',
            'wpscac_cache_settings',
            [
                'label_for'   => 'wpscac_redis_cache',
                'option_name' => 'redis_cache',
                'description' => __('Enable Redis object caching', 'wps-cache')
            ]
        );
        add_settings_field(
            'wpscac_varnish_cache',
            __('Varnish Cache', 'wps-cache'),
            [$this->renderer, 'renderCheckboxField'],
            'wps-cache',
            'wpscac_cache_settings',
            [
                'label_for'   => 'wpscac_varnish_cache',
                'option_name' => 'varnish_cache',
                'description' => __('Enable Varnish HTTP cache', 'wps-cache')
            ]
        );
        add_settings_field(
            'wpscac_css_minify',
            __('CSS Minification', 'wps-cache'),
            [$this->renderer, 'renderCheckboxField'],
            'wps-cache',
            'wpscac_cache_settings',
            [
                'label_for'   => 'wpscac_css_minify',
                'option_name' => 'css_minify',
                'description' => __('Minify CSS (Experimental)', 'wps-cache')
            ]
        );
        add_settings_field(
            'wpscac_js_minify',
            __('JS Minification', 'wps-cache'),
            [$this->renderer, 'renderCheckboxField'],
            'wps-cache',
            'wpscac_cache_settings',
            [
                'label_for'   => 'wpscac_js_minify',
                'option_name' => 'js_minify',
                'description' => __('Minify JS (Experimental)', 'wps-cache')
            ]
        );

        // Redis Fields
        add_settings_field(
            'wpscac_redis_host',
            __('Redis Host', 'wps-cache'),
            [$this->renderer, 'renderTextField'],
            'wps-cache',
            'wpscac_redis_settings',
            [
                'label_for'   => 'wpscac_redis_host',
                'option_name' => 'redis_host',
                'description' => __('Redis server hostname or IP', 'wps-cache')
            ]
        );
        add_settings_field(
            'wpscac_redis_port',
            __('Redis Port', 'wps-cache'),
            [$this->renderer, 'renderNumberField'],
            'wps-cache',
            'wpscac_redis_settings',
            [
                'label_for'   => 'wpscac_redis_port',
                'option_name' => 'redis_port',
                'description' => __('Redis server port', 'wps-cache'),
                'min'         => 1,
                'max'         => 65535
            ]
        );
        add_settings_field(
            'wpscac_redis_db',
            __('Redis Database', 'wps-cache'),
            [$this->renderer, 'renderNumberField'],
            'wps-cache',
            'wpscac_redis_settings',
            [
                'label_for'   => 'wpscac_redis_db',
                'option_name' => 'redis_db',
                'description' => __('Redis database index', 'wps-cache'),
                'min'         => 0,
                'max'         => 15
            ]
        );
        add_settings_field(
            'wpscac_redis_password',
            __('Redis Password', 'wps-cache'),
            [$this->renderer, 'renderPasswordField'],
            'wps-cache',
            'wpscac_redis_settings',
            [
                'label_for'   => 'wpscac_redis_password',
                'option_name' => 'redis_password',
                'description' => __('Redis password (leave blank if none)', 'wps-cache')
            ]
        );
        add_settings_field(
            'wpscac_redis_prefix',
            __('Redis Prefix', 'wps-cache'),
            [$this->renderer, 'renderTextField'],
            'wps-cache',
            'wpscac_redis_settings',
            [
                'label_for'   => 'wpscac_redis_prefix',
                'option_name' => 'redis_prefix',
                'description' => __('Prefix for Redis keys', 'wps-cache')
            ]
        );
        add_settings_field(
            'wpscac_redis_persistent',
            __('Persistent Connections', 'wps-cache'),
            [$this->renderer, 'renderCheckboxField'],
            'wps-cache',
            'wpscac_redis_settings',
            [
                'label_for'   => 'wpscac_redis_persistent',
                'option_name' => 'redis_persistent',
                'description' => __('Use persistent Redis connections', 'wps-cache')
            ]
        );
        add_settings_field(
            'wpscac_redis_compression',
            __('Redis Compression', 'wps-cache'),
            [$this->renderer, 'renderCheckboxField'],
            'wps-cache',
            'wpscac_redis_settings',
            [
                'label_for'   => 'wpscac_redis_compression',
                'option_name' => 'redis_compression',
                'description' => __('Enable compression for Redis data', 'wps-cache')
            ]
        );

        // Varnish Fields
        add_settings_field(
            'wpscac_varnish_host',
            __('Varnish Host', 'wps-cache'),
            [$this->renderer, 'renderTextField'],
            'wps-cache',
            'wpscac_varnish_settings',
            [
                'label_for'   => 'wpscac_varnish_host',
                'option_name' => 'varnish_host',
                'description' => __('Varnish server hostname or IP', 'wps-cache')
            ]
        );
        add_settings_field(
            'wpscac_varnish_port',
            __('Varnish Port', 'wps-cache'),
            [$this->renderer, 'renderNumberField'],
            'wps-cache',
            'wpscac_varnish_settings',
            [
                'label_for'   => 'wpscac_varnish_port',
                'option_name' => 'varnish_port',
                'description' => __('Varnish server port', 'wps-cache'),
                'min'         => 1,
                'max'         => 65535
            ]
        );

        // Advanced Settings Fields
        add_settings_field(
            'wpscac_cache_lifetime',
            __('Cache Lifetime', 'wps-cache'),
            [$this->renderer, 'renderNumberField'],
            'wps-cache',
            'wpscac_advanced_settings',
            [
                'label_for'   => 'wpscac_cache_lifetime',
                'option_name' => 'cache_lifetime',
                'description' => __('Cache lifetime in seconds', 'wps-cache'),
                'min'         => 60,
                'max'         => 2592000
            ]
        );
        add_settings_field(
            'wpscac_excluded_urls',
            __('Excluded URLs', 'wps-cache'),
            [$this->renderer, 'renderTextareaField'],
            'wps-cache',
            'wpscac_advanced_settings',
            [
                'label_for'   => 'wpscac_excluded_urls',
                'option_name' => 'excluded_urls',
                'description' => __('URLs to exclude from caching (one per line)', 'wps-cache')
            ]
        );
        add_settings_field(
            'wpscac_object_cache_alloptions_limit',
            __('Alloptions Limit', 'wps-cache'),
            [$this->renderer, 'renderNumberField'],
            'wps-cache',
            'wpscac_advanced_settings',
            [
                'label_for'   => 'wpscac_object_cache_alloptions_limit',
                'option_name' => 'advanced_settings[object_cache_alloptions_limit]',
                'description' => __('Limit for alloptions object cache', 'wps-cache'),
                'min'         => 100,
                'max'         => 5000
            ]
        );
        add_settings_field(
            'wpscac_cache_groups',
            __('Cache Groups', 'wps-cache'),
            [$this->renderer, 'renderTextareaField'],
            'wps-cache',
            'wpscac_advanced_settings',
            [
                'label_for'   => 'wpscac_cache_groups',
                'option_name' => 'advanced_settings[cache_groups]',
                'description' => __('Cache groups to include (one per line)', 'wps-cache')
            ]
        );
    }

    /**
     * Renders the settings tab content
     */
    public function renderTab(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->renderer->renderSettingsPage();
    }

    /**
     * Gets the default settings array
     *
     * @return array Default settings values
     */
    public function getDefaultSettings(): array
    {
        return [
            'html_cache'         => false,
            'redis_cache'        => false,
            'varnish_cache'      => false,
            'css_minify'         => false,
            'js_minify'          => false,
            'cache_lifetime'     => 3600,
            'excluded_urls'      => [],
            'excluded_css'       => [],
            'excluded_js'        => [],
            'redis_host'         => '127.0.0.1',
            'redis_port'         => 6379,
            'redis_db'           => 0,
            'redis_password'     => '',
            'redis_prefix'       => 'wpscac:',
            'redis_persistent'   => false,
            'redis_compression'  => true,
            'varnish_host'       => '127.0.0.1',
            'varnish_port'       => 6081,
            'preload_urls'       => [],
            'preload_interval'   => 'daily',
            'enable_metrics'     => true,
            'metrics_retention'  => 30,
            'advanced_settings'  => [
                'object_cache_alloptions_limit' => 1000,
                'max_ttl'                       => 86400,
                'cache_groups'                  => [],
                'ignored_groups'                => [],
            ]
        ];
    }

    /**
     * Updates plugin settings
     *
     * @param array $settings New settings array
     * @return bool Whether the update was successful
     */
    public function updateSettings(array $settings): bool
    {
        $validated_settings = $this->validator->sanitizeSettings($settings);

        $updated = update_option('wpscac_settings', $validated_settings);

        if ($updated) {
            $this->cache_manager->clearAllCaches();
            do_action('wpscac_settings_updated', $validated_settings);
        }

        return $updated;
    }

    /**
     * Gets all current settings
     *
     * @return array Current settings
     */
    public function getSettings(): array
    {
        return get_option('wpscac_settings', $this->getDefaultSettings());
    }

    /**
     * Gets a specific setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed Setting value
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->getSettings();
        return $settings[$key] ?? $default;
    }
}
