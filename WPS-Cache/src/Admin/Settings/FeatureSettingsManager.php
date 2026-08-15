<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

use WPSCache\Config\Settings;
use WPSCache\Infrastructure\Server\ServerConfigGenerator;

/** Renders capability-focused screens added after the original settings UI. */
final class FeatureSettingsManager
{
    private SettingsRenderer $renderer;

    public function __construct(private readonly ServerConfigGenerator $serverConfig)
    {
        $this->renderer = new SettingsRenderer();
    }

    public function renderDeliveryTab(): void
    {
        $settings = $this->settings();
        $this->formStart();
        $this->renderer->renderCard('Cache identity', 'Control exactly which requests share a page-cache entry.', function () use ($settings): void {
            $this->renderer->renderRadioGroup('cache_device_mode', 'Device variants', 'Shared, mobile/desktop, or tablet/mobile/desktop cache keys.', $settings, ['shared' => 'Shared', 'mobile' => 'Mobile + desktop', 'tablet' => 'Tablet + mobile + desktop']);
            $this->renderer->renderRadioGroup('cache_query_mode', 'Query strings', 'Ignore all parameters, create canonical variants, or accept only an allowlist.', $settings, ['variants' => 'Canonical variants', 'allowlist' => 'Allowlist only', 'ignore' => 'Ignore all']);
            $this->renderer->renderTextarea('cache_ignored_query_params', 'Ignored tracking parameters', 'Wildcards supported. These never create extra cache files.', $settings, ['placeholder' => "utm_*\nfbclid\ngclid"]);
            $this->renderer->renderTextarea('cache_query_allowlist', 'Allowed query parameters', 'Used in allowlist mode.', $settings);
            $this->renderer->renderTextarea('cache_query_denylist', 'Denied query parameters', 'Any matching request bypasses cache.', $settings);
        }, 'dashicons-randomize');

        $this->renderer->renderCard('Request safety', 'Keep personalized and incompatible requests out of public cache.', function () use ($settings): void {
            $this->renderer->renderTextarea('cache_bypass_cookies', 'Custom bypass cookies', 'Cookie-name fragments, one per line.', $settings);
            $this->renderer->renderTextarea('cache_bypass_user_agents', 'User-agent exclusions', 'Literal user-agent fragments, one per line.', $settings);
            $this->renderer->renderTextarea('cache_logged_in_roles', 'Cache logged-in roles', 'Advanced: per-user variants require WPSC_PRIVATE_CACHE_DIR pointing outside the public web root.', $settings, ['placeholder' => "subscriber\ncustomer"]);
            $this->renderer->renderToggle('cache_feeds', 'Cache feeds', 'Allow eligible RSS/Atom HTML/XML responses through runtime caching.', $settings);
            $this->renderer->renderToggle('cache_search', 'Cache searches', 'Allow canonical search result cache variants.', $settings);
        }, 'dashicons-shield');

        $this->renderer->renderCard('Revalidation and preload', 'Prevent stampedes and warm the complete sitemap in resumable batches.', function () use ($settings): void {
            $this->renderer->renderInput('cache_stale_ttl', 'Serve stale window (seconds)', 'Other requests may receive stale content while one request rebuilds.', $settings, 'number', ['min' => 0, 'max' => 86400]);
            $this->renderer->renderInput('cache_regeneration_lock', 'Regeneration lock (seconds)', 'Automatic dead-lock expiry.', $settings, 'number', ['min' => 1, 'max' => 300]);
            $this->renderer->renderRadioGroup('preload_source', 'URL discovery', 'WordPress core sitemaps are followed recursively with a database fallback.', $settings, ['sitemap' => 'Sitemap', 'both' => 'Sitemap + database', 'wordpress' => 'Database only']);
            $this->renderer->renderInput('preload_batch_size', 'Scheduled batch size', 'Each cron worker processes this many URLs.', $settings, 'number', ['min' => 1, 'max' => 500]);
            $this->renderer->renderInput('preload_concurrency', 'Manual concurrency', 'Parallel browser-admin warmup requests.', $settings, 'number', ['min' => 1, 'max' => 10]);
        }, 'dashicons-update');

        $this->renderer->renderCard('REST API cache', 'Cache successful anonymous GET responses for public routes.', function () use ($settings): void {
            $this->renderer->renderToggle('rest_cache', 'Enable REST response cache', 'Mutation and authenticated requests always bypass.', $settings);
            $this->renderer->renderInput('rest_cache_ttl', 'REST TTL (seconds)', 'Independent of page-cache lifetime.', $settings, 'number', ['min' => 10, 'max' => 86400]);
            $this->renderer->renderTextarea('rest_cache_routes', 'Route allowlist', 'Route patterns such as /wp/v2/posts/*. Empty caches all public routes except users.', $settings);
            $this->renderer->renderToggle('fragment_hole_punch', 'Dynamic fragment hole punching', 'Signed no-store REST fragments update personalized regions inside otherwise static pages.', $settings);
        }, 'dashicons-rest-api');
        $this->formEnd();

        $this->renderer->renderCard('Targeted purge', 'Invalidate one URL locally and at configured reverse proxies/CDNs.', function (): void {
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wpsc-inline-tool">
                <?php wp_nonce_field('wpsc_purge_url'); ?>
                <input type="hidden" name="action" value="wpsc_purge_url">
                <label class="screen-reader-text" for="wpsc-purge-url">URL</label>
                <input class="wpsc-input" id="wpsc-purge-url" type="url" name="url" required value="<?php echo esc_attr(home_url('/')); ?>">
                <button class="wpsc-btn-primary" type="submit">Purge URL</button>
            </form>
            <?php
        }, 'dashicons-trash');

        $settingsObject = new Settings($settings);
        $this->renderer->renderCard('Server recipes', 'Copyable configuration is generated but never written to server-owned files.', function () use ($settingsObject): void {
            $this->codeBox('wpsc-nginx-config', 'Nginx FastCGI cache + static assets', $this->serverConfig->nginx($settingsObject));
            $this->codeBox('wpsc-apache-assets', 'Apache static-asset browser cache', $this->serverConfig->apacheStaticAssets());
        }, 'dashicons-editor-code');
    }

    public function renderExperienceTab(): void
    {
        $settings = $this->settings();
        $this->formStart();
        $this->renderer->renderCard('CSS delivery', 'Aggregation and async delivery are opt-in because theme compatibility varies.', function () use ($settings): void {
            $this->renderer->renderToggle('css_combine', 'Combine simple local CSS', 'Complex handles and inline data are kept separate.', $settings);
            $this->renderer->renderToggle('css_async', 'Load non-critical CSS asynchronously', 'Styles tagged data-wpsc-critical and media styles remain blocking.', $settings);
            $this->renderer->renderToggle('css_rendered_profiles', 'Collect rendered CSS profiles', 'A same-origin browser audit records selectors used by each URL and desktop/mobile/tablet viewport.', $settings);
            $this->renderer->renderToggle('css_critical_rendered', 'Inline rendered critical CSS', 'Uses the collected above-the-fold profile and keeps a browser-generated fallback path.', $settings);
            $this->renderer->renderToggle('css_linked_prune', 'Remove unused linked CSS', 'Replaces profiled same-origin stylesheets with a generated per-page used-CSS file. Requires rendered profiles.', $settings);
            $this->renderer->renderInput('css_profile_retention', 'Profile retention (days)', 'Expired profiles are recollected automatically.', $settings, 'number', ['min' => 1, 'max' => 365]);
        }, 'dashicons-art');
        $this->renderer->renderCard('JavaScript delivery', 'Tune parsing and interaction delay behavior.', function () use ($settings): void {
            $this->renderer->renderToggle('js_combine', 'Combine simple local JavaScript', 'Scripts with inline data or conditionals remain separate.', $settings);
            $this->renderer->renderToggle('js_defer_inline', 'Defer inline JavaScript', 'Eligible blocks run after DOMContentLoaded.', $settings);
            $this->renderer->renderRadioGroup('js_delay_strategy', 'Delay strategy', 'Interaction, browser idle time, or the custom wpsc:consent event.', $settings, ['interaction' => 'First interaction', 'idle' => 'Browser idle', 'consent' => 'Consent event']);
            $this->renderer->renderInput('js_delay_timeout', 'Interaction delay timeout (ms)', 'Fallback before delayed scripts execute.', $settings, 'number', ['min' => 0, 'max' => 30000]);
        }, 'dashicons-editor-code');
        $this->renderer->renderCard('HTML and rendering', 'Optimize markup and below-fold rendering without coupling to page caching.', function () use ($settings): void {
            $this->renderer->renderToggle('html_minify', 'Minify HTML', 'Preserves script, style, template, textarea, and pre blocks.', $settings);
            $this->renderer->renderTextarea('lazy_render_selectors', 'Lazy-render selectors', 'Simple class, ID, or element selectors receive content-visibility.', $settings, ['placeholder' => ".site-footer\n#related-posts"]);
            $this->renderer->renderTextarea('asset_unload_rules', 'Script Manager rules', 'handle|script/style|URL wildcard|role wildcard', $settings, ['placeholder' => "wc-cart-fragments|script|*|visitor\ncontact-form-7|style|/blog/*|*"]);
        }, 'dashicons-layout');
        $this->renderer->renderCard('Resource hints', 'Add explicit connection and preload hints.', function () use ($settings): void {
            $this->renderer->renderTextarea('dns_prefetch_urls', 'DNS prefetch origins', 'Absolute origins, one per line.', $settings);
            $this->renderer->renderTextarea('preconnect_urls', 'Preconnect origins', 'Absolute origins, one per line.', $settings);
            $this->renderer->renderTextarea('resource_preload_urls', 'Resource preloads', 'Images, CSS, JS, fonts, or fetch URLs.', $settings);
            $this->renderer->renderTextarea('font_preload_urls', 'Font preloads', 'Font URLs receive the correct as and crossorigin attributes.', $settings);
            $this->renderer->renderToggle('font_auto_preload_localized', 'Auto-preload localized fonts', 'Preloads up to four locally cached font files discovered in localized CSS.', $settings);
            $this->renderer->renderTextarea('self_host_asset_urls', 'Self-host external CSS/JS', 'Exact administrator-approved stylesheet or script URLs, cached locally for seven days.', $settings);
            $this->renderer->renderToggle('font_system_stack', 'System-font-first mode', 'Opt-in global system stack for fastest text rendering.', $settings);
            $this->renderer->renderInput('font_subset_text', 'Google Fonts subset text', 'Optional glyph set such as your brand alphabet; Google returns a smaller subsetted WOFF2 file.', $settings);
            $this->renderer->renderToggle('gravatar_local_cache', 'Cache Gravatars locally', 'Downloads trusted Gravatar responses for seven days.', $settings);
        }, 'dashicons-networking');
        $this->renderer->renderCard('Expanded media delivery', 'Responsive and below-fold delivery controls.', function () use ($settings): void {
            $this->renderer->renderToggle('media_responsive_images', 'Generate missing srcset/sizes', 'Uses WordPress attachment metadata when an image can be identified.', $settings);
            $this->renderer->renderToggle('media_lcp_preload', 'Preload leading/LCP image', 'Emits an image preload for the first eager candidate.', $settings);
            $this->renderer->renderToggle('media_lqip', 'Lightweight placeholder styling', 'Adds a neutral placeholder while lazy images load.', $settings);
            $this->renderer->renderToggle('media_lazy_backgrounds', 'Lazy-load CSS backgrounds', 'Inline background URLs load through IntersectionObserver.', $settings);
            $this->renderer->renderToggle('media_lazy_load_video', 'Lazy-load native video', 'Defers source loading until near the viewport.', $settings);
            $this->renderer->renderToggle('media_vimeo_facade', 'Vimeo click facade', 'Replaces embeds with a lightweight consent-friendly loader.', $settings);
            $this->renderer->renderToggle('media_maps_facade', 'Google Maps click facade', 'Replaces maps with a lightweight click-to-load surface.', $settings);
        }, 'dashicons-format-video');
        $this->formEnd();
    }

    public function renderImagesTab(): void
    {
        $settings = $this->settings();
        $capabilities = apply_filters('wpsc_image_optimizer_capabilities', []);
        $stats = get_option('wpsc_image_stats', []);
        $stats = is_array($stats) ? $stats : [];
        $this->renderer->renderCard('Local encoder status', 'Features activate automatically when the server supplies the needed encoder.', function () use ($capabilities, $stats): void {
            echo '<div class="wpsc-capability-grid">';
            foreach (['imagick' => 'Imagick', 'gd' => 'GD', 'webp' => 'WebP', 'avif' => 'AVIF', 'animated_gif' => 'Animated GIF', 'exif' => 'EXIF', 'lossless_jpeg' => 'jpegtran', 'pdf' => 'Ghostscript PDF'] as $key => $label) {
                $available = !empty($capabilities[$key]);
                echo '<div class="wpsc-capability"><strong>' . esc_html($label) . '</strong><span class="wpsc-status-pill ' . ($available ? 'success' : 'warning') . '">' . ($available ? 'Available' : 'Unavailable') . '</span></div>';
            }
            echo '</div><p>Processed: <strong>' . esc_html((string) ($stats['processed'] ?? 0)) . '</strong> · Saved: <strong>' . esc_html(size_format((int) ($stats['bytes_saved'] ?? 0))) . '</strong> · Variants: <strong>' . esc_html((string) ($stats['variants'] ?? 0)) . '</strong></p>';
        }, 'dashicons-admin-tools');
        $this->formStart();
        $this->renderer->renderCard('Automation and safety', 'Originals are preserved before destructive operations.', function () use ($settings): void {
            $this->renderer->renderToggle('image_optimize_upload', 'Optimize new uploads', 'Processes originals and selected thumbnails after WordPress creates metadata.', $settings);
            $this->renderer->renderToggle('image_backup_originals', 'Back up originals', 'Creates a PHP-guarded sidecar for exact restore without exposing original bytes.', $settings);
            $this->renderer->renderToggle('image_preserve_original_file', 'Variant-only mode', 'Leaves source files byte-for-byte unchanged and generates only WebP/AVIF/LQIP delivery variants.', $settings);
            $this->renderer->renderToggle('image_lossless', 'Lossless/high-fidelity mode', 'PNG remains lossless; exact progressive JPEG optimization requires the diagnosed jpegtran binary.', $settings);
            $this->renderer->renderInput('image_quality', 'Lossy quality', 'Smart mode lowers quality slightly for very large images.', $settings, 'number', ['min' => 1, 'max' => 100]);
            $this->renderer->renderInput('image_max_width', 'Maximum width', 'Zero disables resizing.', $settings, 'number', ['min' => 0, 'max' => 12000]);
            $this->renderer->renderInput('image_max_height', 'Maximum height', 'Zero disables resizing.', $settings, 'number', ['min' => 0, 'max' => 12000]);
            $this->renderer->renderToggle('image_preserve_exif', 'Preserve EXIF metadata', 'Disable to strip metadata and reduce file size.', $settings);
            $this->renderer->renderToggle('image_smart_crop', 'Focal smart crop', 'Crops to maximum dimensions; the wpsc_image_crop_focus filter can supply subject coordinates.', $settings);
        }, 'dashicons-shield-alt');
        $this->renderer->renderCard('Formats and scope', 'Create browser-native next-generation variants alongside originals.', function () use ($settings): void {
            $this->renderer->renderToggle('image_generate_webp', 'Generate and deliver WebP', 'Requires Imagick or GD WebP support.', $settings);
            $this->renderer->renderToggle('image_generate_avif', 'Generate and deliver AVIF', 'Requires Imagick or GD AVIF support.', $settings);
            $this->renderer->renderTextarea('image_optimize_sizes', 'Thumbnail size allowlist', 'WordPress image-size names. Empty processes every generated size.', $settings);
            $this->renderer->renderTextarea('image_exclusions', 'Image exclusions', 'Path fragments that must never be processed.', $settings);
            $this->renderer->renderTextarea('image_custom_folders', 'Custom folders', 'Absolute folders below wp-content; used by the public API/provider integrations.', $settings);
            $this->renderer->renderInput('image_watermark_id', 'Watermark attachment ID', 'Imagick only. Zero disables watermarking.', $settings, 'number', ['min' => 0]);
        }, 'dashicons-format-image');
        $this->renderer->renderCard('Accessible image text', 'Generate alt text only when the WordPress attachment field is empty.', function () use ($settings): void {
            $this->renderer->renderToggle('image_ai_alt_provider', 'Generate missing alt text', 'Runs only for attachments whose alt text is empty. Custom providers can use the wpsc_generate_image_alt_text filter.', $settings);
            $this->renderer->renderToggle('image_ai_alt_openai', 'Use built-in OpenAI vision provider', 'Explicit opt-in: supported image bytes are sent to the OpenAI Responses API and API usage may incur cost.', $settings);
            $this->renderer->renderInput('openai_api_key', 'OpenAI API key', 'Retained when left blank and omitted from exports. WPSC_OPENAI_API_KEY can supply it from wp-config.php.', $settings, 'password');
            $this->renderer->renderInput('openai_vision_model', 'OpenAI vision model', 'Default: gpt-5.6. Choose a model that accepts image input.', $settings);
        }, 'dashicons-universal-access-alt');
        $this->renderer->renderCard('Media offload', 'Upload attachment objects through a custom provider or the built-in S3-compatible adapter.', function () use ($settings): void {
            $this->renderer->renderToggle('media_offload_provider', 'Enable media-offload provider', 'Calls the documented wpsc_offload_media_file lifecycle and rewrites attachment/srcset URLs after verified HTTPS responses.', $settings);
            $this->renderer->renderToggle('media_offload_delete_local', 'Delete verified offloaded thumbnails', 'Never deletes originals; providers must explicitly return verified=true. Keep disabled until restore behavior is tested.', $settings);
            $this->renderer->renderToggle('media_offload_s3', 'Built-in S3-compatible adapter', 'Signs direct HTTPS PUT/DELETE requests with AWS Signature Version 4. Works with S3-compatible path-style endpoints.', $settings);
            $this->renderer->renderInput('media_offload_endpoint', 'S3 endpoint', 'https://s3.amazonaws.com', $settings, 'url');
            $this->renderer->renderInput('media_offload_region', 'S3 region', 'us-east-1', $settings);
            $this->renderer->renderInput('media_offload_bucket', 'S3 bucket', 'media-bucket', $settings);
            $this->renderer->renderInput('media_offload_access_key', 'S3 access key', 'Retained when left blank and omitted from exports.', $settings, 'password');
            $this->renderer->renderInput('media_offload_secret_key', 'S3 secret key', 'Retained when left blank and omitted from exports.', $settings, 'password');
            $this->renderer->renderInput('media_offload_public_url', 'Public media origin', 'Optional CDN/custom-domain base URL.', $settings, 'url');
        }, 'dashicons-cloud-upload');
        $this->renderer->renderCard('Adaptive delivery', 'Generate signed, cached, browser-selected responsive derivatives without exposing arbitrary filesystem paths.', function () use ($settings): void {
            $this->renderer->renderToggle('image_adaptive_delivery', 'Enable adaptive image service', 'Same-origin uploads receive width-descriptor srcsets; AVIF/WebP is negotiated when the encoder and browser support it.', $settings);
            $this->renderer->renderInput('image_adaptive_quality', 'Adaptive quality', 'Applied to cached on-demand derivatives.', $settings, 'number', ['min' => 1, 'max' => 100]);
            $this->renderer->renderInput('image_adaptive_max_width', 'Largest adaptive width', 'Never upscales beyond the source dimensions.', $settings, 'number', ['min' => 1, 'max' => 12000]);
            $this->renderer->renderToggle('image_background_optimization', 'Background-optimize existing media', 'WordPress cron processes the existing library in bounded resumable batches.', $settings);
            $this->renderer->renderInput('image_background_batch_size', 'Background batch size', 'Attachments processed per cron worker.', $settings, 'number', ['min' => 1, 'max' => 100]);
        }, 'dashicons-images-alt');
        $this->formEnd();
        $this->renderer->renderCard('Bulk media library', 'Processes media in resumable browser batches. Closing the tab safely pauses the run.', function (): void {
            ?>
            <div id="wpsc-image-progress" class="wpsc-progress-container" hidden><progress id="wpsc-image-bar" value="0" max="100"></progress><span id="wpsc-image-status" role="status">Ready</span></div>
            <button type="button" id="wpsc-start-image-bulk" class="wpsc-btn-primary"><span class="dashicons dashicons-images-alt2"></span> Optimize media library</button>
            <div class="wpsc-inline-tool" style="margin-top:16px"><label for="wpsc-restore-image-id">Restore attachment ID</label><input class="wpsc-input" id="wpsc-restore-image-id" type="number" min="1"><button type="button" id="wpsc-restore-image" class="wpsc-btn-secondary">Restore original</button></div>
            <?php
        }, 'dashicons-images-alt2');
    }

    public function renderMonitoringTab(): void
    {
        $settings = $this->settings();
        $rum = get_option('wpsc_rum_metrics', []);
        $uptime = get_option('wpsc_uptime_history', []);
        $this->formStart();
        $this->renderer->renderCard('Real-user metrics', 'Stores a 10% anonymous local sample; no full URLs, IPs, cookies, or user IDs are recorded.', function () use ($settings): void {
            $this->renderer->renderToggle('enable_metrics', 'Enable dashboard metrics', 'Collect local cache and environment statistics.', $settings);
            $this->renderer->renderToggle('rum_enable', 'Collect Core Web Vitals', 'LCP, CLS, INP, and TTFB are sent to this WordPress installation.', $settings);
            $this->renderer->renderInput('metrics_retention', 'Retention (days)', 'Old real-user samples are pruned.', $settings, 'number', ['min' => 1, 'max' => 365]);
        }, 'dashicons-chart-area');
        $this->renderer->renderCard('Availability', 'WordPress cron performs a local loopback health check.', function () use ($settings): void {
            $this->renderer->renderToggle('uptime_monitor', 'Enable uptime checks', 'Records HTTP status and response time locally.', $settings);
            $this->renderer->renderRadioGroup('uptime_interval', 'Check interval', 'Cron timing depends on site traffic.', $settings, ['hourly' => 'Hourly', 'daily' => 'Daily']);
            $this->renderer->renderInput('uptime_heartbeat_url', 'External dead-man heartbeat URL', 'Optional HTTPS endpoint. Missing pings let an external monitor detect a complete origin outage.', $settings, 'password');
        }, 'dashicons-heart');
        $this->renderer->renderCard('Lighthouse lab testing', 'Use the official PageSpeed Insights v5 API for browser-rendered Lighthouse data; without a key the tool retains its local HTTP timing fallback.', function () use ($settings): void {
            $this->renderer->renderInput('pagespeed_api_key', 'PageSpeed API key', 'Stored locally and omitted from exports.', $settings, 'password');
            $this->renderer->renderRadioGroup('pagespeed_strategy', 'Lab strategy', 'Choose the Lighthouse device profile.', $settings, ['mobile' => 'Mobile', 'desktop' => 'Desktop']);
        }, 'dashicons-performance');
        $this->formEnd();
        $this->renderer->renderCard('Current samples', 'Use the HTTP lab check for server timing; browser Core Web Vitals come from RUM.', function () use ($rum, $uptime): void {
            echo '<p>RUM samples: <strong>' . esc_html((string) (is_array($rum) ? count($rum) : 0)) . '</strong> · Uptime checks: <strong>' . esc_html((string) (is_array($uptime) ? count($uptime) : 0)) . '</strong></p>';
            echo '<button type="button" id="wpsc-run-lab" class="wpsc-btn-primary">Run lab check</button><pre id="wpsc-lab-result" class="wpsc-code-box" aria-live="polite"></pre>';
        }, 'dashicons-performance');
    }

    public function renderToolsTab(): void
    {
        $settings = $this->settings();
        $history = get_option('wpsc_settings_history', []);
        $this->formStart();
        $this->renderer->renderCard('Test mode', 'Preview risky optimizations before making them public.', function () use ($settings): void {
            $this->renderer->renderToggle('optimization_safe_mode', 'Enable optimization test mode', 'Risky HTML and asset transformations only run with a signed preview link.', $settings);
            $preview = wp_nonce_url(home_url('/'), 'wpsc_optimization_preview', 'wpsc_preview');
            echo '<p><a class="wpsc-btn-secondary" target="_blank" rel="noopener" href="' . esc_url($preview) . '">Open signed preview</a></p>';
        }, 'dashicons-visibility');
        $this->formEnd();
        $this->renderer->renderCard('Presets and rollback', 'Preset changes and normal saves retain the previous ten configurations.', function () use ($history): void {
            echo '<div class="wpsc-action-grid">';
            foreach (['safe', 'balanced', 'aggressive'] as $preset) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('wpsc_apply_preset');
                echo '<input type="hidden" name="action" value="wpsc_apply_preset"><input type="hidden" name="preset" value="' . esc_attr($preset) . '"><button class="wpsc-btn-secondary" type="submit">' . esc_html(ucfirst($preset)) . '</button></form>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('wpsc_rollback_settings');
            echo '<input type="hidden" name="action" value="wpsc_rollback_settings"><button class="wpsc-btn-secondary" type="submit" ' . (is_array($history) && $history !== [] ? '' : 'disabled') . '>Rollback latest (' . esc_html((string) (is_array($history) ? count($history) : 0)) . ')</button></form></div>';
        }, 'dashicons-backup');
        $this->renderer->renderCard('Import and export', 'Secrets are omitted from exports and retained when an import leaves them blank.', function (): void {
            ?>
            <div class="wpsc-action-grid">
                <a class="wpsc-btn-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpsc_export_settings'), 'wpsc_export_settings')); ?>">Export JSON</a>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wpsc_import_settings'); ?>
                    <input type="hidden" name="action" value="wpsc_import_settings"><input type="file" name="settings_file" accept="application/json,.json" required>
                    <button class="wpsc-btn-secondary" type="submit">Import JSON</button>
                </form>
            </div>
            <?php
        }, 'dashicons-migrate');
        $this->renderer->renderCard('Environment diagnostics', 'Conditional features report their real server support.', function (): void {
            $checks = [
                'Cache directory writable' => is_dir(WPSC_CACHE_DIR) && is_writable(WPSC_CACHE_DIR),
                'PHP Redis' => extension_loaded('redis'),
                'PHP Memcached' => extension_loaded('memcached'),
                'Imagick or GD' => extension_loaded('imagick') || extension_loaded('gd'),
                'Brotli encoder' => function_exists('brotli_compress'),
                'FontTools pyftsubset' => defined('WPSC_PYFTSUBSET_BINARY') && is_file((string) WPSC_PYFTSUBSET_BINARY),
                'WP-CLI loaded' => defined('WP_CLI') && WP_CLI,
                'Multisite' => is_multisite(),
            ];
            echo '<div class="wpsc-capability-grid">';
            foreach ($checks as $label => $ok) {
                echo '<div class="wpsc-capability"><strong>' . esc_html($label) . '</strong><span class="wpsc-status-pill ' . ($ok ? 'success' : 'warning') . '">' . ($ok ? 'Ready' : 'Not available') . '</span></div>';
            }
            echo '</div>';
        }, 'dashicons-info-outline');
        $this->renderer->renderCard('Developer interfaces', 'Stable entry points for themes, hosts, and optional providers.', function (): void {
            echo '<p><code>FragmentCache::remember()</code>, <code>FragmentCache::placeholder()</code>, REST cache controls, <code>wp wps-cache</code>, and documented <code>wpsc_*</code> actions/filters are available. Built-in external adapters remain explicitly disabled until an administrator configures and enables them.</p>';
        }, 'dashicons-editor-code');
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $current = get_option(Settings::OPTION, []);
        return array_replace(Settings::defaults(), is_array($current) ? $current : []);
    }

    private function formStart(): void
    {
        echo '<form action="options.php" method="post" class="wpsc-form">';
        settings_fields('wpsc_settings');
    }

    private function formEnd(): void
    {
        echo '<div class="wpsc-sticky-footer"><button type="submit" class="wpsc-btn-primary" data-loading-text="Saving Changes..."><span class="dashicons dashicons-saved"></span> Save Changes</button></div></form>';
    }

    private function codeBox(string $id, string $label, string $content): void
    {
        echo '<label class="wpsc-setting-label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label><div class="wpsc-code-wrap"><textarea readonly id="' . esc_attr($id) . '" class="wpsc-textarea wpsc-code-box" rows="12">' . esc_textarea($content) . '</textarea><button type="button" class="wpsc-btn-secondary wpsc-copy-trigger" data-copy-target="' . esc_attr($id) . '">Copy</button></div>';
    }
}
