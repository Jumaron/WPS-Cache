<?php

declare(strict_types=1);

namespace WPSCache\Admin\Tools;

class ToolsManager
{
    private const OBJECT_CACHE_TEMPLATE = "object-cache.php";

    public function __construct()
    {
        // AJAX Hooks
        add_action("wp_ajax_wpsc_get_preload_urls", [$this, "handleGetUrls"]);
        add_action("wp_ajax_wpsc_process_preload_url", [
            $this,
            "handleProcessUrl",
        ]);
        // POST Actions
        add_action("admin_post_wpsc_install_object_cache", [
            $this,
            "handleInstallObjectCache",
        ]);
        add_action("admin_post_wpsc_remove_object_cache", [
            $this,
            "handleRemoveObjectCache",
        ]);
    }

    public function render(): void
    {
        $object_cache_installed = file_exists(
            WP_CONTENT_DIR . "/object-cache.php",
        ); ?>

        <!-- SECTION 1: Object Cache Drop-in -->
        <section class="wpsc-section">
            <div class="wpsc-section-header">
                <h3 class="wpsc-section-title">Object Cache Drop-in</h3>
                <p class="wpsc-section-desc">
                    The Redis Object Cache requires a drop-in file (<code>object-cache.php</code>) in your
                    <code>wp-content</code> directory to function.
                </p>
            </div>

            <div class="wpsc-section-body">
                <div class="wpsc-tool-status-box">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <strong>Status:</strong>
                        <?php if ($object_cache_installed): ?>
                            <span class="wpsc-status-pill success">
                                <span class="dashicons dashicons-yes" style="font-size:16px; width:16px; height:16px;"></span> Installed & Active
                            </span>
                        <?php else: ?>
                            <span class="wpsc-status-pill warning">
                                <span class="dashicons dashicons-warning" style="font-size:16px; width:16px; height:16px;"></span> Not Installed
                            </span>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if ($object_cache_installed): ?>
                            <form method="post" action="<?php echo esc_url(
                                admin_url("admin-post.php"),
                            ); ?>">
                                <?php wp_nonce_field(
                                    "wpsc_remove_object_cache",
                                ); ?>
                                <input type="hidden" name="action" value="wpsc_remove_object_cache">
                                <button type="submit" class="wpsc-btn-ghost-danger wpsc-confirm-trigger"
                                        data-confirm="Are you sure? This will disable object caching.">
                                    Uninstall Drop-in
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?php echo esc_url(
                                admin_url("admin-post.php"),
                            ); ?>">
                                <?php wp_nonce_field(
                                    "wpsc_install_object_cache",
                                ); ?>
                                <input type="hidden" name="action" value="wpsc_install_object_cache">
                                <button type="submit" class="wpsc-btn-primary">
                                    Install Drop-in
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- SECTION 2: Cache Preloader -->
        <section class="wpsc-section">
            <div class="wpsc-section-header">
                <h3 class="wpsc-section-title">Cache Preloader</h3>
                <p class="wpsc-section-desc">
                    Automatically generates cache files for your content. Uses a client-side queue to prevent server timeouts.
                </p>
            </div>

            <div class="wpsc-section-body">
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
            </div>
        </section>

        <!-- SECTION 3: System Status -->
        <section class="wpsc-section">
            <div class="wpsc-section-header">
                <h3 class="wpsc-section-title">System Status</h3>
                <p class="wpsc-section-desc">Use this report when requesting support.</p>
            </div>

            <div class="wpsc-section-body">
                <textarea id="wpsc-system-report" readonly class="wpsc-code-block" rows="6"
                    spellcheck="false"><?php echo esc_textarea(
                        $this->getSystemReport(),
                    ); ?></textarea>

                <div style="margin-top: 10px; text-align: right;">
                    <button type="button" class="wpsc-btn-secondary wpsc-copy-trigger" data-copy-target="wpsc-system-report">
                        <span class="dashicons dashicons-clipboard"></span> Copy Report
                    </button>
                </div>
            </div>
        </section>
        <?php
    }

    // ... (Keep existing handleInstallObjectCache, handleRemoveObjectCache, redirectWithNotice, handleGetUrls, handleProcessUrl) ...
    // Note: I'm omitting the exact copies of the helper functions for brevity,
    // but you should COPY them from your previous file as they don't need design changes.
    // If you need them pasted here let me know, but the "render()" method is the only thing changing.

    public function handleInstallObjectCache(): void
    {
        /* ... copy from previous ... */
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
            @chmod($destination, 0644);
            $this->redirectWithNotice(
                "Object Cache Drop-in installed.",
                "success",
            );
        } else {
            $this->redirectWithNotice("Failed to copy file.", "error");
        }
    }

    public function handleRemoveObjectCache(): void
    {
        check_admin_referer("wpsc_remove_object_cache");
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }
        $file = WP_CONTENT_DIR . "/object-cache.php";
        if (file_exists($file)) {
            @unlink($file);
            wp_cache_flush();
            $this->redirectWithNotice("Drop-in removed.", "success");
        } else {
            $this->redirectWithNotice("File not found.", "warning");
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

    public function handleGetUrls(): void
    {
        /* ... copy from previous ... */
        // ... (standard logic)
        check_ajax_referer("wpsc_ajax_nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error();
        }
        $query = new \WP_Query([
            "post_type" => ["page", "post"],
            "post_status" => "publish",
            "posts_per_page" => 200,
            "fields" => "ids",
        ]);
        $urls = [home_url("/")];
        foreach ($query->posts as $id) {
            $urls[] = get_permalink($id);
        }
        wp_send_json_success(array_values(array_unique($urls)));
    }

    public function handleProcessUrl(): void
    {
        /* ... copy from previous ... */
        check_ajax_referer("wpsc_ajax_nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error();
        }
        $url = esc_url_raw($_POST["url"] ?? "");
        if (empty($url)) {
            wp_send_json_error();
        }
        wp_remote_get($url, [
            "timeout" => 5,
            "blocking" => true,
            "sslverify" => false,
        ]);
        wp_send_json_success();
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
