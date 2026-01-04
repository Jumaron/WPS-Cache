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

        add_action("admin_init", [$this, "registerSettings"]);
    }

    public function registerSettings(): void
    {
        register_setting("wpsc_settings", "wpsc_settings", [
            "type" => "array",
            "sanitize_callback" => [$this->validator, "sanitizeSettings"],
            "default" => $this->getDefaultSettings(),
        ]);
    }

    private function getSettings(): array
    {
        $defaults = $this->getDefaultSettings();
        $current = get_option("wpsc_settings", []);
        return is_array($current)
            ? array_merge($defaults, $current)
            : $defaults;
    }

    public function getDefaultSettings(): array
    {
        return \WPSCache\Plugin::DEFAULT_SETTINGS;
    }

    private function formStart(): void
    {
        echo '<form action="options.php" method="post" class="wpsc-form">';
        settings_fields("wpsc_settings");
    }

    private function formEnd(): void
    {
        echo '<div style="margin-top: 20px;">';
        echo '<button type="submit" name="submit" id="submit" class="button button-primary wpsc-btn-primary">';
        echo esc_html__("Save Changes", "wps-cache");
        echo '</button>';
        echo "</div>";
        echo "</form>";
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
    }
    public function renderCdnTab(): void
    {
        $this->renderCdnTabContent($this->getSettings());
    }
    public function renderOptimizationTab(): void
    {
        $this->renderOptimizationTabContent($this->getSettings());
    }
    public function renderTweaksTab(): void
    {
        $this->renderTweaksTabContent($this->getSettings());
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
        $confirm = esc_js(__("Are you sure you want to purge all caches?", "wps-cache"));
        echo '<a href="' .
            wp_nonce_url(
                admin_url("admin-post.php?action=wpsc_clear_cache"),
                "wpsc_clear_cache",
            ) .
            '" class="button wpsc-btn-danger" onclick="return confirm(\'' . $confirm . '\');"><span class="dashicons dashicons-trash" aria-hidden="true" style="vertical-align: middle;"></span> Purge All Caches</a>';
        echo "</div>";
        $this->formStart();
        $this->renderer->renderCard(
            "Global Configuration",
            "Master switches for the caching system.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "html_cache",
                    "Page Caching",
                    "Enable static HTML caching.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "enable_metrics",
                    "Analytics",
                    "Collect performance metrics.",
                    $settings,
                );
            },
        );
        $this->renderer->renderCard(
            "Preloading",
            "Automatically generate cache.",
            function () use ($settings) {
                $this->renderer->renderSelect(
                    "preload_interval",
                    "Interval",
                    "How often to restart.",
                    $settings,
                    [
                        "hourly" => "Hourly",
                        "daily" => "Daily",
                        "weekly" => "Weekly",
                    ],
                );
            },
        );
        $this->formEnd();
    }
    private function renderCacheTabContent(array $settings): void
    {
        $this->formStart();

        $this->renderer->renderCard(
            "Caching Engines",
            "Backend storage configuration.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "redis_cache",
                    "Redis Object Cache",
                    "Enable database query caching.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "varnish_cache",
                    "Varnish Integration",
                    "Enable Varnish purge headers.",
                    $settings,
                );
            },
        );

        if (class_exists("WooCommerce")) {
            $this->renderer->renderCard(
                "eCommerce Compatibility",
                "Ensure shop functionality works correctly.",
                function () use ($settings) {
                    $this->renderer->renderToggle(
                        "woo_support",
                        "WooCommerce Optimization",
                        "Excludes Cart, Checkout, and Account pages. Disables caching when items are in the cart.",
                        $settings,
                    );
                },
            );
        }

        $this->renderer->renderCard(
            "Redis Details",
            "Connection settings.",
            function () use ($settings) {
                $this->renderer->renderInput(
                    "redis_host",
                    "Host",
                    "e.g., 127.0.0.1",
                    $settings,
                    "text",
                    ["placeholder" => "127.0.0.1"],
                );
                $this->renderer->renderInput(
                    "redis_port",
                    "Port",
                    "Default: 6379",
                    $settings,
                    "number",
                    ["placeholder" => "6379"],
                );
                $this->renderer->renderInput(
                    "redis_password",
                    "Password",
                    "Leave empty to keep unchanged.",
                    $settings,
                    "password",
                );
                $this->renderer->renderInput(
                    "redis_db",
                    "Database ID",
                    "Default: 0",
                    $settings,
                    "number",
                    ["placeholder" => "0"],
                );
                $this->renderer->renderInput(
                    "redis_prefix",
                    "Prefix",
                    "Key prefix.",
                    $settings,
                    "text",
                    ["placeholder" => "wpsc_"],
                );
            },
        );
        $this->renderer->renderCard("Rules", "What to cache.", function () use (
            $settings,
        ) {
            $this->renderer->renderInput(
                "cache_lifetime",
                "TTL (Seconds)",
                "Default: 3600",
                $settings,
                "number",
                ["placeholder" => "3600"],
            );
            $this->renderer->renderTextarea(
                "excluded_urls",
                "Excluded URLs",
                "One URL or path per line.",
                $settings,
                ["placeholder" => "e.g. /my-account/\n/checkout/"],
            );
        });
        $this->formEnd();
    }
    private function renderMediaTabContent(array $settings): void
    {
        $this->formStart();
        $this->renderer->renderCard(
            "Lazy Loading",
            "Improve page load by loading media only when visible.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "media_lazy_load",
                    "Lazy Load Images",
                    "Use native browser lazy loading.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "media_lazy_load_iframes",
                    "Lazy Load Iframes",
                    "Native lazy loading for embeds.",
                    $settings,
                );
                $this->renderer->renderInput(
                    "media_lazy_load_exclude_count",
                    "Excluded Images (LCP)",
                    "Number of images to skip from top (prevents LCP delay). Recommended: 3",
                    $settings,
                    "number",
                    ["placeholder" => "3"],
                );
            },
        );
        $this->renderer->renderCard(
            "Media Optimization",
            "Reduce layout shifts and JS weight.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "media_add_dimensions",
                    "Add Missing Dimensions",
                    "Fixes Cumulative Layout Shift (CLS).",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "media_youtube_facade",
                    "YouTube Facade",
                    "Replace heavy YouTube player with a static thumbnail. Loads player on click.",
                    $settings,
                );
            },
        );
        $this->formEnd();
    }

    private function renderCdnTabContent(array $settings): void
    {
        $this->formStart();

        $this->renderer->renderCard(
            "CDN Configuration",
            "Serve static assets from a global network.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "cdn_enable",
                    "Enable CDN Rewrite",
                    "Replace local URLs with CDN URLs.",
                    $settings,
                );
                $this->renderer->renderInput(
                    "cdn_url",
                    "CDN CNAME / URL",
                    "e.g. https://cdn.example.com",
                    $settings,
                    "text",
                    ["placeholder" => "https://cdn.example.com"],
                );
            },
        );

        $this->renderer->renderCard(
            "Cloudflare Integration",
            "Synchronize local cache with Cloudflare Edge.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "cf_enable",
                    "Enable Cloudflare",
                    "Purge Cloudflare cache when local cache is cleared.",
                    $settings,
                );
                $this->renderer->renderInput(
                    "cf_api_token",
                    "API Token",
                    'Cloudflare API Token with "Zone.Cache:Purge" permissions.',
                    $settings,
                    "password",
                );
                $this->renderer->renderInput(
                    "cf_zone_id",
                    "Zone ID",
                    "Found in Cloudflare Dashboard Overview.",
                    $settings,
                    "text",
                    ["placeholder" => "e.g. 023e105f4ecef8ad9ca31a8372d0c353"],
                );
            },
        );

        $this->formEnd();
    }

    private function renderOptimizationTabContent(array $settings): void
    {
        $this->formStart();
        $this->renderer->renderCard(
            "Instant Click (Speculative Loading)",
            "Prerender pages before the user clicks. 0ms Navigation.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "speculative_loading",
                    "Enable Instant Click",
                    "Use Speculation Rules API.",
                    $settings,
                );
                $this->renderer->renderSelect(
                    "speculation_mode",
                    "Mode",
                    "Prerender fully renders (Fastest). Prefetch only downloads HTML.",
                    $settings,
                    [
                        "prerender" => "Prerender (SOTA - 0ms)",
                        "prefetch" => "Prefetch (Standard)",
                    ],
                );
            },
        );
        $this->renderer->renderCard(
            "Font Optimization",
            "Performance for Google Fonts and Local Fonts.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "font_localize_google",
                    "Localize Google Fonts",
                    "Download Google Fonts to your server. Removes DNS lookups and tracking.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "font_display_swap",
                    "Force Font Swap",
                    "Add font-display: swap to all fonts to ensure text remains visible during load.",
                    $settings,
                );
            },
        );
        $this->renderer->renderCard(
            "CSS Optimization",
            "Improve First Contentful Paint.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "css_minify",
                    "Minify CSS",
                    "Strip whitespace/comments.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "remove_unused_css",
                    "Remove Unused CSS",
                    "Automatically strip unused rules (Inline Styles & Theme). SOTA Tree Shaking.",
                    $settings,
                );
                $this->renderer->renderTextarea(
                    "excluded_css",
                    "Exclude CSS",
                    "Filenames to skip.",
                    $settings,
                    ["placeholder" => "e.g. style.css\nbootstrap.min.css"],
                );
            },
        );
        $this->renderer->renderCard(
            "JavaScript Optimization",
            "Improve Time to Interactive.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "js_minify",
                    "Minify JS",
                    "Compress JS files.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "js_defer",
                    "Defer JS",
                    "Move to footer execution.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "js_delay",
                    "Delay JS",
                    "Wait for user interaction.",
                    $settings,
                );
                $this->renderer->renderTextarea(
                    "excluded_js",
                    "Exclude JS",
                    "Filenames to skip.",
                    $settings,
                    ["placeholder" => "e.g. jquery.js\nanalytics.js"],
                );
            },
        );
        $this->formEnd();
    }

    private function renderTweaksTabContent(array $settings): void
    {
        $this->formStart();
        $this->renderer->renderCard(
            "Script & Header Cleanup",
            "Remove unnecessary code and version tracking.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "bloat_disable_emojis",
                    "Disable Emojis",
                    "Removes WP Emoji scripts (~6KB).",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_disable_embeds",
                    "Disable Embeds",
                    "Prevents external sites from embedding your content.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_remove_jquery_migrate",
                    "Remove jQuery Migrate",
                    "Stops loading legacy jQuery support script.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_remove_dashicons",
                    "Remove Dashicons",
                    "Unloads Admin Icons on Frontend (for non-logged in users).",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_remove_query_strings",
                    "Remove Query Strings",
                    "Removes ?ver=x.x from URLs.",
                    $settings,
                );
            },
        );
        $this->renderer->renderCard(
            "Security & Privacy Tweaks",
            "Harden your site headers.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "bloat_disable_xmlrpc",
                    "Disable XML-RPC",
                    "Prevents DDoS and Brute Force attacks.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_hide_wp_version",
                    "Hide WP Version",
                    "Removes version meta tag.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_remove_wlw_rsd",
                    "Remove WLW & RSD",
                    "Removes Windows Live Writer headers.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_remove_shortlink",
                    "Remove Shortlinks",
                    "Removes shortlink meta tag.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_disable_self_pingbacks",
                    "Disable Self Pingbacks",
                    "Prevents site from pinging itself.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_disable_rss",
                    "Disable RSS Feeds",
                    "Use only if this is a static business site.",
                    $settings,
                );
            },
        );
        $this->renderer->renderCard(
            "Heartbeat API Control",
            "Limit server resource usage.",
            function () use ($settings) {
                $this->renderer->renderSelect(
                    "heartbeat_frequency",
                    "Frequency",
                    "How often the browser calls the server.",
                    $settings,
                    [
                        "15" => "15 Seconds (Standard)",
                        "30" => "30 Seconds",
                        "60" => "60 Seconds (Efficient)",
                        "120" => "120 Seconds (Very Slow)",
                    ],
                );
                echo '<p class="wpsc-setting-label" style="margin-top:15px;">Disable Heartbeat Locations</p>';
                $this->renderer->renderToggle(
                    "heartbeat_disable_admin",
                    "Disable in Admin",
                    "Disable everywhere in backend.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "heartbeat_disable_dashboard",
                    "Disable on Dashboard",
                    "Disable only on the main Dashboard widget page.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "heartbeat_disable_frontend",
                    "Disable on Frontend",
                    "Disable for logged-in users viewing the site.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "heartbeat_disable_editor",
                    "Disable in Post Editor",
                    "WARNING: Disables Auto-save and Revision tracking.",
                    $settings,
                );
            },
        );
        $this->formEnd();
    }

    private function renderAdvancedTabContent(array $settings): void
    {
        $this->formStart();
        $this->renderer->renderCard(
            "Varnish Details",
            "Connection for PURGE requests.",
            function () use ($settings) {
                $this->renderer->renderInput(
                    "varnish_host",
                    "Host",
                    "127.0.0.1",
                    $settings,
                    "text",
                    ["placeholder" => "127.0.0.1"],
                );
                $this->renderer->renderInput(
                    "varnish_port",
                    "Port",
                    "6081",
                    $settings,
                    "number",
                    ["placeholder" => "6081"],
                );
            },
        );
        $this->formEnd();
    }

    private function renderDatabaseTabContent(array $settings): void
    {
        $optimizer = \WPSCache\Plugin::getInstance()->getDatabaseOptimizer();
        $stats = $optimizer->getStats();
        $items = DatabaseOptimizer::ITEMS;
        $this->formStart();
        $this->renderer->renderCard(
            "Automatic Cleanup",
            "Schedule automated maintenance.",
            function () use ($settings) {
                $this->renderer->renderSelect(
                    "db_schedule",
                    "Frequency",
                    "How often to run cleanup.",
                    $settings,
                    [
                        "disabled" => "Disabled",
                        "daily" => "Daily",
                        "weekly" => "Weekly",
                        "monthly" => "Monthly",
                    ],
                );
                echo '<p class="wpsc-setting-desc" style="margin-top:10px;">Select items below to include in the scheduled cleanup.</p>';
            },
        );
        $this->renderer->renderCard(
            "Cleanup Options & Manual Run",
            "Select items to clean.",
            function () use ($settings, $stats, $items) {
                echo '<div style="margin-bottom: 20px; display:flex; justify-content:flex-end;">';
                echo '<button type="button" id="wpsc-db-optimize" class="button wpsc-btn-primary"><span class="dashicons dashicons-database" aria-hidden="true" style="vertical-align: middle;"></span> Optimize Selected Now</button>';
                echo "</div>";
                echo '<div id="wpsc-db-status" role="status" aria-live="polite" style="margin-bottom:20px; text-align:right; font-weight:600;"></div>';
                foreach ($items as $key => $label) {

                    $count = $stats[$key] ?? 0;
                    $display =
                        $key === "optimize_tables"
                            ? "Overhead: {$count}"
                            : "Count: {$count}";
                    $checked = !empty($settings["db_clean_" . $key]);
                    $descId = "wpsc_db_desc_" . esc_attr($key);
                    ?>
                <div class="wpsc-setting-row">
                    <div class="wpsc-setting-info">
                        <label class="wpsc-setting-label" for="wpsc_db_clean_<?php echo esc_attr(
                            $key,
                        ); ?>"><?php echo esc_html($label); ?></label>
                        <p class="wpsc-setting-desc" id="<?php echo $descId; ?>" style="color: var(--wpsc-primary);"><?php echo esc_html(
                            $display,
                        ); ?></p>
                    </div>
                    <div class="wpsc-setting-control">
                        <input type="hidden" name="wpsc_settings[db_clean_<?php echo esc_attr(
                            $key,
                        ); ?>]" value="0">
                        <label class="wpsc-switch">
                            <input type="checkbox" class="wpsc-db-checkbox" aria-describedby="<?php echo $descId; ?>" data-key="<?php echo esc_attr(
                                $key,
                            ); ?>" id="wpsc_db_clean_<?php echo esc_attr(
    $key,
); ?>" name="wpsc_settings[db_clean_<?php echo esc_attr(
    $key,
); ?>]" value="1" <?php checked($checked); ?>>
                            <span class="wpsc-slider"></span>
                        </label>
                    </div>
                </div>
                <?php
                }
            },
        );
        $this->formEnd();?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('wpsc-db-optimize');
            const status = document.getElementById('wpsc-db-status');
            btn.addEventListener('click', function() {
                const items = [];
                document.querySelectorAll('.wpsc-db-checkbox:checked').forEach(el => { items.push(el.dataset.key); });
                if (items.length === 0) {
                    status.style.color = 'var(--wpsc-danger)';
                    status.textContent = 'Please select at least one item to clean.';
                    return;
                }
                if (!btn.dataset.originalText) { btn.dataset.originalText = btn.innerHTML; }
                btn.disabled = true;
                btn.innerHTML = '<span class="dashicons dashicons-update wpsc-spin" aria-hidden="true" style="vertical-align: middle;"></span> Optimizing...';
                status.textContent = '';
                fetch(wpsc_admin.ajax_url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({ action: 'wpsc_manual_db_cleanup', _ajax_nonce: wpsc_admin.nonce, 'items[]': items })
                }).then(res => res.json()).then(res => {
                    if(res.success) {
                        btn.innerHTML = '<span class="dashicons dashicons-yes" aria-hidden="true" style="vertical-align: middle;"></span> Cleaned!';
                        btn.style.backgroundColor = 'var(--wpsc-success)';
                        btn.style.borderColor = 'var(--wpsc-success)';
                        btn.style.color = 'white';
                        status.style.color = 'var(--wpsc-success)';
                        status.textContent = res.data;
                        if (typeof announce === "function") { announce(res.data); }
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = btn.dataset.originalText;
                        status.style.color = 'var(--wpsc-danger)';
                        status.textContent = res.data;
                    }
                }).catch(err => {
                    btn.disabled = false;
                    btn.innerHTML = btn.dataset.originalText;
                    status.style.color = 'var(--wpsc-danger)';
                    status.textContent = 'Optimization failed. Please try again.';
                });
            });
        });
        </script>
        <?php
    }
}
