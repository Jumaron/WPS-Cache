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
                            <button type="submit" class="button wpsc-btn-danger wpsc-confirm-trigger" data-loading-text="Uninstalling..."
                                data-confirm="<?php echo esc_attr(
                                    __(
                                        "Are you sure you want to uninstall the Object Cache Drop-in? This will disable object caching.",
                                        "wps-cache",
                                    ),
                                ); ?>">
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
                    <br><strong>Scope:</strong> Preloads both Desktop and Mobile versions.
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
                    spellcheck="false" autocorrect="off" autocapitalize="none"
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
        set_transient(
            "wpsc_admin_notices",
            [["message" => $message, "type" => $type]],
            60,
        );
        wp_safe_redirect(
            remove_query_arg(["action", "_wpnonce"], wp_get_referer()),
        );
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
     * Updated to hit both Desktop and Mobile versions.
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

        // --- 1. Desktop Request ---
        $desktopArgs = [
            "timeout" => 10,
            "blocking" => true,
            "cookies" => [],
            "headers" => [
                "User-Agent" => "WPS-Cache-Preloader/1.0; " . home_url(),
            ],
            // Optional: Disable SSL verify for local envs if needed, usually better to keep on
            "sslverify" => apply_filters("https_local_ssl_verify", true),
        ];

        $resDesktop = wp_safe_remote_get($url, $desktopArgs);
        $codeDesktop = is_wp_error($resDesktop)
            ? 0
            : wp_remote_retrieve_response_code($resDesktop);

        // --- 2. Mobile Request ---
        // Using a generic iPhone UA to match the 'Mobile' regex in HTMLCache.php
        $mobileUA =
            "Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1";

        $mobileArgs = [
            "timeout" => 10,
            "blocking" => true,
            "cookies" => [],
            "headers" => [
                "User-Agent" => $mobileUA,
            ],
            "sslverify" => apply_filters("https_local_ssl_verify", true),
        ];

        $resMobile = wp_safe_remote_get($url, $mobileArgs);
        $codeMobile = is_wp_error($resMobile)
            ? 0
            : wp_remote_retrieve_response_code($resMobile);

        // --- Response Handling ---

        $errors = [];
        if (is_wp_error($resDesktop)) {
            $errors[] = "Desk: " . $resDesktop->get_error_message();
        }
        if (is_wp_error($resMobile)) {
            $errors[] = "Mob: " . $resMobile->get_error_message();
        }

        if (!empty($errors) && $codeDesktop === 0 && $codeMobile === 0) {
            // Both failed completely
            wp_send_json_error(implode(", ", $errors));
        }

        // Success if at least one worked or returned a valid HTTP code
        $statusMsg = "Cached (D:$codeDesktop, M:$codeMobile)";

        // If 200 OK
        if (
            ($codeDesktop >= 200 && $codeDesktop < 300) ||
            ($codeMobile >= 200 && $codeMobile < 300)
        ) {
            wp_send_json_success($statusMsg);
        } else {
            // e.g. 404s or 500s
            wp_send_json_error("HTTP Error " . $statusMsg);
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
