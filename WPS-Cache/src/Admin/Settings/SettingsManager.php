<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Cache\CacheManager;
use WPSCache\Optimization\DatabaseOptimizer;

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

    private function getSettings(): array
    {
        $defaults = $this->getDefaultSettings();
        $current = get_option('wpsc_settings', []);
        return is_array($current) ? array_merge($defaults, $current) : $defaults;
    }

    public function getDefaultSettings(): array
    {
        return \WPSCache\Plugin::DEFAULT_SETTINGS;
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
        $this->renderDashboardTabContent($this->getSettings());
    }
    public function renderCacheTab(): void
    {
        $this->renderCacheTabContent($this->getSettings());
    }
    public function renderMediaTab(): void
    {
        $this->renderMediaTabContent($this->getSettings());
    } // New
    public function renderOptimizationTab(): void
    {
        $this->renderOptimizationTabContent($this->getSettings());
    }
    public function renderAdvancedTab(): void
    {
        $this->renderAdvancedTabContent($this->getSettings());
    }
    public function renderDatabaseTab(): void
    {
        $this->renderDatabaseTabContent($this->getSettings());
    }

    private function renderDashboardTabContent(array $settings): void
    {
        echo '<div style="margin-bottom: 20px; text-align:right;">';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=wpsc_clear_cache'), 'wpsc_clear_cache') . '" class="button wpsc-btn-secondary" style="color: #ef4444; border-color: #ef4444;"><span class="dashicons dashicons-trash" style="vertical-align:text-bottom"></span> Purge All Caches</a>';
        echo '</div>';
        $this->formStart();
        $this->renderer->renderCard('Global Configuration', 'Master switches for the caching system.', function () use ($settings) {
            $this->renderer->renderToggle('html_cache', 'Page Caching', 'Enable static HTML caching.', $settings);
            $this->renderer->renderToggle('enable_metrics', 'Analytics', 'Collect performance metrics.', $settings);
        });
        $this->renderer->renderCard('Preloading', 'Automatically generate cache.', function () use ($settings) {
            $this->renderer->renderSelect('preload_interval', 'Interval', 'How often to restart.', $settings, ['hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly']);
        });
        $this->formEnd();
    }

    private function renderCacheTabContent(array $settings): void
    {
        $this->formStart();
        $this->renderer->renderCard('Caching Engines', 'Backend storage configuration.', function () use ($settings) {
            $this->renderer->renderToggle('redis_cache', 'Redis Object Cache', 'Enable database query caching.', $settings);
            $this->renderer->renderToggle('varnish_cache', 'Varnish Integration', 'Enable Varnish purge headers.', $settings);
        });
        $this->renderer->renderCard('Redis Details', 'Connection settings.', function () use ($settings) {
            $this->renderer->renderInput('redis_host', 'Host', 'e.g., 127.0.0.1', $settings);
            $this->renderer->renderInput('redis_port', 'Port', 'Default: 6379', $settings, 'number');
            $this->renderer->renderInput('redis_password', 'Password', 'Leave empty to keep unchanged.', $settings, 'password');
            $this->renderer->renderInput('redis_db', 'Database ID', 'Default: 0', $settings, 'number');
            $this->renderer->renderInput('redis_prefix', 'Prefix', 'Key prefix.', $settings);
        });
        $this->renderer->renderCard('Rules', 'What to cache.', function () use ($settings) {
            $this->renderer->renderInput('cache_lifetime', 'TTL (Seconds)', 'Default: 3600', $settings, 'number');
            $this->renderer->renderTextarea('excluded_urls', 'Excluded URLs', 'One URL or path per line.', $settings);
        });
        $this->formEnd();
    }

    private function renderMediaTabContent(array $settings): void
    {
        $this->formStart();

        $this->renderer->renderCard('Lazy Loading', 'Improve page load by loading media only when visible.', function () use ($settings) {
            $this->renderer->renderToggle('media_lazy_load', 'Lazy Load Images', 'Use native browser lazy loading.', $settings);
            $this->renderer->renderToggle('media_lazy_load_iframes', 'Lazy Load Iframes', 'Native lazy loading for embeds.', $settings);

            $this->renderer->renderInput('media_lazy_load_exclude_count', 'Excluded Images (LCP)', 'Number of images to skip from top (prevents LCP delay). Recommended: 3', $settings, 'number');
        });

        $this->renderer->renderCard('Media Optimization', 'Reduce layout shifts and JS weight.', function () use ($settings) {
            $this->renderer->renderToggle('media_add_dimensions', 'Add Missing Dimensions', 'Fixes Cumulative Layout Shift (CLS).', $settings);
            $this->renderer->renderToggle('media_youtube_facade', 'YouTube Facade', 'Replace heavy YouTube player with a static thumbnail. Loads player on click.', $settings);
        });

        $this->formEnd();
    }

    private function renderOptimizationTabContent(array $settings): void
    {
        $this->formStart();
        $this->renderer->renderCard('Instant Click (Speculative Loading)', 'Prerender pages before the user clicks. 0ms Navigation.', function () use ($settings) {
            $this->renderer->renderToggle('speculative_loading', 'Enable Instant Click', 'Use Speculation Rules API.', $settings);
            $this->renderer->renderSelect('speculation_mode', 'Mode', 'Prerender fully renders (Fastest). Prefetch only downloads HTML.', $settings, ['prerender' => 'Prerender (SOTA - 0ms)', 'prefetch' => 'Prefetch (Standard)']);
        });
        $this->renderer->renderCard('CSS Optimization', 'Improve First Contentful Paint.', function () use ($settings) {
            $this->renderer->renderToggle('css_minify', 'Minify CSS', 'Strip whitespace/comments.', $settings);
            $this->renderer->renderToggle('css_async', 'Async CSS', 'Load non-critical CSS later.', $settings);
            $this->renderer->renderTextarea('excluded_css', 'Exclude CSS', 'Filenames to skip.', $settings);
        });
        $this->renderer->renderCard('JavaScript Optimization', 'Improve Time to Interactive.', function () use ($settings) {
            $this->renderer->renderToggle('js_minify', 'Minify JS', 'Compress JS files.', $settings);
            $this->renderer->renderToggle('js_defer', 'Defer JS', 'Move to footer execution.', $settings);
            $this->renderer->renderToggle('js_delay', 'Delay JS', 'Wait for user interaction.', $settings);
            $this->renderer->renderTextarea('excluded_js', 'Exclude JS', 'Filenames to skip.', $settings);
        });
        $this->formEnd();
    }

    private function renderAdvancedTabContent(array $settings): void
    {
        $this->formStart();
        $this->renderer->renderCard('Varnish Details', 'Connection for PURGE requests.', function () use ($settings) {
            $this->renderer->renderInput('varnish_host', 'Host', '127.0.0.1', $settings);
            $this->renderer->renderInput('varnish_port', 'Port', '6081', $settings, 'number');
        });
        $this->formEnd();
    }

    private function renderDatabaseTabContent(array $settings): void
    {
        $optimizer = \WPSCache\Plugin::getInstance()->getDatabaseOptimizer();
        $stats = $optimizer->getStats();
        $items = DatabaseOptimizer::ITEMS;

        $this->formStart();
        $this->renderer->renderCard('Automatic Cleanup', 'Schedule automated maintenance.', function () use ($settings) {
            $this->renderer->renderSelect('db_schedule', 'Frequency', 'How often to run cleanup.', $settings, ['disabled' => 'Disabled', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly']);
            echo '<p class="wpsc-setting-desc" style="margin-top:10px;">Select items below to include in the scheduled cleanup.</p>';
        });

        $this->renderer->renderCard('Cleanup Options & Manual Run', 'Select items to clean.', function () use ($settings, $stats, $items) {
            echo '<div style="margin-bottom: 20px; display:flex; justify-content:flex-end;">';
            echo '<button type="button" id="wpsc-db-optimize" class="button wpsc-btn-primary">Optimize Selected Now</button>';
            echo '</div>';
            echo '<div id="wpsc-db-status" style="margin-bottom:20px; text-align:right; font-weight:600; color:var(--wpsc-success);"></div>';

            foreach ($items as $key => $label) {
                $count = $stats[$key] ?? 0;
                $display = ($key === 'optimize_tables') ? "Overhead: {$count}" : "Count: {$count}";
                $checked = !empty($settings['db_clean_' . $key]);
                ?>
                <div class="wpsc-setting-row">
                    <div class="wpsc-setting-info">
                        <label class="wpsc-setting-label" for="wpsc_db_clean_<?php echo esc_attr($key); ?>">
                            <?php echo esc_html($label); ?>
                        </label>
                        <p class="wpsc-setting-desc" style="color: var(--wpsc-primary);"><?php echo esc_html($display); ?></p>
                    </div>
                    <div class="wpsc-setting-control">
                        <input type="hidden" name="wpsc_settings[db_clean_<?php echo esc_attr($key); ?>]" value="0">
                        <label class="wpsc-switch">
                            <input type="checkbox" class="wpsc-db-checkbox" data-key="<?php echo esc_attr($key); ?>"
                                id="wpsc_db_clean_<?php echo esc_attr($key); ?>"
                                name="wpsc_settings[db_clean_<?php echo esc_attr($key); ?>]" value="1" <?php checked($checked); ?>>
                            <span class="wpsc-slider"></span>
                        </label>
                    </div>
                </div>
                <?php
            }
        });
        $this->formEnd();
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const btn = document.getElementById('wpsc-db-optimize');
                const status = document.getElementById('wpsc-db-status');

                btn.addEventListener('click', function () {
                    const items = [];
                    document.querySelectorAll('.wpsc-db-checkbox:checked').forEach(el => { items.push(el.dataset.key); });
                    if (items.length === 0) { alert('Please select at least one item to clean.'); return; }

                    btn.disabled = true; btn.innerHTML = 'Optimizing...'; status.innerHTML = '';

                    fetch(wpsc_admin.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ action: 'wpsc_manual_db_cleanup', _ajax_nonce: wpsc_admin.nonce, 'items[]': items })
                    }).then(res => res.json()).then(res => {
                        btn.disabled = false; btn.innerHTML = 'Optimize Selected Now';
                        if (res.success) { status.innerHTML = res.data; setTimeout(() => window.location.reload(), 1500); }
                        else { alert(res.data); }
                    });
                });
            });
        </script>
        <?php
    }
}