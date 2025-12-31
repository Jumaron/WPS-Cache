<?php

declare(strict_types=1);

namespace WPSCache\Admin\Tools;

/**
 * Handles Maintenance Tools, Preloading, and Debug Information.
 */
class ToolsManager
{
    public function __construct()
    {
        add_action('wp_ajax_wpsc_preload_cache', [$this, 'handlePreloadRequest']);
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
                    Automatically crawl your site to generate static HTML files.
                    This prevents the first visitor from experiencing a slow load time.
                </p>

                <div id="wpsc-preload-progress" style="display:none; margin: 15px 0;">
                    <progress value="0" max="100" style="width: 100%;"></progress>
                    <div class="progress-text" style="text-align: center; font-size: 0.9em; margin-top: 5px;"></div>
                </div>

                <button type="button" id="wpsc-preload-cache" class="button button-primary wpsc-btn-primary">
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
                <textarea readonly class="wpsc-textarea" rows="10" style="font-family: monospace; font-size: 12px;"><?php echo esc_textarea($this->getSystemReport()); ?></textarea>
                <p style="margin-top: 10px;">
                    <button type="button" class="button wpsc-btn-secondary" onclick="navigator.clipboard.writeText(this.parentElement.previousElementSibling.value)">
                        Copy to Clipboard
                    </button>
                </p>
            </div>
        </div>
<?php
    }

    /**
     * AJAX Handler: Process a batch of URLs.
     */
    public function handlePreloadRequest(): void
    {
        check_ajax_referer('wpsc_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Generate list if not provided
        // In a real scenario, we might paginate this list. 
        // For simplicity/robustness, we fetch top 50 pages here.
        $urls = $this->getPreloadUrls();
        $total = count($urls);
        $success = 0;

        foreach ($urls as $url) {
            $response = wp_remote_get($url, [
                'timeout'   => 5,
                'sslverify' => false,
                'cookies'   => [], // Ensure no login cookies are sent
                'headers'   => ['User-Agent' => 'WPS-Cache-Preloader']
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $success++;
            }
        }

        wp_send_json_success([
            'total' => $total,
            'success' => $success
        ]);
    }

    private function getPreloadUrls(): array
    {
        // 1. Home
        $urls = [home_url('/')];

        // 2. Pages
        $pages = get_pages(['number' => 20]);
        foreach ($pages as $p) {
            $urls[] = get_permalink($p->ID);
        }

        // 3. Recent Posts
        $posts = get_posts(['numberposts' => 20]);
        foreach ($posts as $p) {
            $urls[] = get_permalink($p->ID);
        }

        return array_unique($urls);
    }

    private function getSystemReport(): string
    {
        global $wp_version;

        $report = "### WPS Cache System Report ###\n\n";
        $report .= "WP Version: " . $wp_version . "\n";
        $report .= "PHP Version: " . PHP_VERSION . "\n";
        $report .= "Web Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
        $report .= "MySQL Version: " . $GLOBALS['wpdb']->db_version() . "\n\n";

        $report .= "### Configuration ###\n";
        $settings = get_option('wpsc_settings', []);
        foreach ($settings as $k => $v) {
            if (str_contains($k, 'password')) $v = '********';
            if (is_array($v)) $v = implode(', ', $v);
            $report .= "$k: $v\n";
        }

        $report .= "\n### Filesystem ###\n";
        $cache_dir = defined('WPSC_CACHE_DIR') ? WPSC_CACHE_DIR : 'Unknown';
        $report .= "Cache Dir: $cache_dir\n";
        $report .= "Writable: " . (is_writable($cache_dir) ? 'Yes' : 'No') . "\n";

        return $report;
    }
}
