<?php

declare(strict_types=1);

namespace WPSCache\Admin\Tools;

/**
 * Handles Maintenance Tools, Object Cache Installation, and Preloading.
 */
class ToolsManager
{
    private const OBJECT_CACHE_TEMPLATE = "object-cache.php";

    public function __construct()
    {
        // Preloader AJAX
        add_action("wp_ajax_wpsc_get_preload_urls", [$this, "handleGetUrls"]);
        add_action("wp_ajax_wpsc_process_preload_url", [
            $this,
            "handleProcessUrl",
        ]);

        // Object Cache Handling (POST Actions)
        add_action("admin_post_wpsc_install_object_cache", [
            $this,
            "handleInstallObjectCache",
        ]);
        add_action("admin_post_wpsc_remove_object_cache", [
            $this,
            "handleRemoveObjectCache",
        ]);
    }

    /**
     * Renders the Tools Tab.
     */
    public function render(): void
    {
        $object_cache_installed = file_exists(
            WP_CONTENT_DIR . "/object-cache.php",
        ); ?>
        <!-- Object Cache Management -->
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <h2>Object Cache Drop-in</h2>
            </div>
            <div class="wpsc-card-body">
                <p class="wpsc-setting-desc">
                    The Redis Object Cache requires a drop-in file (<code>object-cache.php</code>) in your
                    <code>wp-content</code> directory.
                </p>

                <div style="margin-top: 15px;">
                    <?php if ($object_cache_installed): ?>
                        <div class="wpsc-notice success" style="display:inline-flex; margin-bottom: 15px;">
                            <span class="dashicons dashicons-yes" aria-hidden="true" style="margin-right:8px;"></span> Installed & Active
                        </div>
                        <form method="post" class="wpsc-form" action="<?php echo esc_url(
                            admin_url("admin-post.php"),
                        ); ?>">
                            <?php wp_nonce_field("wpsc_remove_object_cache"); ?>
                            <input type="hidden" name="action" value="wpsc_remove_object_cache">
                            <button type="submit" class="button wpsc-btn-danger" data-loading-text="Uninstalling..."
                                onclick="return confirm('<?php echo esc_js(
                                    __(
                                        "Are you sure you want to uninstall the Object Cache Drop-in? This will disable object caching.",
                                        "wps-cache",
                                    ),
                                ); ?>');">
                                <span class="dashicons dashicons-trash" aria-hidden="true" style="vertical-align: middle;"></span> Uninstall Drop-in
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="wpsc-notice warning" style="display:inline-flex; margin-bottom: 15px;">
                            <span class="dashicons dashicons-warning" aria-hidden="true" style="margin-right:8px;"></span> Not Installed
                        </div>
                        <form method="post" class="wpsc-form" action="<?php echo esc_url(
                            admin_url("admin-post.php"),
                        ); ?>">
                            <?php wp_nonce_field(
                                "wpsc_install_object_cache",
                            ); ?>
                            <input type="hidden" name="action" value="wpsc_install_object_cache">
                            <button type="submit" class="button button-primary wpsc-btn-primary" data-loading-text="Installing...">
                                <span class="dashicons dashicons-download" aria-hidden="true" style="vertical-align: middle;"></span> Install Drop-in
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Preloader Tool -->
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <h2>Cache Preloader</h2>
            </div>
            <div class="wpsc-card-body">
                <p class="wpsc-setting-desc">
                    Generates cache files for your content.
                    <br><strong>Method:</strong> Client-side Queue (Prevents server timeouts).
                </p>

                <div id="wpsc-preload-progress"
                    style="display:none; margin: 20px 0; background: #f3f4f6; padding: 15px; border-radius: 6px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-weight: 600;">
                        <span id="wpsc-preload-status" role="status" aria-live="polite">Initializing...</span>
                        <span id="wpsc-preload-percent">0%</span>
                    </div>
                    <progress id="wpsc-preload-bar" aria-labelledby="wpsc-preload-status" value="0" max="100"
                        style="width: 100%; height: 10px;"></progress>
                </div>

                <button type="button" id="wpsc-start-preload" class="button button-primary wpsc-btn-primary"
                    aria-controls="wpsc-preload-progress">
                    <span class="dashicons dashicons-controls-play" aria-hidden="true" style="vertical-align: middle;"></span> Start Preloading
                </button>
            </div>
        </div>

        <!-- System Status -->
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <h2>System Status</h2>
            </div>
            <div class="wpsc-card-body">
                <textarea id="wpsc-system-report" readonly aria-label="System Status Report" class="wpsc-textarea" rows="8"
                    style="font-family: monospace; font-size: 11px; width:100%; margin-bottom: 10px;"><?php echo esc_textarea(
                        $this->getSystemReport(),
                    ); ?></textarea>
                <button type="button" class="button wpsc-btn-secondary wpsc-copy-trigger" data-copy-target="wpsc-system-report">
                    <span class="dashicons dashicons-clipboard" aria-hidden="true" style="vertical-align: middle;"></span> Copy Report
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Handles Object Cache Installation
     */
    public function handleInstallObjectCache(): void
    {
        check_admin_referer("wpsc_install_object_cache");
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }

        $source = WPSC_PLUGIN_DIR . "includes/" . self::OBJECT_CACHE_TEMPLATE;
        $destination = WP_CONTENT_DIR . "/object-cache.php";

        if (file_exists($destination)) {
            $this->redirectWithNotice("Drop-in already exists.", "error");
            return;
        }

        if (@copy($source, $destination)) {
            // Fix permissions if WP_Filesystem is needed
            @chmod($destination, 0644);
            $this->redirectWithNotice(
                "Object Cache Drop-in installed successfully.",
                "success",
            );
        } else {
            $this->redirectWithNotice(
                "Failed to copy object-cache.php. Check permissions.",
                "error",
            );
        }
    }

    /**
     * Handles Object Cache Removal
     */
    public function handleRemoveObjectCache(): void
    {
        check_admin_referer("wpsc_remove_object_cache");
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }

        $file = WP_CONTENT_DIR . "/object-cache.php";

        if (file_exists($file)) {
            @unlink($file);
            wp_cache_flush(); // Clear memory cache
            $this->redirectWithNotice(
                "Object Cache Drop-in removed.",
                "success",
            );
        } else {
            $this->redirectWithNotice("File does not exist.", "warning");
        }
    }

    private function redirectWithNotice(string $message, string $type): void
    {
        // Simple notice implementation relying on transient or query arg
        // Ideally use NoticeManager, but keeping dependencies light here.
        set_transient(
            "wpsc_admin_notices",
            [["message" => $message, "type" => $type]],
            60,
        );
        wp_redirect(remove_query_arg(["action", "_wpnonce"], wp_get_referer()));
        exit();
    }

    /**
     * AJAX Step 1: Return list of URLs to preload.
     */
    public function handleGetUrls(): void
    {
        check_ajax_referer("wpsc_ajax_nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error();
        }

        $post_types = ["page", "post"];
        if (class_exists("WooCommerce")) {
            $post_types[] = "product";
        }

        $query = new \WP_Query([
            "post_type" => $post_types,
            "post_status" => "publish",
            "posts_per_page" => 200,
            "fields" => "ids",
            "no_found_rows" => true,
            "update_post_meta_cache" => false,
            "update_post_term_cache" => false,
        ]);

        $urls = [];
        $urls[home_url("/")] = true;

        foreach ($query->posts as $id) {
            $link = get_permalink($id);
            if ($link) {
                $urls[$link] = true;
            }
        }

        wp_send_json_success(array_values(array_keys($urls)));
    }

    /**
     * AJAX Step 2: Process a single URL.
     */
    public function handleProcessUrl(): void
    {
        check_ajax_referer("wpsc_ajax_nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error();
        }

        $url = isset($_POST["url"]) ? esc_url_raw($_POST["url"]) : "";
        if (empty($url)) {
            wp_send_json_error("No URL provided");
        }

        if (!str_starts_with($url, home_url("/"))) {
            wp_send_json_error("External URLs are not allowed");
        }

        $response = wp_safe_remote_get($url, [
            "timeout" => 10,
            "blocking" => true,
            "cookies" => [],
            "headers" => [
                "User-Agent" => "WPS-Cache-Preloader/1.0; " . home_url(),
            ],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            wp_send_json_success("Cached ($code)");
        } else {
            wp_send_json_error("HTTP $code");
        }
    }

    private function getSystemReport(): string
    {
        global $wp_version;
        $report = "WP: $wp_version | PHP: " . PHP_VERSION . "\n";
        $report .= "Server: " . ($_SERVER["SERVER_SOFTWARE"] ?? "N/A") . "\n";
        $report .=
            "Cache Dir: " .
            (defined("WPSC_CACHE_DIR") ? WPSC_CACHE_DIR : "N/A") .
            "\n";
        return $report;
    }
}
