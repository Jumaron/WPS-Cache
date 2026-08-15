<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Cache\CacheManager;
use WPSCache\Config\Settings;
use WPSCache\Maintenance\DatabaseOptimizer;
use WPSCache\Admin\Analytics\MetricsCollector;

final class SettingsManager
{
    private CacheManager $cacheManager;
    private SettingsRenderer $renderer;
    private DatabaseOptimizer $databaseOptimizer;

    public function __construct(CacheManager $cacheManager, DatabaseOptimizer $databaseOptimizer)
    {
        $this->cacheManager = $cacheManager;
        $this->databaseOptimizer = $databaseOptimizer;
        $this->renderer = new SettingsRenderer();
    }

    private function getSettings(): array
    {
        $defaults = Settings::defaults();
        $current = get_option("wpsc_settings", []);
        return is_array($current)
            ? array_merge($defaults, $current)
            : $defaults;
    }

    private function formStart(): void
    {
        echo '<form action="options.php" method="post" class="wpsc-form">';
        settings_fields("wpsc_settings");
    }

    private function formEnd(): void
    {
        echo '<div class="wpsc-sticky-footer">';
        echo '<button type="submit" name="submit" id="submit" class="wpsc-btn-primary" data-loading-text="Saving Changes...">';
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
        $collector = new MetricsCollector($this->cacheManager, new Settings($settings));
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
                            <div class="wpsc-stat-header"><span class="dashicons dashicons-database"></span> <?php echo esc_html((string) ($redis['backend'] ?? 'Object')); ?> Object Cache
                            </div>
                            <?php if (!empty($redis["enabled"])): ?>
                                <?php if (!empty($redis["connected"])): ?>
                                    <div class="wpsc-stat-big-number">
                                        <?php echo esc_html(
                                            $redis["hit_ratio"],
                                        ); ?>%
                                    </div>
                                    <div style="color: var(--wpsc-text-muted);">Hit Ratio</div>
                                <?php else: ?>
                                    <div class="wpsc-status-pill error" title="<?php echo esc_attr($redis['error'] ?? 'Connection Failed'); ?>">Connection Failed</div>
                                    <?php if (!empty($redis['error'])): ?>
                                        <div style="color: var(--wpsc-text-muted); font-size: 0.8rem; margin-top: 5px; line-height: 1.2;">
                                            <?php echo esc_html($redis['error']); ?>
                                        </div>
                                    <?php endif; ?>
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
                                <div class="wpsc-stat-big-number">
                                    <?php echo esc_html($html["files"]); ?>
                                </div>
                                <div style="color: var(--wpsc-text-muted);">Cached Pages</div>
                                <?php if (!empty($html['traffic'])): ?>
                                    <div style="margin-top:8px;color:var(--wpsc-text-muted);font-size:.85rem"><?php echo esc_html((string) $html['traffic']['hit_ratio']); ?>% hit ratio · <?php echo esc_html((string) $html['traffic']['hits']); ?> hits</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="wpsc-status-pill warning">Disabled</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="wpsc-stat-card">
                        <div class="wpsc-stat-header"><span class="dashicons dashicons-desktop"></span> Server</div>
                        <div style="font-size: 0.9rem; display:flex; flex-direction:column; gap:5px;">
                            <div>PHP: <strong>
                                    <?php echo esc_html(
                                        $stats["system"]["php_version"],
                                    ); ?>
                                </strong></div>
                            <div>Server: <strong>
                                    <?php echo esc_html(
                                        $stats["system"]["server"],
                                    ); ?>
                                </strong></div>
                        </div>
                    </div>
                </div>
                <div style="margin-top: 2rem; display:flex; justify-content:flex-end;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wpsc-form">
                        <?php wp_nonce_field("wpsc_refresh_stats"); ?>
                        <input type="hidden" name="action" value="wpsc_refresh_stats">
                        <button type="submit" class="wpsc-btn-secondary" data-loading-text="Refreshing...">
                            <span class="dashicons dashicons-update"></span> Refresh Data
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <?php
        // Preloader & Status
        $this->formStart();

        $this->renderer->renderCard(
            "Cache Preloader",
            "Automatically generate cache files.",
            function () {
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
            "dashicons-update",
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
                        ); ?>" class="wpsc-btn-ghost-danger wpsc-confirm-trigger"
                            data-confirm="Disable Object Cache?">Uninstall</a>
                    <?php else: ?>
                        <a href="<?php echo esc_url(
                            wp_nonce_url(
                                admin_url(
                                    "admin-post.php?action=wpsc_install_object_cache",
                                ),
                                "wpsc_install_object_cache",
                            ),
                        ); ?>" class="wpsc-btn-primary">Install Drop-in</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            },
            "dashicons-database-view",
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
                    "Enable page cache",
                    "Serve prebuilt HTML to eligible visitors for dramatically faster responses.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['html_cache' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderInput(
                        "cache_lifetime",
                        "Cache lifespan",
                        "Seconds before a cached page expires. 3,600 seconds is a solid default.",
                        $settings,
                        "number",
                        ["min" => "60", "max" => "31536000"],
                    );
                    $this->renderer->renderRadioGroup(
                        "preload_interval",
                        "Automatic preload",
                        "Choose how often WPS Cache should rebuild the warm cache.",
                        $settings,
                        [
                            "hourly" => "Hourly",
                            "daily" => "Daily",
                            "weekly" => "Weekly",
                            "disabled" => "Off",
                        ],
                    );
                    $this->renderer->renderTextarea(
                        "excluded_urls",
                        "Never cache these URLs",
                        "Add one path per line. Useful for accounts, carts, and personalized pages.",
                        $settings,
                        ["placeholder" => "/my-account/\n/contact/"],
                    );
                }, 'all', 'Page cache settings');
            },
            "dashicons-html",
        );

        $this->renderer->renderCard(
            "Object Cache (Redis)",
            "Cache database queries and dynamic data.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "redis_cache",
                    "Enable Redis object cache",
                    "Store repeated database results in a reachable Redis service.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['redis_cache' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderInput("redis_host", "Host", "Usually 127.0.0.1 or the private service hostname.", $settings);
                    $this->renderer->renderInput("redis_port", "Port", "Redis commonly listens on 6379.", $settings, "number", ["min" => "1", "max" => "65535"]);
                    $this->renderer->renderInput("redis_db", "Database", "Logical Redis database from 0 to 15.", $settings, "number", ["min" => "0", "max" => "15"]);
                    $this->renderer->renderInput("redis_password", "Password", "Optional. Leave blank to keep the saved password.", $settings, "password");
                    $this->renderer->renderInput("redis_prefix", "Key prefix", "Separates this site's keys from other applications.", $settings);
                    $this->renderer->renderToggle("redis_tls", "Use TLS", "Encrypt the connection with the tls:// transport.", $settings);
                }, 'all', 'Redis connection settings');
            },
            "dashicons-database",
        );

        $this->renderer->renderCard(
            "Object Cache (Memcached)",
            "Selectable persistent object caching with namespace-safe invalidation. Enabling it disables Redis because WordPress supports one object-cache drop-in.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "memcached_cache",
                    "Enable Memcached object cache",
                    "Requires the PHP Memcached extension and a reachable daemon.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['memcached_cache' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderInput("memcached_host", "Host", "Usually 127.0.0.1 or the private service hostname.", $settings);
                    $this->renderer->renderInput("memcached_port", "Port", "Memcached commonly listens on 11211.", $settings, "number", ["min" => "1", "max" => "65535"]);
                    $this->renderer->renderInput("memcached_prefix", "Key prefix", "Separates this site's keys from other applications.", $settings);
                    $this->renderer->renderInput("memcached_persistent_id", "Persistent connection ID", "Reuses the PHP connection between requests.", $settings);
                }, 'all', 'Memcached connection settings');
            },
            "dashicons-database-view",
        );

        $this->renderer->renderCard(
            "Server Integration (Nginx FastCGI)",
            "Purge a host-managed FastCGI cache. Copy the generated server recipe from Delivery Rules.",
            function () use ($settings) {
                $this->renderer->renderToggle("nginx_cache", "Enable Nginx purge", "Sends non-blocking PURGE requests to the configured local endpoint.", $settings);
                $this->renderer->renderConditionalGroup(['nginx_cache' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderInput("nginx_host", "Nginx host", "Local address that accepts purge requests.", $settings);
                    $this->renderer->renderInput("nginx_port", "Nginx port", "The internal listener port.", $settings, "number", ["min" => "1", "max" => "65535"]);
                    $this->renderer->renderInput("nginx_purge_path", "Purge endpoint", "Path configured by the Nginx cache recipe.", $settings);
                }, 'all', 'Nginx purge settings');
            },
            "dashicons-admin-site-alt3",
        );

        $this->renderer->renderCard(
            "Server Integration (Varnish)",
            "Send Purge requests to Varnish.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "varnish_cache",
                    "Enable Varnish purge",
                    "Clear Varnish automatically when WordPress content changes.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['varnish_cache' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderInput("varnish_host", "Varnish host", "Local address that accepts purge requests.", $settings);
                    $this->renderer->renderInput("varnish_port", "Varnish port", "Varnish commonly listens on 6081.", $settings, "number", ["min" => "1", "max" => "65535"]);
                }, 'all', 'Varnish connection settings');
            },
            "dashicons-cloud",
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
            "dashicons-controls-forward",
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
                    "Minify CSS files",
                    "Remove comments and unnecessary whitespace from local stylesheets.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['css_minify' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderTextarea(
                        "excluded_css_minify",
                        "CSS file exclusions",
                        "Handles, filenames, or URL fragments to leave untouched, one per line.",
                        $settings,
                        ["placeholder" => "theme-style\nlegacy.css"],
                    );
                }, 'all', 'CSS minification exclusions');

                $this->renderer->renderToggle(
                    "remove_unused_css",
                    "Prune unused inline CSS",
                    "Experimental selector pruning for inline style blocks; this is separate from rendered critical CSS.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['remove_unused_css' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderTextarea(
                        "css_safelist",
                        "Always keep these selectors",
                        "Protect dynamic classes and IDs that are added after page load.",
                        $settings,
                        ["placeholder" => ".is-active\n#mobile-menu"],
                    );
                }, 'all', 'Unused CSS safelist');
            },
            "dashicons-art",
        );

        $this->renderer->renderCard(
            "JavaScript Optimization",
            "Manage script execution.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "js_minify",
                    "Minify JavaScript files",
                    "Remove comments and unnecessary whitespace from local scripts.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['js_minify' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderTextarea(
                        "excluded_js_minify",
                        "JavaScript file exclusions",
                        "Handles, filenames, or URL fragments to leave untouched, one per line.",
                        $settings,
                        ["placeholder" => "legacy-script\ncheckout.js"],
                    );
                }, 'all', 'JavaScript minification exclusions');

                $this->renderer->renderToggle(
                    "js_defer",
                    "Defer JavaScript",
                    "Let HTML parsing finish before eligible scripts execute.",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "js_delay",
                    "Delay JavaScript",
                    "Wait for visitor interaction before running eligible scripts.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['js_defer' => ['1'], 'js_delay' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderTextarea(
                        "excluded_js_execution",
                        "Execution exclusions",
                        "Scripts that must run immediately, one handle or filename per line.",
                        $settings,
                        ["placeholder" => "jquery.js\ncheckout.js"],
                    );
                }, 'any', 'JavaScript execution exclusions');
            },
            "dashicons-editor-code",
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
            "dashicons-editor-textcolor",
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
                    "Lazy-load iframes",
                    "Use native browser lazy loading for embeds.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['media_lazy_load' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderInput(
                        "media_lazy_load_exclude_count",
                        "Leading image exclusions",
                        "Keep this many early images eager to protect Largest Contentful Paint. Recommended: 3.",
                        $settings,
                        "number",
                        ["min" => "0"],
                    );
                }, 'all', 'Image lazy-loading settings');
            },
            "dashicons-images-alt2",
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
            "dashicons-video-alt3",
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
                    "Enable CDN rewriting",
                    "Serve eligible assets from your CDN origin.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['cdn_enable' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderInput(
                        "cdn_url",
                        "Default CDN origin",
                        "Used for every supported asset type unless overridden below.",
                        $settings,
                        "url",
                        ["placeholder" => "https://cdn.example.com"],
                    );
                    $this->renderer->renderInput("cdn_css_url", "CSS origin", "Optional origin used only for stylesheets.", $settings, "url", ["placeholder" => "https://css.example.com"]);
                    $this->renderer->renderInput("cdn_js_url", "JavaScript origin", "Optional origin used only for scripts.", $settings, "url", ["placeholder" => "https://js.example.com"]);
                    $this->renderer->renderInput("cdn_media_url", "Media origin", "Optional origin used for images, fonts, and media.", $settings, "url", ["placeholder" => "https://media.example.com"]);
                }, 'all', 'CDN origin settings');
            },
            "dashicons-earth",
        );
        $this->renderer->renderCard(
            "Cloudflare",
            "Edge Cache.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "cf_enable",
                    "Connect Cloudflare",
                    "Purge the configured zone when WordPress content changes.",
                    $settings,
                );
                $this->renderer->renderConditionalGroup(['cf_enable' => ['1']], $settings, function () use ($settings): void {
                    $this->renderer->renderInput("cf_api_token", "API token", "Use a scoped token with cache-purge permissions. Leave blank to keep it.", $settings, "password");
                    $this->renderer->renderInput("cf_zone_id", "Zone ID", "The 32-character identifier shown in the Cloudflare dashboard.", $settings);
                    $this->renderer->renderToggle("cf_edge_cache", "Manage full-page edge caching", "Synchronize an anonymous HTML cache rule through the Cloudflare API.", $settings);
                }, 'all', 'Cloudflare connection settings');
            },
            "dashicons-cloud-saved",
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
            "dashicons-trash",
        );
        $this->renderer->renderCard(
            "Security",
            "Hardening.",
            function () use ($settings) {
                $this->renderer->renderToggle(
                    "bloat_disable_xmlrpc",
                    "Disable XML-RPC",
                    "",
                    $settings,
                );
                $this->renderer->renderToggle(
                    "bloat_disable_user_enumeration",
                    "Disable User Enumeration",
                    "Block author scans and user REST endpoints.",
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
                $this->renderer->renderToggle("bloat_disable_rss", "Disable RSS feeds", "Remove discovery links and feed endpoints.", $settings);
            },
            "dashicons-shield",
        );
        $this->renderer->renderCard(
            "WooCommerce assets",
            "Reduce storefront scripts on pages that do not need them.",
            function () use ($settings) {
                $this->renderer->renderToggle("woo_disable_cart_fragments", "Disable cart fragments", "Removes the AJAX cart-fragments script.", $settings);
                $this->renderer->renderToggle("woo_unload_assets", "Unload WooCommerce assets elsewhere", "Dequeues common WooCommerce CSS/JS outside shop, cart, checkout, and account pages.", $settings);
            },
            "dashicons-cart",
        );
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
            "dashicons-heart",
        );
        $this->formEnd();
    }

    private function renderDatabaseTabContent(array $settings): void
    {
        $stats = $this->databaseOptimizer->getStats();
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
            "dashicons-calendar",
        );

        $this->renderer->renderCard(
            "Cleanup Items",
            "Manual or Scheduled.",
            function () use ($settings, $stats, $items) {
                echo '<div style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">';
                echo '<button type="button" id="wpsc-db-toggle-all" class="wpsc-btn-secondary"><span class="dashicons dashicons-yes" style="vertical-align:middle;"></span> Select All</button>';
                echo '<button type="button" id="wpsc-db-optimize" class="wpsc-btn-primary" data-loading-text="Optimizing..."><span class="dashicons dashicons-database" style="vertical-align:middle;"></span> Optimize Selected</button>';
                echo "</div>";
                echo '<div id="wpsc-db-status" style="margin-bottom:20px; text-align:right; font-weight:600;"></div>';

                foreach ($items as $key => $label) {

                    $count = $stats[$key] ?? 0;
                    $display =
                        $key === "optimize_tables"
                            ? "Overhead: {$count}"
                            : "Count: {$count}";
                    $checked = !empty($settings["db_clean_" . $key]);
                    $inputId = "wpsc_db_clean_" . $key;
                    ?>
                <div class="wpsc-setting-row">
                    <div class="wpsc-setting-info">
                        <label class="wpsc-setting-label" for="<?php echo esc_attr(
                            $inputId,
                        ); ?>">
                            <?php echo esc_html($label); ?>
                        </label>
                        <p class="wpsc-setting-desc" style="color:var(--wpsc-primary);">
                            <?php echo esc_html($display); ?>
                        </p>
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
); ?>]" value="1" aria-checked="<?php echo $checked
    ? "true"
    : "false"; ?>" <?php checked($checked); ?>>
                            <span class="wpsc-slider"></span>
                        </label>
                    </div>
                </div>
                <?php
                }
            },
            "dashicons-list-view",
        );
        $this->formEnd();?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const btn = document.getElementById('wpsc-db-optimize');
                const toggleBtn = document.getElementById('wpsc-db-toggle-all');
                const status = document.getElementById('wpsc-db-status');
                const checkboxes = document.querySelectorAll('.wpsc-db-checkbox');

                function updateButtonState() {
                    if (btn && btn.dataset.originalText) return;
                    const anyChecked = Array.from(checkboxes).some(cb => cb.checked);
                    if (btn) btn.disabled = !anyChecked;
                    if (toggleBtn) {
                        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                        toggleBtn.innerHTML = allChecked ? '<span class="dashicons dashicons-dismiss" style="vertical-align:middle;"></span> Deselect All' : '<span class="dashicons dashicons-yes" style="vertical-align:middle;"></span> Select All';
                        toggleBtn.dataset.state = allChecked ? 'deselect' : 'select';
                    }
                }

                if (toggleBtn) {
                    toggleBtn.addEventListener('click', function () {
                        const isSelect = toggleBtn.dataset.state !== 'deselect';
                        checkboxes.forEach(cb => {
                            cb.checked = isSelect;
                            cb.dispatchEvent(new Event('change'));
                        });
                        if (typeof announce === 'function') { announce(isSelect ? 'All items selected' : 'All items deselected'); }
                    });
                }

                if (checkboxes) checkboxes.forEach(cb => cb.addEventListener('change', updateButtonState));
                if (btn) updateButtonState();

                if (btn) {
                    btn.addEventListener('click', function () {
                        const items = [];
                        document.querySelectorAll('.wpsc-db-checkbox:checked').forEach(el => { items.push(el.dataset.key); });
                        if (items.length === 0) return;

                        if (!btn.dataset.originalText) { btn.dataset.originalText = btn.innerHTML; }
                        const originalText = btn.dataset.originalText;
                        btn.disabled = true;
                        // ADDED wpsc-spin CLASS HERE
                        btn.innerHTML = '<span class="dashicons dashicons-update wpsc-spin"></span> Optimizing...';

                        const params = new URLSearchParams({ action: 'wpsc_manual_db_cleanup', _ajax_nonce: wpsc_admin.nonce });
                        items.forEach(item => params.append('items[]', item));

                        fetch(wpsc_admin.ajax_url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: params
                        }).then(res => res.json()).then(res => {
                            if (res.success) {
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
