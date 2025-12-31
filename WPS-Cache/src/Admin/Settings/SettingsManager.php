<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Cache\CacheManager;

class SettingsManager
{
    private CacheManager $cacheManager;
    private SettingsRenderer $renderer;
    private SettingsValidator $validator;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
        $this->renderer = new SettingsRenderer();
        $this->validator = new SettingsValidator();

        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerSettings(): void
    {
        register_setting(
            'wpsc_settings',
            'wpsc_settings',
            [
                'type' => 'array',
                'sanitize_callback' => [$this->validator, 'sanitizeSettings'],
                'default' => $this->getDefaultSettings()
            ]
        );
    }

    /**
     * Helper to get settings with defaults merged.
     * Prevents "undefined index" errors in views.
     */
    private function getSettings(): array
    {
        $defaults = $this->getDefaultSettings();
        $current = get_option('wpsc_settings', []);

        if (!is_array($current)) {
            return $defaults;
        }

        return array_merge($defaults, $current);
    }

    public function getDefaultSettings(): array
    {
        return [
            // Switches
            'html_cache'     => false,
            'redis_cache'    => false,
            'varnish_cache'  => false,
            'css_minify'     => false,
            'css_async'      => false,
            'js_minify'      => false,
            'js_defer'       => false,
            'js_delay'       => false,
            'enable_metrics' => true,

            // Config
            'cache_lifetime' => 3600,
            'metrics_retention' => 30,

            // Redis
            'redis_host'     => '127.0.0.1',
            'redis_port'     => 6379,
            'redis_db'       => 0,
            'redis_password' => '',
            'redis_prefix'   => 'wpsc:',

            // Varnish
            'varnish_host'   => '127.0.0.1',
            'varnish_port'   => 6081,

            // Arrays
            'excluded_urls'  => [],
            'excluded_css'   => [],
            'excluded_js'    => [],
            'preload_urls'   => []
        ];
    }

    // --- Form Renderers ---

    private function formStart(): void
    {
        echo '<form action="options.php" method="post" class="wpsc-form">';
        settings_fields('wpsc_settings');
    }

    private function formEnd(): void
    {
        echo '<div style="margin-top: 20px;">';
        submit_button('Save Changes', 'primary wpsc-btn-primary');
        echo '</div>';
        echo '</form>';
    }

    public function renderDashboardTab(): void
    {
        $settings = $this->getSettings();
        $this->formStart();

        $this->renderer->renderCard(
            'Global Configuration',
            'Master switches for the caching system.',
            function () use ($settings) {
                $this->renderer->renderToggle('html_cache', 'Page Caching', 'Enable static HTML caching.', $settings);
                $this->renderer->renderToggle('enable_metrics', 'Analytics', 'Collect performance metrics.', $settings);
            }
        );

        $this->renderer->renderCard(
            'Preloading',
            'Automatically generate cache.',
            function () use ($settings) {
                $this->renderer->renderSelect('preload_interval', 'Interval', 'How often to restart.', $settings, [
                    'hourly' => 'Hourly',
                    'daily' => 'Daily',
                    'weekly' => 'Weekly'
                ]);
            }
        );

        $this->formEnd();
    }

    public function renderCacheTab(): void
    {
        $settings = $this->getSettings();
        $this->formStart();

        $this->renderer->renderCard(
            'Caching Engines',
            'Backend storage configuration.',
            function () use ($settings) {
                $this->renderer->renderToggle('redis_cache', 'Redis Object Cache', 'Enable database query caching.', $settings);
                $this->renderer->renderToggle('varnish_cache', 'Varnish Integration', 'Enable Varnish purge headers.', $settings);
            }
        );

        $this->renderer->renderCard(
            'Redis Details',
            'Connection settings.',
            function () use ($settings) {
                $this->renderer->renderInput('redis_host', 'Host', 'e.g., 127.0.0.1', $settings);
                $this->renderer->renderInput('redis_port', 'Port', 'Default: 6379', $settings, 'number');
                $this->renderer->renderInput('redis_password', 'Password', 'Hidden for security.', $settings, 'password');
                $this->renderer->renderInput('redis_db', 'Database ID', 'Default: 0', $settings, 'number');
                $this->renderer->renderInput('redis_prefix', 'Prefix', 'Key prefix.', $settings);
            }
        );

        $this->renderer->renderCard(
            'Rules',
            'What to cache.',
            function () use ($settings) {
                $this->renderer->renderInput('cache_lifetime', 'TTL (Seconds)', 'Default: 3600', $settings, 'number');
                $this->renderer->renderTextarea('excluded_urls', 'Excluded URLs', 'One URL or path per line.', $settings);
            }
        );

        $this->formEnd();
    }

    public function renderOptimizationTab(): void
    {
        $settings = $this->getSettings();
        $this->formStart();

        $this->renderer->renderCard(
            'CSS Optimization',
            'Improve First Contentful Paint.',
            function () use ($settings) {
                $this->renderer->renderToggle('css_minify', 'Minify CSS', 'Strip whitespace/comments.', $settings);
                $this->renderer->renderToggle('css_async', 'Async CSS', 'Load non-critical CSS later.', $settings);
                $this->renderer->renderTextarea('excluded_css', 'Exclude CSS', 'Filenames to skip.', $settings);
            }
        );

        $this->renderer->renderCard(
            'JavaScript Optimization',
            'Improve Time to Interactive.',
            function () use ($settings) {
                $this->renderer->renderToggle('js_minify', 'Minify JS', 'Compress JS files.', $settings);
                $this->renderer->renderToggle('js_defer', 'Defer JS', 'Move to footer execution.', $settings);
                $this->renderer->renderToggle('js_delay', 'Delay JS', 'Wait for user interaction.', $settings);
                $this->renderer->renderTextarea('excluded_js', 'Exclude JS', 'Filenames to skip.', $settings);
            }
        );

        $this->formEnd();
    }

    public function renderAdvancedTab(): void
    {
        $settings = $this->getSettings();
        $this->formStart();
        $this->renderer->renderCard(
            'Varnish Details',
            'Connection for PURGE requests.',
            function () use ($settings) {
                $this->renderer->renderInput('varnish_host', 'Host', '127.0.0.1', $settings);
                $this->renderer->renderInput('varnish_port', 'Port', '6081', $settings, 'number');
            }
        );
        $this->formEnd();
    }
}
