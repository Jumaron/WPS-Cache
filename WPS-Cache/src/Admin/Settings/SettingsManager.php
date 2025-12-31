<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Cache\CacheManager;

/**
 * Manages the settings logic and organizes the modern UI tabs
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
     * Uses the Options API for secure saving
     */
    public function registerSettings(): void
    {
        register_setting(
            'wpsc_settings',      // Option group
            'wpsc_settings',      // Option name
            [
                'type'              => 'array',
                'sanitize_callback' => [$this->validator, 'sanitizeSettings'],
                'default'           => $this->getDefaultSettings()
            ]
        );
    }

    /**
     * Render "Dashboard" Tab (General Overview & Global Settings)
     */
    public function renderDashboardTab(): void
    {
        $settings = $this->getSettings();

        $this->renderer->renderSettingsFormStart();

        // Global On/Off Switches
        $this->renderer->renderCard(
            __('Global Configuration', 'wps-cache'),
            __('Essential settings to get your site running fast immediately.', 'wps-cache'),
            function () use ($settings) {
                $this->renderer->renderToggleRow(
                    'html_cache',
                    __('Page Caching', 'wps-cache'),
                    __('Cache static HTML pages to reduce server load and improve TTFB.', 'wps-cache'),
                    $settings
                );

                $this->renderer->renderToggleRow(
                    'enable_metrics',
                    __('Performance Analytics', 'wps-cache'),
                    __('Collect and display cache hit ratios and performance trends on the dashboard.', 'wps-cache'),
                    $settings
                );
            }
        );

        // Quick Preload Configuration
        $this->renderer->renderCard(
            __('Cache Preloading', 'wps-cache'),
            __('Automatically generate cache for your pages.', 'wps-cache'),
            function () use ($settings) {
                $this->renderer->renderInputRow(
                    'preload_interval',
                    __('Preload Interval', 'wps-cache'),
                    __('How often the preload process should restart (e.g., daily, weekly).', 'wps-cache'),
                    $settings,
                    'select',
                    ['options' => [
                        'hourly' => 'Hourly',
                        'daily' => 'Daily',
                        'weekly' => 'Weekly'
                    ]]
                );
            }
        );

        $this->renderer->renderSettingsFormEnd();
    }

    /**
     * Render "Cache" Tab (Backend & Rules)
     */
    public function renderCacheTab(): void
    {
        $settings = $this->getSettings();
        $this->renderer->renderSettingsFormStart();

        // Caching Engines
        $this->renderer->renderCard(
            __('Caching Engines', 'wps-cache'),
            __('Select the backend technologies used for caching.', 'wps-cache'),
            function () use ($settings) {
                $this->renderer->renderToggleRow(
                    'redis_cache',
                    __('Redis Object Cache', 'wps-cache'),
                    __('Use Redis to cache database queries (Requires Redis server).', 'wps-cache'),
                    $settings
                );

                $this->renderer->renderToggleRow(
                    'varnish_cache',
                    __('Varnish Cache', 'wps-cache'),
                    __('Enable Varnish HTTP purging and compatibility.', 'wps-cache'),
                    $settings
                );
            }
        );

        // Redis Connection Details (Only visible if Redis is enabled conceptually, though usually shown for config)
        $this->renderer->renderCard(
            __('Redis Connection', 'wps-cache'),
            __('Configure your Redis server connection details.', 'wps-cache'),
            function () use ($settings) {
                $this->renderer->renderInputRow('redis_host', __('Host', 'wps-cache'), 'e.g., 127.0.0.1', $settings);
                $this->renderer->renderInputRow('redis_port', __('Port', 'wps-cache'), 'Default: 6379', $settings, 'number');
                $this->renderer->renderInputRow('redis_password', __('Password', 'wps-cache'), 'Leave empty if none', $settings, 'password');
                $this->renderer->renderInputRow('redis_db', __('Database ID', 'wps-cache'), 'Default: 0', $settings, 'number');
                $this->renderer->renderInputRow('redis_prefix', __('Key Prefix', 'wps-cache'), 'Default: wpsc:', $settings);
            }
        );

        // Varnish Connection Details
        $this->renderer->renderCard(
            __('Varnish Connection', 'wps-cache'),
            __('Configure Varnish server IP for purging.', 'wps-cache'),
            function () use ($settings) {
                $this->renderer->renderInputRow('varnish_host', __('Host', 'wps-cache'), 'e.g., 127.0.0.1', $settings);
                $this->renderer->renderInputRow('varnish_port', __('Port', 'wps-cache'), 'Default: 6081', $settings, 'number');
            }
        );

        // Cache Rules
        $this->renderer->renderCard(
            __('Cache Rules', 'wps-cache'),
            __('Fine tune what gets cached and for how long.', 'wps-cache'),
            function () use ($settings) {
                $this->renderer->renderInputRow(
                    'cache_lifetime',
                    __('Cache Lifespan (seconds)', 'wps-cache'),
                    'How long cached files remain valid (Default: 3600).',
                    $settings,
                    'number'
                );

                $this->renderer->renderInputRow(
                    'excluded_urls',
                    __('Exclude URLs', 'wps-cache'),
                    'Enter one URL (or partial path) per line to exclude from caching.',
                    $settings,
                    'textarea'
                );
            }
        );

        $this->renderer->renderSettingsFormEnd();
    }

    /**
     * Render "CSS & JS" Tab (Frontend Optimization)
     */
    public function renderCssJsTab(): void
    {
        $settings = $this->getSettings();
        $this->renderer->renderSettingsFormStart();

        // CSS Optimization
        $this->renderer->renderCard(
            __('CSS Optimization', 'wps-cache'),
            __('Optimize stylesheet delivery to improve First Contentful Paint.', 'wps-cache'),
            function () use ($settings) {
                $this->renderer->renderToggleRow(
                    'css_minify',
                    __('Minify CSS', 'wps-cache'),
                    'Remove whitespace and comments from CSS files to reduce file size.',
                    $settings
                );

                $this->renderer->renderToggleRow(
                    'css_async',
                    __('Load CSS Asynchronously', 'wps-cache'),
                    'Fix render-blocking resources by loading CSS later (Generates Critical CSS).',
                    $settings
                );

                $this->renderer->renderInputRow(
                    'excluded_css',
                    __('Exclude CSS Files', 'wps-cache'),
                    'Enter CSS filenames to exclude from minification (one per line).',
                    $settings,
                    'textarea'
                );
            }
        );

        // JavaScript Optimization
        $this->renderer->renderCard(
            __('JavaScript Optimization', 'wps-cache'),
            __('Optimize script execution to improve Time to Interactive.', 'wps-cache'),
            function () use ($settings) {
                $this->renderer->renderToggleRow(
                    'js_minify',
                    __('Minify JS', 'wps-cache'),
                    'Compress JavaScript files to reduce payload size.',
                    $settings
                );

                $this->renderer->renderToggleRow(
                    'js_defer',
                    __('Defer JavaScript', 'wps-cache'),
                    'Execute scripts after HTML parsing is complete (Safe default).',
                    $settings
                );

                $this->renderer->renderToggleRow(
                    'js_delay',
                    __('Delay JavaScript', 'wps-cache'),
                    'Wait for user interaction before loading scripts (Aggressive performance boost).',
                    $settings
                );

                $this->renderer->renderInputRow(
                    'excluded_js',
                    __('Exclude JS Files', 'wps-cache'),
                    'Enter JS filenames or keywords to exclude from optimization (one per line).',
                    $settings,
                    'textarea'
                );
            }
        );

        $this->renderer->renderSettingsFormEnd();
    }

    /**
     * Gets all current settings
     */
    public function getSettings(): array
    {
        return get_option('wpsc_settings', $this->getDefaultSettings());
    }

    /**
     * Updates plugin settings
     */
    public function updateSettings(array $settings): bool
    {
        $validated_settings = $this->validator->sanitizeSettings($settings);
        $updated = update_option('wpsc_settings', $validated_settings);

        if ($updated) {
            $this->cache_manager->clearAllCaches();
            do_action('wpscac_settings_updated', $validated_settings);
        }

        return $updated;
    }

    /**
     * Gets the default settings array
     */
    public function getDefaultSettings(): array
    {
        return [
            // Core
            'html_cache'         => false,
            'enable_metrics'     => true,
            'metrics_retention'  => 30,

            // Engines
            'redis_cache'        => false,
            'varnish_cache'      => false,

            // Frontend
            'css_minify'         => false,
            'css_async'          => false,
            'js_minify'          => false,
            'js_defer'           => false,
            'js_delay'           => false,

            // Configuration
            'cache_lifetime'     => 3600,
            'excluded_urls'      => [],
            'excluded_css'       => [],
            'excluded_js'        => [
                'jquery',
                'jquery-core',
                'wp-includes/js',
                'google-analytics'
            ],

            // Preload
            'preload_urls'       => [],
            'preload_interval'   => 'daily',

            // Redis Connection
            'redis_host'         => '127.0.0.1',
            'redis_port'         => 6379,
            'redis_db'           => 0,
            'redis_password'     => '',
            'redis_prefix'       => 'wpsc:',
            'redis_persistent'   => false,
            'redis_compression'  => true,

            // Varnish Connection
            'varnish_host'       => '127.0.0.1',
            'varnish_port'       => 6081,

            // Advanced Structure
            'advanced_settings'  => [
                'object_cache_alloptions_limit' => 1000,
                'max_ttl'                       => 86400,
                'cache_groups'                  => [],
                'ignored_groups'                => [],
            ]
        ];
    }
}
