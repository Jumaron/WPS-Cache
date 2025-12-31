<?php

declare(strict_types=1);

namespace WPSCache\Admin\Tools;

/**
 * Handles Maintenance Tools with SOTA "Queue & Worker" Preloading.
 */
class ToolsManager
{
    public function __construct()
    {
        // Register AJAX handlers for the Queue System
        add_action('wp_ajax_wpsc_get_preload_urls', [$this, 'handleGetUrls']);
        add_action('wp_ajax_wpsc_process_preload_url', [$this, 'handleProcessUrl']);
    }

    /**
     * Renders the Tools Tab.
     */
    public function render(): void
    {
?>
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

                <div id="wpsc-preload-progress" style="display:none; margin: 20px 0; background: #f3f4f6; padding: 15px; border-radius: 6px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-weight: 600;">
                        <span id="wpsc-preload-status">Initializing...</span>
                        <span id="wpsc-preload-percent">0%</span>
                    </div>
                    <progress id="wpsc-preload-bar" value="0" max="100" style="width: 100%; height: 10px;"></progress>
                </div>

                <button type="button" id="wpsc-start-preload" class="button button-primary wpsc-btn-primary">
                    Start Preloading
                </button>
            </div>
        </div>

        <!-- Debug Info -->
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <h2>System Status</h2>
            </div>
            <div class="wpsc-card-body">
                <textarea readonly class="wpsc-textarea" rows="8" style="font-family: monospace; font-size: 11px; width:100%;"><?php echo esc_textarea($this->getSystemReport()); ?></textarea>
            </div>
        </div>
<?php
    }

    /**
     * AJAX Step 1: Return list of URLs to preload.
     */
    public function handleGetUrls(): void
    {
        check_ajax_referer('wpsc_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        // SOTA: Efficiently query IDs only to save memory
        $post_types = ['page', 'post'];
        if (class_exists('WooCommerce')) {
            $post_types[] = 'product';
        }

        $query = new \WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => 200, // Limit for safety
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $urls = [];
        $urls[] = home_url('/'); // Always preload home

        foreach ($query->posts as $id) {
            $link = get_permalink($id);
            if ($link) $urls[] = $link;
        }

        // CRITICAL FIX: array_values() re-indexes the array (0,1,2...)
        // ensuring json_encode outputs an Array [...], not an Object {"0":...}
        wp_send_json_success(array_values(array_unique($urls)));
    }

    /**
     * AJAX Step 2: Process a single URL.
     */
    public function handleProcessUrl(): void
    {
        check_ajax_referer('wpsc_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        if (empty($url)) wp_send_json_error('No URL provided');

        // Perform the request
        $response = wp_remote_get($url, [
            'timeout'   => 10,
            'blocking'  => true,
            'sslverify' => false,
            'cookies'   => [],
            'headers'   => [
                'User-Agent' => 'WPS-Cache-Preloader/1.0; ' . home_url()
            ]
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
        $report .= "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . "\n";
        $report .= "Cache Dir: " . (defined('WPSC_CACHE_DIR') ? WPSC_CACHE_DIR : 'N/A') . "\n";
        return $report;
    }
}
