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

    // ... (sanitizeSettings and getDefaultSettings methods from previous turn remain the same) ...
    // Note: Ensure you include the sanitizeSettings and getDefaultSettings logic from Turn 7 here.

    public function getDefaultSettings(): array
    {
        // ... include array from Turn 7 ...
        return [
            'html_cache' => false,
            // ... etc
        ];
    }

    private function getSettings(): array
    {
        return get_option('wpsc_settings', $this->getDefaultSettings());
    }

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
                $this->renderer->renderToggle('redis_cache', 'Redis Object Cache', 'Enable database query caching.', $settings);
                $this->renderer->renderToggle('varnish_cache', 'Varnish Integration', 'Enable Varnish purge headers.', $settings);
            }
        );

        $this->formEnd();
    }

    public function renderCacheTab(): void
    {
        $settings = $this->getSettings();
        $this->formStart();

        $this->renderer->renderCard(
            'Redis Configuration',
            'Connection details for your Redis server.',
            function () use ($settings) {
                $this->renderer->renderInput('redis_host', 'Host', 'e.g., 127.0.0.1', $settings);
                $this->renderer->renderInput('redis_port', 'Port', 'Default: 6379', $settings, 'number');
                $this->renderer->renderInput('redis_password', 'Password', 'Leave empty if none.', $settings, 'password');
                $this->renderer->renderInput('redis_prefix', 'Prefix', 'Unique prefix for this site.', $settings);
            }
        );

        $this->renderer->renderCard(
            'Exclusions',
            'Prevent specific content from being cached.',
            function () use ($settings) {
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
            'Improve First Contentful Paint (FCP).',
            function () use ($settings) {
                $this->renderer->renderToggle('css_minify', 'Minify CSS', 'Remove whitespace and comments.', $settings);
                $this->renderer->renderToggle('css_async', 'Optimize CSS Delivery', 'Load CSS asynchronously to fix render blocking.', $settings);
                $this->renderer->renderTextarea('excluded_css', 'Exclude CSS', 'Filenames to exclude.', $settings);
            }
        );

        $this->renderer->renderCard(
            'JavaScript Optimization',
            'Improve Time to Interactive (TTI).',
            function () use ($settings) {
                $this->renderer->renderToggle('js_minify', 'Minify JS', 'Compress JavaScript files.', $settings);
                $this->renderer->renderToggle('js_defer', 'Defer JS', 'Load JS after HTML parsing.', $settings);
                $this->renderer->renderToggle('js_delay', 'Delay JS', 'Wait for user interaction.', $settings);
                $this->renderer->renderTextarea('excluded_js', 'Exclude JS', 'Filenames to exclude.', $settings);
            }
        );

        $this->formEnd();
    }

    public function renderAdvancedTab(): void
    {
        // Advanced tab content...
        $this->formStart();
        $settings = $this->getSettings();
        $this->renderer->renderCard(
            'Cache Lifespan',
            'How long should cache files exist?',
            function () use ($settings) {
                $this->renderer->renderInput('cache_lifetime', 'Global TTL (seconds)', 'Default: 3600', $settings, 'number');
            }
        );
        $this->formEnd();
    }
}
