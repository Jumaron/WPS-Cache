<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Cache\CacheManager;
use WPSCache\Optimization\DatabaseOptimizer;
use WPSCache\Admin\Analytics\MetricsCollector;

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
        echo '<div class="wpsc-sticky-footer">';
        echo '<button type="submit" name="submit" id="submit" class="wpsc-btn-primary">';
        echo '<span class="dashicons dashicons-saved" aria-hidden="true"></span> ';
        echo esc_html__("Save Changes", "wps-cache");
        echo "</button>";
        echo "</div>";
        echo "</form>";
    }

    public function renderDashboardTab(): void
    {
        $settings = $this->getSettings();

        // Metrics
        $collector = new MetricsCollector($this->cacheManager);
        $stats = $collector->getStats();
        $redis = $stats["redis"];
        $html = $stats["html"];
        ?>
        <section class="wpsc-section">
            <div class="wpsc-section-header">
                <h3 class="wpsc-section-title">Performance Overview</h3>
                <p class="wpsc-section-desc">Real-time status of your caching engines.</p>
            </div>
            <div class="wpsc-section-body">
                <div class="wpsc-stats-grid">
                    <div class="wpsc-stat-card">
                        <div>
                            <div class="wpsc-stat-header"><span class="dashicons dashicons-database"></span> Redis Object Cache</div>
                            <?php if (!empty($redis["enabled"])): ?>
                                <?php if (!empty($redis["connected"])): ?>
                                    <div class="wpsc-stat-big-number"><?php echo esc_html(
                                        $redis["hit_ratio"],
                                    ); ?>%</div>
                                    <div style="color: var(--wpsc-text-muted);">Hit Ratio</div>
                                <?php else: ?>
                                    <div class="wpsc-status-pill error">Connection Failed</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="wpsc-status-pill warning">Disabled</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="wpsc-stat-card">
                        <div>
                            <div class="wpsc-stat-header"><span class="dashicons dashicons-html"></span> Page Cache</div>
                            <?php if ($html["enabled"]): ?>
                                <div class="wpsc-stat-big-number"><?php echo esc_html(
                                    $html["files"],
                                ); ?></div>
                                <div style="color: var(--wpsc-text-muted);">Cached Pages</div>
                            <?php else: ?>
                                <div class="wpsc-status-pill warning">Disabled</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="wpsc-stat-card">
                        <div class="wpsc-stat-header"><span class="dashicons dashicons-desktop"></span> Server</div>
                        <div style="font-size: 0.9rem; display:flex; flex-direction:column; gap:5px;">
                            <div>PHP: <strong><?php echo esc_html(
                                $stats["system"]["php_version"],
                            ); ?></strong></div>
                            <div>Server: <strong><?php echo esc_html(
                                $stats["system"]["server"],
                            ); ?></strong></div>
                        </div>
                    </div>
                </div>
                <div style="margin-top: 2rem; display:flex; justify-content:flex-end;">
                    <form method="post" class="wpsc-form">
                        <?php wp_nonce_field("wpsc_refresh_stats"); ?>
                        <input type="hidden" name="wpsc_action" value="refresh_stats">
                        <button type="submit" class="wpsc-btn-secondary" data-loading-text="Refreshing...">
                            <span class="dashicons dashicons-update"></span> Refresh Data
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <?php
        if (
            isset($_POST["wpsc_action"]) &&
            $_POST["wpsc_action"] === "refresh_stats"
        ) {
            check_admin_referer("wpsc_refresh_stats");
            delete_transient("wpsc_stats_cache");
            echo "<script>window.location.reload();</script>";
        }

        // Preloader & Status
        $this->formStart();

        $this->renderer->renderCard(
            "Cache Preloader",
            "Automatically generate cache files.",
            function () use ($settings) {
                ?>
                <div id="wpsc-preload-progress" class="wpsc-progress-container" style="display:none;">
                    <div class="wpsc-progress-header">
                        <span id="wpsc-preload-status" role="status" aria-live="polite">Initializing...</span>
                        <span id="wpsc-preload-percent">0%</span>
                    </div>
                    <progress id="wpsc-preload-bar" class="wpsc-progress-bar" value="0" max="100"></progress>
                </div>
                <div style="display: flex; justify-content: flex-start; margin-top: 15px;">
                    <button type="button" id="wpsc-start-preload" class="wpsc-btn-primary" aria-controls="wpsc-preload-progress">
                        <span class="dashicons dashicons-controls-play"></span> Start Preloading
                    </button>
                </div>
                <?php
            },
        );

        $object_cache_installed = file_exists(
            WP_CONTENT_DIR . "/object-cache.php",
        );
        $this->renderer->renderCard(
            "Object Cache Drop-in",
            "Required for Redis functionality.",
            function () use ($object_cache_installed) {
                ?>
                <div class="wpsc-tool-status-box">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <strong>Status:</strong>
                        <?php if ($object_cache_installed): ?>
                            <span class="wpsc-status-pill success"><span class="dashicons dashicons-yes"></span> Installed</span>
                        <?php else: ?>
                            <span class="wpsc-status-pill warning"><span class="dashicons dashicons-warning"></span> Not Installed</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($object_cache_installed): ?>
                            <a href="<?php echo esc_url(
                                wp_nonce_url(
                                    admin_url(
                                        "admin-post.php?action=wpsc_remove_object_cache",
                                    ),
                                    "wpsc_remove_object_cache",
                                ),
                            ); ?>"
                               class="wpsc-btn-ghost-danger wpsc-confirm-trigger" data-confirm="Disable Object Cache?">Uninstall</a>
                        <?php else: ?>
                            <a href="<?php echo esc_url(
                                wp_nonce_url(
                                    admin_url(
                                        "admin-post.php?action=wpsc_install_object_cache",
                                    ),
                                    "wpsc_install_object_cache",
                                ),
                            ); ?>"
                               class="wpsc-btn-primary">Install Drop-in</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
            },
        );
        $this->formEnd();
    }

    public function renderCacheTab(): void
    {
        $settings = $this->getSettings();
        $this->formStart();

        $this->renderer->renderCard(
            "Page Caching",
            "Serve static HTML copies of your pages.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "html_cache",
                    "Enable Page Caching",
                    "Speed up your site significantly.",
                    $settings,
                );
                $this->renderer->renderInput(
                    "cache_lifetime",
                    "Cache TTL (Seconds)",
                    "Default: 3600",
                    $settings,
                    "number",
                );
                $this->renderer->renderRadioGroup(
                    "preload_interval",
                    "Preload Interval",
                    "How often to regenerate cache automatically.",
                    $settings,
                    [
                        "hourly" => "Hourly",
                        "daily" => "Daily",
                        "weekly" => "Weekly",
                        "disabled" => "Disabled",
                    ],
                );
                $this->renderer->renderTextarea(
                    "excluded_urls",
                    "Excluded URLs",
                    "Pages to never cache (one per line).",
                    $settings,
                );
            },
        );

        $this->renderer->renderCard(
            "Object Cache (Redis)",
            "Cache database queries and dynamic data.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "redis_cache",
                    "Enable Redis",
                    "Requires running Redis server.",
                    $settings,
                );
                echo '<div style="margin-top:15px; padding-left:15px; border-left:2px solid var(--wpsc-border);">';
                $this->renderer->renderInput(
                    "redis_host",
                    "Redis Host",
                    "127.0.0.1",
                    $settings,
                );
                $this->renderer->renderInput(
                    "redis_port",
                    "Redis Port",
                    "6379",
                    $settings,
                    "number",
                );
                $this->renderer->renderInput(
                    "redis_db",
                    "Database ID",
                    "0",
                    $settings,
                    "number",
                );
                $this->renderer->renderInput(
                    "redis_password",
                    "Password",
                    "Optional",
                    $settings,
                    "password",
                );
                $this->renderer->renderInput(
                    "redis_prefix",
                    "Key Prefix",
                    "wpsc:",
                    $settings,
                );
                echo "</div>";
            },
        );

        $this->renderer->renderCard(
            "Server Integration (Varnish)",
            "Send Purge requests to Varnish.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "varnish_cache",
                    "Enable Varnish Purge",
                    "Purge Varnish when content updates.",
                    $settings,
                );
                echo '<div style="margin-top:15px; padding-left:15px; border-left:2px solid var(--wpsc-border);">';
                $this->renderer->renderInput(
                    "varnish_host",
                    "Varnish Host",
                    "127.0.0.1",
                    $settings,
                );
                $this->renderer->renderInput(
                    "varnish_port",
                    "Varnish Port",
                    "6081",
                    $settings,
                    "number",
                );
                echo "</div>";
            },
        );

        $this->renderer->renderCard(
            "Smart Navigation",
            "Preload pages before click.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "speculative_loading",
                    "Enable Instant Click",
                    "Automatically uses Prerender (Modern) or Prefetch (Legacy).",
                    $settings,
                );
            },
        );

        $this->formEnd();
    }

    public function renderOptimizationTab(): void
    {
        $settings = $this->getSettings();
        $this->formStart();

        $this->renderer->renderCard(
            "CSS Optimization",
            "Minify and clean up styles.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "css_minify",
                    "Minify CSS",
                    "Remove whitespace.",
                    $settings,
                );
                $this->renderer->renderTextarea(
                    "excluded_css_minify",
                    "Exclude from Minification",
                    "Filenames to skip.",
                    $settings,
                );

                echo '<hr style="margin:20px 0; border:0; border-top:1px solid var(--wpsc-border);">';

                $this->renderer->renderToggle(
                    "remove_unused_css",
                    "Remove Unused CSS",
                    "Experimental tree-shaking.",
                    $settings,
                );
                $this->renderer->renderTextarea(
                    "css_safelist",
                    "CSS Safelist",
                    "Selectors to always keep (e.g. .active).",
                    $settings,
                );
            },
        );

        $this->renderer->renderCard(
            "JavaScript Optimization",
            "Manage script execution.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "js_minify",
                    "Minify JS",
                    "Compress files.",
                    $settings,
                );
                $this->renderer->renderTextarea(
                    "excluded_js_minify",
                    "Exclude from Minification",
                    "Filenames to skip.",
                    $settings,
                );

                echo '<hr style="margin:20px 0; border:0; border-top:1px solid var(--wpsc-border);">';

                $this->renderer->renderToggle(
                    "js_defer",
                    "Defer Execution",
                    "Move to footer.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "js_delay",
                    "Delay Execution",
                    "Wait for interaction.",
                    $settings,
                );
                $this->renderer->renderTextarea(
                    "excluded_js_execution",
                    "Exclude from Defer/Delay",
                    "Scripts that must run immediately.",
                    $settings,
                );
            },
        );

        $this->renderer->renderCard(
            "Font Optimization",
            "Google Fonts handling.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "font_localize_google",
                    "Localize Google Fonts",
                    "Serve locally.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "font_display_swap",
                    "Force Display Swap",
                    "Ensure text visibility.",
                    $settings,
                );
            },
        );

        $this->formEnd();
    }

    public function renderMediaTab(): void
    {
        $this->renderMediaTabContent($this->getSettings());
    }
    public function renderCdnTab(): void
    {
        $this->renderCdnTabContent($this->getSettings());
    }
    public function renderDatabaseTab(): void
    {
        $this->renderDatabaseTabContent($this->getSettings());
    }
    public function renderTweaksTab(): void
    {
        $this->renderTweaksTabContent($this->getSettings());
    }

    private function renderMediaTabContent(array $settings): void
    {
        $this->formStart();
        $this->renderer->renderCard(
            "Lazy Loading",
            "Load media only when visible.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "media_lazy_load",
                    "Lazy Load Images",
                    "Native lazy loading.",
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
                    "LCP Exclusion",
                    "Skip first X images (Recommended: 3).",
                    $settings,
                    "number",
                );
            },
        );
        $this->renderer->renderCard(
            "Optimization",
            "Layout shifts & Facades.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "media_add_dimensions",
                    "Add Missing Dimensions",
                    "Fixes CLS.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "media_youtube_facade",
                    "YouTube Facade",
                    "Static thumbnail for videos.",
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
            "CDN",
            "Global content delivery.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "cdn_enable",
                    "Enable CDN Rewrite",
                    "Rewrite URLs.",
                    $settings,
                );
                $this->renderer->renderInput(
                    "cdn_url",
                    "CDN URL",
                    "https://cdn.example.com",
                    $settings,
                    "url",
                );
            },
        );
        $this->renderer->renderCard(
            "Cloudflare",
            "Edge Cache.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "cf_enable",
                    "Enable Cloudflare",
                    "Purge on update.",
                    $settings,
                );
                $this->renderer->renderInput(
                    "cf_api_token",
                    "API Token",
                    "Token",
                    $settings,
                    "password",
                );
                $this->renderer->renderInput(
                    "cf_zone_id",
                    "Zone ID",
                    "ID",
                    $settings,
                );
            },
        );
        $this->formEnd();
    }

    private function renderTweaksTabContent(array $settings): void
    {
        $this->formStart();
        $this->renderer->renderCard(
            "Cleanup",
            "Remove bloat.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "bloat_disable_emojis",
                    "Disable Emojis",
                    "",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_disable_embeds",
                    "Disable Embeds",
                    "",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_remove_jquery_migrate",
                    "Remove jQuery Migrate",
                    "",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_remove_dashicons",
                    "Remove Dashicons",
                    "Frontend only.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_remove_query_strings",
                    "Remove Query Strings",
                    "",
                    $settings,
                );
            },
        );
        $this->renderer->renderCard("Security", "Hardening.", function () use (
            $settings,
        ) {
            $this->renderer->renderToggle(
                "bloat_disable_xmlrpc",
                "Disable XML-RPC",
                "",
                $settings,
            );
            $this->renderer->renderToggle(
                "bloat_hide_wp_version",
                "Hide WP Version",
                "",
                $settings,
            );
            $this->renderer->renderToggle(
                "bloat_remove_wlw_rsd",
                "Remove WLW & RSD",
                "",
                $settings,
            );
            $this->renderer->renderToggle(
                "bloat_remove_shortlink",
                "Remove Shortlinks",
                "",
                $settings,
            );
            $this->renderer->renderToggle(
                "bloat_disable_self_pingbacks",
                "Disable Self Pingbacks",
                "",
                $settings,
            );
        });
        $this->renderer->renderCard(
            "Heartbeat",
            "Server load.",
            function () use ($settings) {
                $this->renderer->renderRadioGroup(
                    "heartbeat_frequency",
                    "Frequency",
                    "Interval in seconds.",
                    $settings,
                    [
                        "15" => "15s",
                        "30" => "30s",
                        "60" => "60s",
                        "120" => "120s",
                    ],
                );
                echo '<p class="wpsc-setting-label" style="margin-top:15px;">Disable Locations</p>';
                $this->renderer->renderToggle(
                    "heartbeat_disable_admin",
                    "Admin",
                    "",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "heartbeat_disable_dashboard",
                    "Dashboard",
                    "",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "heartbeat_disable_frontend",
                    "Frontend",
                    "",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "heartbeat_disable_editor",
                    "Post Editor",
                    "",
                    $settings,
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
            "Schedule.",
            function () use ($settings) {
                $this->renderer->renderRadioGroup(
                    "db_schedule",
                    "Frequency",
                    "",
                    $settings,
                    [
                        "disabled" => "Disabled",
                        "daily" => "Daily",
                        "weekly" => "Weekly",
                        "monthly" => "Monthly",
                    ],
                );
            },
        );

        $this->renderer->renderCard(
            "Cleanup Items",
            "Manual or Scheduled.",
            function () use ($settings, $stats, $items) {
                echo '<div style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">';
                echo '<button type="button" id="wpsc-db-toggle-all" class="wpsc-btn-secondary"><span class="dashicons dashicons-yes" style="vertical-align:middle;"></span> Select All</button>';
                echo '<button type="button" id="wpsc-db-optimize" class="button wpsc-btn-primary"><span class="dashicons dashicons-database" style="vertical-align:middle;"></span> Optimize Selected</button>';
                echo "</div>";
                echo '<div id="wpsc-db-status" style="margin-bottom:20px; text-align:right; font-weight:600;"></div>';

                foreach ($items as $key => $label) {

                    $count = $stats[$key] ?? 0;
                    $display =
                        $key === "optimize_tables"
                            ? "Overhead: {$count}"
                            : "Count: {$count}";
                    $checked = !empty($settings["db_clean_" . $key]);
                    $inputId = "wpsc_db_clean_" . $key; ?>
                <div class="wpsc-setting-row">
                    <div class="wpsc-setting-info">
                        <label class="wpsc-setting-label" for="<?php echo esc_attr(
                            $inputId,
                        ); ?>"><?php echo esc_html($label); ?></label>
                        <p class="wpsc-setting-desc" style="color:var(--wpsc-primary);"><?php echo esc_html(
                            $display,
                        ); ?></p>
                    </div>
                    <div class="wpsc-setting-control">
                        <input type="hidden" name="wpsc_settings[db_clean_<?php echo esc_attr(
                            $key,
                        ); ?>]" value="0">
                        <label class="wpsc-switch">
                            <input type="checkbox" role="switch" id="<?php echo esc_attr(
                                $inputId,
                            ); ?>" class="wpsc-db-checkbox" data-key="<?php echo esc_attr(
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
            const toggleBtn = document.getElementById('wpsc-db-toggle-all');
            const status = document.getElementById('wpsc-db-status');
            const checkboxes = document.querySelectorAll('.wpsc-db-checkbox');

            function updateButtonState() {
                if (btn && btn.dataset.originalText) return;
                const anyChecked = Array.from(checkboxes).some(cb => cb.checked);
                if(btn) btn.disabled = !anyChecked;
                if(toggleBtn) {
                    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                    toggleBtn.innerHTML = allChecked ? '<span class="dashicons dashicons-dismiss" style="vertical-align:middle;"></span> Deselect All' : '<span class="dashicons dashicons-yes" style="vertical-align:middle;"></span> Select All';
                    toggleBtn.dataset.state = allChecked ? 'deselect' : 'select';
                }
            }

            if(toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    const isSelect = toggleBtn.dataset.state !== 'deselect';
                    checkboxes.forEach(cb => {
                        cb.checked = isSelect;
                        cb.dispatchEvent(new Event('change'));
                    });
                    if (typeof announce === 'function') { announce(isSelect ? 'All items selected' : 'All items deselected'); }
                });
            }

            if(checkboxes) checkboxes.forEach(cb => cb.addEventListener('change', updateButtonState));
            if(btn) updateButtonState();

            if(btn) {
                btn.addEventListener('click', function() {
                    const items = [];
                    document.querySelectorAll('.wpsc-db-checkbox:checked').forEach(el => { items.push(el.dataset.key); });
                    if (items.length === 0) return;

                    if (!btn.dataset.originalText) { btn.dataset.originalText = btn.innerHTML; }
                    const originalText = btn.dataset.originalText;
                    btn.disabled = true;
                    btn.innerHTML = '<span class="dashicons dashicons-update wpsc-spin"></span> Optimizing...';

                    // Sentinel Fix: Correctly serialize array parameters for PHP handling.
                    // Previous 'URLSearchParams' usage sent 'items[]=a,b' (string) which caused silent failures.
                    // By appending individually, we send 'items[]=a&items[]=b' which PHP parses correctly as an array.
                    const params = new URLSearchParams({ action: 'wpsc_manual_db_cleanup', _ajax_nonce: wpsc_admin.nonce });
                    items.forEach(item => params.append('items[]', item));

                    fetch(wpsc_admin.ajax_url, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: params
                    }).then(res => res.json()).then(res => {
                        if(res.success) {
                            btn.innerHTML = '<span class="dashicons dashicons-yes"></span> Cleaned!';
                            status.style.color = 'var(--wpsc-success)';
                            status.textContent = res.data;
                            if (typeof announce === 'function') { announce(res.data); }
                            setTimeout(() => window.location.reload(), 1500);
                        } else {
                             throw new Error(res.data);
                        }
                    }).catch(err => {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        status.style.color = 'var(--wpsc-danger)';
                        const msg = 'Error: ' + (err.message || 'Unknown error');
                        status.textContent = msg;
                        if (typeof announce === 'function') { announce(msg); }
                    });
                });
            }
        });
        </script>
        <?php
    }
}
