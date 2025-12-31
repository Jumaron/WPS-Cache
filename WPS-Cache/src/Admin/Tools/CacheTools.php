<?php

declare(strict_types=1);

namespace WPSCache\Admin\Tools;

use WPSCache\Cache\CacheManager;
use WPSCache\Cache\Drivers\RedisCache;

/**
 * Handles cache manipulation and management operations
 */
class CacheTools
{
    private CacheManager $cache_manager;
    private const OBJECT_CACHE_TEMPLATE = 'object-cache.php';

    public function __construct(CacheManager $cache_manager)
    {
        $this->cache_manager = $cache_manager;
    }

    /**
     * Renders cache management interface (New Grid Layout)
     */
    public function renderCacheManagement(): void
    {
        $object_cache_installed = file_exists(WP_CONTENT_DIR . '/object-cache.php');
?>
        <div class="wpsc-stats-grid" style="margin-bottom: 2rem;">
            <!-- Clear Cache Card -->
            <div class="wpsc-card" style="margin-bottom: 0;">
                <div class="wpsc-card-body" style="text-align: center; padding: 2rem;">
                    <div style="background: #eff6ff; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem auto;">
                        <span class="dashicons dashicons-trash" style="color: var(--wpsc-primary); font-size: 24px; width: 24px; height: 24px;"></span>
                    </div>
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem;">Purge Entire Cache</h3>
                    <p style="color: var(--wpsc-text-muted); margin-bottom: 1.5rem;">Clear HTML, Redis, and Varnish caches.</p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wpsc_clear_cache'); ?>
                        <input type="hidden" name="action" value="wpsc_clear_cache">
                        <button type="submit" class="button wpsc-btn-primary" style="width: 100%;">
                            Clear All Caches
                        </button>
                    </form>
                </div>
            </div>

            <!-- Object Cache Drop-in Card -->
            <div class="wpsc-card" style="margin-bottom: 0;">
                <div class="wpsc-card-body" style="text-align: center; padding: 2rem;">
                    <div style="background: #f3f4f6; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem auto;">
                        <span class="dashicons dashicons-database" style="color: #4b5563; font-size: 24px; width: 24px; height: 24px;"></span>
                    </div>
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem;">Object Cache Drop-in</h3>

                    <?php if ($object_cache_installed): ?>
                        <p style="color: var(--wpsc-success); margin-bottom: 1.5rem; font-weight: 500;">
                            <span class="dashicons dashicons-yes"></span> Installed & Active
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('wpsc_remove_object_cache'); ?>
                            <input type="hidden" name="action" value="wpsc_remove_object_cache">
                            <button type="submit" class="button wpsc-btn-secondary" style="color: var(--wpsc-danger); border-color: var(--wpsc-danger); width: 100%;">
                                Uninstall Drop-in
                            </button>
                        </form>
                    <?php else: ?>
                        <p style="color: var(--wpsc-warning); margin-bottom: 1.5rem;">
                            Not Installed
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('wpsc_install_object_cache'); ?>
                            <input type="hidden" name="action" value="wpsc_install_object_cache">
                            <button type="submit" class="button wpsc-btn-primary" style="width: 100%;">
                                Install Drop-in
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Renders cache preloading tools
     */
    public function renderPreloadingTools(): void
    {
        $urls = $this->getPreloadUrls();
    ?>
        <div class="wpsc-preload-tools">
            <p class="wpsc-setting-desc" style="margin-bottom: 1rem;">
                Preload cache for your most important pages to ensure optimal performance.
                Found <strong><?php echo count($urls); ?></strong> URLs to process.
            </p>

            <div style="display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem;">
                <button type="button" class="button wpsc-btn-primary" id="wpsc-preload-cache">
                    Start Preloading
                </button>
            </div>

            <div id="wpsc-preload-progress" style="display: none; background: #f9fafb; padding: 1rem; border-radius: 8px; border: 1px solid var(--wpsc-border);">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span style="font-weight: 600;">Progress</span>
                    <span class="progress-text">0%</span>
                </div>
                <progress value="0" max="100" style="width: 100%; height: 10px; border-radius: 5px;" aria-label="<?php echo esc_attr__('Cache Preloading Progress', 'wps-cache'); ?>"></progress>
            </div>

            <div class="wpsc-setting-row" style="border: none; padding: 1rem 0 0 0;">
                <details>
                    <summary style="cursor: pointer; color: var(--wpsc-primary);">View URL List</summary>
                    <textarea id="wpsc-preload-urls" class="wpsc-textarea" rows="5" readonly style="margin-top: 10px; width: 100%;">
                        <?php echo esc_textarea(implode("\n", $urls)); ?>
                    </textarea>
                </details>
            </div>
        </div>
<?php
    }

    /**
     * Clears all caches (Full Logic)
     */
    public function clearAllCaches(): array
    {
        $cleared = [];
        $success = true;

        try {
            // Clear HTML cache
            if ($this->cache_manager->clearHtmlCache()) {
                $cleared[] = 'HTML';
            }

            // Clear Redis cache
            if ($this->cache_manager->clearRedisCache()) {
                $cleared[] = 'Redis';
            }

            // Clear Varnish cache
            if ($this->cache_manager->clearVarnishCache()) {
                $cleared[] = 'Varnish';
            }

            // Clear WordPress object cache
            wp_cache_flush();

            // Clear PHP opcache if available
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            // Trigger action for other caches
            do_action('wpsc_clear_all_caches');

            set_transient('wpsc_last_cache_clear', time());
        } catch (\Exception $e) {
            $success = false;
        }

        return [
            'success' => $success,
            'cleared' => $cleared
        ];
    }

    /**
     * Gets URLs for preloading (Full Logic)
     */
    public function getPreloadUrls(): array
    {
        $settings = get_option('wpsc_settings');
        $urls = $settings['preload_urls'] ?? [];

        // Add important URLs if not specified manually
        if (empty($urls)) {
            $url_set = [];

            // Add all pages
            $pages = get_pages();
            foreach ($pages as $page) {
                $url_set[get_permalink($page)] = true;
            }

            // Add popular posts
            $popular_posts = get_posts([
                'posts_per_page' => 10,
                'orderby'        => 'comment_count',
                'order'          => 'DESC'
            ]);

            foreach ($popular_posts as $post) {
                $url_set[get_permalink($post)] = true;
            }

            // Add category archives
            $categories = get_categories([
                'orderby' => 'count',
                'order'   => 'DESC',
                'number'  => 5
            ]);

            foreach ($categories as $category) {
                $url_set[get_category_link($category->term_id)] = true;
            }

            $urls = array_keys($url_set);
        }

        return $urls;
    }

    /**
     * Preloads cache for specified URLs (Full Logic)
     */
    public function preloadCache(): array
    {
        $urls = $this->getPreloadUrls();
        $total = count($urls);
        $results = [];

        foreach ($urls as $index => $url) {
            try {
                // Sentinel: Use wp_safe_remote_get to prevent SSRF and enforce SSL verification
                $response = wp_safe_remote_get($url, [
                    'timeout'   => 30,
                    'user-agent' => 'WPSCache Preloader'
                ]);

                if (is_wp_error($response)) {
                    throw new \Exception($response->get_error_message());
                }

                $status = wp_remote_retrieve_response_code($response);
                $results[] = [
                    'url'     => $url,
                    'status'  => $status,
                    'success' => $status >= 200 && $status < 300
                ];

                // Calculate progress
                $processed = $index + 1;
                $progress  = ($processed / $total) * 100;

                // Update progress transient
                set_transient('wpsc_preload_progress', [
                    'total'     => $total,
                    'processed' => $processed,
                    'progress'  => $progress
                ], HOUR_IN_SECONDS);
            } catch (\Exception $e) {
                $results[] = [
                    'url'     => $url,
                    'status'  => 0,
                    'success' => false,
                    'error'   => $e->getMessage()
                ];
            }

            // Small delay between URLs to prevent server overload
            usleep(250000); // 0.25 second delay
        }

        $final_progress = [
            'total'       => $total,
            'processed'   => $total,
            'progress'    => 100,
            'results'     => $results,
            'is_complete' => true
        ];

        // Clear progress transient
        delete_transient('wpsc_preload_progress');

        return $final_progress;
    }

    /**
     * Performs scheduled cache maintenance (Full Logic)
     */
    public function performMaintenance(): void
    {
        try {
            $settings = get_option('wpsc_settings');
            $lifetime = $settings['cache_lifetime'] ?? 3600;
            $cache_dir = WPSC_CACHE_DIR . 'html/';

            if (is_dir($cache_dir)) {
                $files = glob($cache_dir . '*.html');
                if ($files) {
                    foreach ($files as $file) {
                        if (is_file($file) && (time() - filemtime($file)) >= $lifetime) {
                            @unlink($file);
                        }
                    }
                }
            }

            // Optimize Redis if enabled
            $this->optimizeRedisCache();
        } catch (\Exception $e) {
            // Log error if needed
        }
    }

    private function optimizeRedisCache(): void
    {
        try {
            $redis_driver = $this->cache_manager->getDriver('redis');
            if (!$redis_driver instanceof RedisCache) {
                return;
            }

            // Get current memory usage
            $info = $redis_driver->getStats();
            $memory_used = $info['used_memory'] ?? 0;
            $max_memory  = $info['maxmemory'] ?? 0;

            // If memory usage is over 75%, trigger cleanup
            if ($max_memory > 0 && ($memory_used / $max_memory) > 0.75) {
                $redis_driver->deleteExpired();
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
    }

    /**
     * Installs object cache drop-in (Full Logic)
     */
    public function installObjectCache(): array
    {
        try {
            $source      = WPSC_PLUGIN_DIR . 'includes/' . self::OBJECT_CACHE_TEMPLATE;
            $destination = WP_CONTENT_DIR . '/object-cache.php';

            if (file_exists($destination)) {
                return [
                    'status'  => 'error_exists',
                    'message' => esc_html__('Object cache drop-in already exists.', 'wps-cache')
                ];
            }

            if (!@copy($source, $destination)) {
                throw new \Exception(esc_html__('Failed to copy object cache drop-in file.', 'wps-cache'));
            }

            if (!function_exists('WP_Filesystem')) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
            }
            WP_Filesystem();
            global $wp_filesystem;
            $wp_filesystem->chmod($destination, 0644);

            return [
                'status'  => 'success',
                'message' => esc_html__('Object cache drop-in installed successfully.', 'wps-cache')
            ];
        } catch (\Exception $e) {
            return [
                'status'  => 'error_copy',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Removes object cache drop-in (Full Logic)
     */
    public function removeObjectCache(): array
    {
        try {
            $object_cache_file = WP_CONTENT_DIR . '/object-cache.php';

            if (!file_exists($object_cache_file)) {
                return [
                    'status'  => 'error_not_exists',
                    'message' => esc_html__('Object cache drop-in does not exist.', 'wps-cache')
                ];
            }

            if (!@wp_delete_file($object_cache_file)) {
                throw new \Exception(esc_html__('Failed to remove object cache drop-in file.', 'wps-cache'));
            }

            // Clear object cache
            wp_cache_flush();

            return [
                'status'  => 'success',
                'message' => esc_html__('Object cache drop-in removed successfully.', 'wps-cache')
            ];
        } catch (\Exception $e) {
            return [
                'status'  => 'error_remove',
                'message' => $e->getMessage()
            ];
        }
    }
}
