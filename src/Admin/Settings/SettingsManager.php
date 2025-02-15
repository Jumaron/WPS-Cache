<?php
declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Cache\CacheManager;

/**
 * Manages the settings functionality for WPS Cache
 */
class SettingsManager {
    private CacheManager $cache_manager;
    private SettingsValidator $validator;
    private SettingsRenderer $renderer;

    /**
     * Default settings defined as a constant to avoid dynamic defaults.
     */
    private const DEFAULT_SETTINGS = [
        'html_cache'        => false,
        'redis_cache'       => false,
        'varnish_cache'     => false,
        'css_minify'        => false,
        'js_minify'         => false,
        'cache_lifetime'    => 3600,
        'excluded_urls'     => [],
        'excluded_css'      => [],
        'excluded_js'       => [],
        'redis_host'        => '127.0.0.1',
        'redis_port'        => 6379,
        'redis_db'          => 0,
        'redis_password'    => '',
        'redis_prefix'      => 'wpsc:',
        'redis_persistent'  => false,
        'redis_compression' => true,
        'varnish_host'      => '127.0.0.1',
        'varnish_port'      => 6081,
        'preload_urls'      => [],
        'preload_interval'  => 'daily',
        'enable_metrics'    => true,
        'metrics_retention' => 30,
        'advanced_settings' => [
            'object_cache_alloptions_limit' => 1000,
            'max_ttl'       => 86400,
            'cache_groups'  => [],
            'ignored_groups'=> [],
        ]
    ];

    public function __construct(CacheManager $cache_manager) {
        $this->cache_manager = $cache_manager;
        $this->validator = new SettingsValidator();
        $this->renderer = new SettingsRenderer();
        $this->initializeHooks();
    }

    /**
     * Initializes WordPress hooks for settings
     */
    private function initializeHooks(): void {
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * Registers all plugin settings with WordPress
     */
    public function registerSettings(): void {
        register_setting(
            'wpsc_settings',
            'wpsc_settings',
            [
                'type'              => 'array',
                // Changed to a static callback so that no dynamic argument is passed.
                'sanitize_callback' => [SettingsValidator::class, 'sanitizeSettings'],
                'default'           => self::DEFAULT_SETTINGS,
            ]
        );

        $this->registerSettingsSections();
        $this->registerSettingsFields();
    }

    /**
     * Registers settings sections
     */
    private function registerSettingsSections(): void {
        // Cache Settings Section
        add_settings_section(
            'wpsc_cache_settings',
            __('Cache Settings', 'WPS-Cache'),
            [$this->renderer, 'renderCacheSettingsInfo'],
            'WPS-Cache'
        );

        // Redis Settings Section
        add_settings_section(
            'wpsc_redis_settings',
            __('Redis Settings', 'WPS-Cache'),
            [$this->renderer, 'renderRedisSettingsInfo'],
            'WPS-Cache'
        );

        // Varnish Settings Section
        add_settings_section(
            'wpsc_varnish_settings',
            __('Varnish Settings', 'WPS-Cache'),
            [$this->renderer, 'renderVarnishSettingsInfo'],
            'WPS-Cache'
        );

        // Advanced Settings Section
        add_settings_section(
            'wpsc_advanced_settings',
            __('Advanced Settings', 'WPS-Cache'),
            [$this->renderer, 'renderAdvancedSettingsInfo'],
            'WPS-Cache'
        );
    }

    /**
     * Registers individual settings fields
     */
    private function registerSettingsFields(): void {
        // Cache Type Fields
        add_settings_field(
            'wpsc_html_cache',
            __('HTML Cache', 'WPS-Cache'),
            [$this->renderer, 'renderCheckboxField'],
            'WPS-Cache',
            'wpsc_cache_settings',
            [
                'label_for'   => 'wpsc_html_cache',
                'option_name' => 'html_cache',
                'description' => __('Enable static HTML caching', 'WPS-Cache')
            ]
        );
        add_settings_field(
            'wpsc_redis_cache',
            __('Redis Cache', 'WPS-Cache'),
            [$this->renderer, 'renderCheckboxField'],
            'WPS-Cache',
            'wpsc_cache_settings',
            [
                'label_for'   => 'wpsc_redis_cache',
                'option_name' => 'redis_cache',
                'description' => __('Enable Redis object caching', 'WPS-Cache')
            ]
        );
        add_settings_field(
            'wpsc_varnish_cache',
            __('Varnish Cache', 'WPS-Cache'),
            [$this->renderer, 'renderCheckboxField'],
            'WPS-Cache',
            'wpsc_cache_settings',
            [
                'label_for'   => 'wpsc_varnish_cache',
                'option_name' => 'varnish_cache',
                'description' => __('Enable Varnish HTTP cache', 'WPS-Cache')
            ]
        );
        add_settings_field(
            'wpsc_css_minify',
            __('CSS Minification', 'WPS-Cache'),
            [$this->renderer, 'renderCheckboxField'],
            'WPS-Cache',
            'wpsc_cache_settings',
            [
                'label_for'   => 'wpsc_css_minify',
                'option_name' => 'css_minify',
                'description' => __('Minify CSS (Experimental)', 'WPS-Cache')
            ]
        );
        add_settings_field(
            'wpsc_js_minify',
            __('JS Minification', 'WPS-Cache'),
            [$this->renderer, 'renderCheckboxField'],
            'WPS-Cache',
            'wpsc_cache_settings',
            [
                'label_for'   => 'wpsc_js_minify',
                'option_name' => 'js_minify',
                'description' => __('Minify JS (Experimental)', 'WPS-Cache')
            ]
        );

        // Redis Fields
        add_settings_field(
            'wpsc_redis_host',
            __('Redis Host', 'WPS-Cache'),
            [$this->renderer, 'renderTextField'],
            'WPS-Cache',
            'wpsc_redis_settings',
            [
                'label_for'   => 'wpsc_redis_host',
                'option_name' => 'redis_host',
                'description' => __('Redis server hostname or IP', 'WPS-Cache')
            ]
        );
        add_settings_field(
            'wpsc_redis_port',
            __('Redis Port', 'WPS-Cache'),
            [$this->renderer, 'renderNumberField'],
            'WPS-Cache',
            'wpsc_redis_settings',
            [
                'label_for'   => 'wpsc_redis_port',
                'option_name' => 'redis_port',
                'description' => __('Redis server port', 'WPS-Cache'),
                'min'         => 1,
                'max'         => 65535
            ]
        );
        add_settings_field(
            'wpsc_redis_db',
            __('Redis Database', 'WPS-Cache'),
            [$this->renderer, 'renderNumberField'],
            'WPS-Cache',
            'wpsc_redis_settings',
            [
                'label_for'   => 'wpsc_redis_db',
                'option_name' => 'redis_db',
                'description' => __('Redis database index', 'WPS-Cache'),
                'min'         => 0,
                'max'         => 15
            ]
        );
        add_settings_field(
            'wpsc_redis_password',
            __('Redis Password', 'WPS-Cache'),
            [$this->renderer, 'renderPasswordField'],
            'WPS-Cache',
            'wpsc_redis_settings',
            [
                'label_for'   => 'wpsc_redis_password',
                'option_name' => 'redis_password',
                'description' => __('Redis password (leave blank if none)', 'WPS-Cache')
            ]
        );
        add_settings_field(
            'wpsc_redis_prefix',
            __('Redis Prefix', 'WPS-Cache'),
            [$this->renderer, 'renderTextField'],
            'WPS-Cache',
            'wpsc_redis_settings',
            [
                'label_for'   => 'wpsc_redis_prefix',
                'option_name' => 'redis_prefix',
                'description' => __('Prefix for Redis keys', 'WPS-Cache')
            ]
        );
        add_settings_field(
            'wpsc_redis_persistent',
            __('Persistent Connections', 'WPS-Cache'),
            [$this->renderer, 'renderCheckboxField'],
            'WPS-Cache',
            'wpsc_redis_settings',
            [
                'label_for'   => 'wpsc_redis_persistent',
                'option_name' => 'redis_persistent',
                'description' => __('Use persistent Redis connections', 'WPS-Cache')
            ]
        );
        add_settings_field(
            'wpsc_redis_compression',
            __('Redis Compression', 'WPS-Cache'),
            [$this->renderer, 'renderCheckboxField'],
            'WPS-Cache',
            'wpsc_redis_settings',
            [
                'label_for'   => 'wpsc_redis_compression',
                'option_name' => 'redis_compression',
                'description' => __('Enable compression for Redis data', 'WPS-Cache')
            ]
        );

        // Varnish Fields
        add_settings_field(
            'wpsc_varnish_host',
            __('Varnish Host', 'WPS-Cache'),
            [$this->renderer, 'renderTextField'],
            'WPS-Cache',
            'wpsc_varnish_settings',
            [
                'label_for'   => 'wpsc_varnish_host',
                'option_name' => 'varnish_host',
                'description' => __('Varnish server hostname or IP', 'WPS-Cache')
            ]
        );
        add_settings_field(
            'wpsc_varnish_port',
            __('Varnish Port', 'WPS-Cache'),
            [$this->renderer, 'renderNumberField'],
            'WPS-Cache',
            'wpsc_varnish_settings',
            [
                'label_for'   => 'wpsc_varnish_port',
                'option_name' => 'varnish_port',
                'description' => __('Varnish server port', 'WPS-Cache'),
                'min'         => 1,
                'max'         => 65535
            ]
        );

        // Advanced Settings Fields
        add_settings_field(
            'wpsc_cache_lifetime',
            __('Cache Lifetime', 'WPS-Cache'),
            [$this->renderer, 'renderNumberField'],
            'WPS-Cache',
            'wpsc_advanced_settings',
            [
                'label_for'   => 'wpsc_cache_lifetime',
                'option_name' => 'cache_lifetime',
                'description' => __('Cache lifetime in seconds', 'WPS-Cache'),
                'min'         => 60,
                'max'         => 2592000
            ]
        );
        add_settings_field(
            'wpsc_excluded_urls',
            __('Excluded URLs', 'WPS-Cache'),
            [$this->renderer, 'renderTextareaField'],
            'WPS-Cache',
            'wpsc_advanced_settings',
            [
                'label_for'   => 'wpsc_excluded_urls',
                'option_name' => 'excluded_urls',
                'description' => __('URLs to exclude from caching (one per line)', 'WPS-Cache')
            ]
        );
        add_settings_field(
            'wpsc_object_cache_alloptions_limit',
            __('Alloptions Limit', 'WPS-Cache'),
            [$this->renderer, 'renderNumberField'],
            'WPS-Cache',
            'wpsc_advanced_settings',
            [
                'label_for'   => 'wpsc_object_cache_alloptions_limit',
                'option_name' => 'advanced_settings[object_cache_alloptions_limit]',
                'description' => __('Limit for alloptions object cache', 'WPS-Cache'),
                'min'         => 100,
                'max'         => 5000
            ]
        );
        add_settings_field(
            'wpsc_cache_groups',
            __('Cache Groups', 'WPS-Cache'),
            [$this->renderer, 'renderTextareaField'],
            'WPS-Cache',
            'wpsc_advanced_settings',
            [
                'label_for'   => 'wpsc_cache_groups',
                'option_name' => 'advanced_settings[cache_groups]',
                'description' => __('Cache groups to include (one per line)', 'WPS-Cache')
            ]
        );
    }

    /**
     * Renders the settings tab content
     */
    public function renderTab(): void {
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
    public function getDefaultSettings(): array {
        return self::DEFAULT_SETTINGS;
    }

    /**
     * Updates plugin settings
     *
     * @param array $settings New settings array
     * @return bool Whether the update was successful
     */
    public function updateSettings(array $settings): bool {
        $validated_settings = $this->validator->sanitizeSettings($settings);
        
        $updated = update_option('wpsc_settings', $validated_settings);
        
        if ($updated) {
            $this->cache_manager->clearAllCaches();
            do_action('wpsc_settings_updated', $validated_settings);
        }
        
        return $updated;
    }

    /**
     * Gets all current settings
     *
     * @return array Current settings
     */
    public function getSettings(): array {
        return get_option('wpsc_settings', self::DEFAULT_SETTINGS);
    }

    /**
     * Gets a specific setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed Setting value
     */
    public function getSetting(string $key, mixed $default = null): mixed {
        $settings = $this->getSettings();
        return $settings[$key] ?? $default;
    }
}
