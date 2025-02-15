<?php
declare(strict_types=1);

namespace WPSCache\Admin\Tools;

use WPSCache\Cache\CacheManager;
use WPSCache\Cache\Drivers\RedisCache;

/**
 * Handles cache manipulation and management operations
 */
class CacheTools {
    private CacheManager $cache_manager;
    private const OBJECT_CACHE_TEMPLATE = 'object-cache.php';

    public function __construct(CacheManager $cache_manager) {
        $this->cache_manager = $cache_manager;
    }

    /**
     * Renders cache management interface
     */
    public function renderCacheManagement(): void {
        $object_cache_installed = file_exists(WP_CONTENT_DIR . '/object-cache.php');
        ?>
        <div class="wpsc-cache-management">
            <!-- Clear Cache -->
            <div class="wpsc-tool-box">
                <h4><?php esc_html_e('Clear Cache', 'WPS-Cache'); ?></h4>
                <p class="description">
                    <?php esc_html_e('Clear all active caches including HTML, Redis, and Varnish caches.', 'WPS-Cache'); ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wpsc_clear_cache'); ?>
                    <input type="hidden" name="action" value="wpsc_clear_cache">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Clear All Caches', 'WPS-Cache'); ?>
                    </button>
                </form>
            </div>

            <!-- Object Cache Drop-in -->
            <div class="wpsc-tool-box">
                <h4><?php esc_html_e('Object Cache Drop-in', 'WPS-Cache'); ?></h4>
                <?php if ($object_cache_installed): ?>
                    <p class="wpsc-status-ok">
                        <?php esc_html_e('Object cache drop-in is installed and active.', 'WPS-Cache'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wpsc_remove_object_cache'); ?>
                        <input type="hidden" name="action" value="wpsc_remove_object_cache">
                        <button type="submit" class="button button-secondary">
                            <?php esc_html_e('Remove Object Cache Drop-in', 'WPS-Cache'); ?>
                        </button>
                    </form>
                <?php else: ?>
                    <p class="wpsc-status-warning">
                        <?php esc_html_e('Object cache drop-in is not installed.', 'WPS-Cache'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wpsc_install_object_cache'); ?>
                        <input type="hidden" name="action" value="wpsc_install_object_cache">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Install Object Cache Drop-in', 'WPS-Cache'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Cache Status -->
            <div class="wpsc-tool-box">
                <h4><?php esc_html_e('Cache Status', 'WPS-Cache'); ?></h4>
                <?php $this->renderCacheStatus(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renders cache preloading tools
     */
    public function renderPreloadingTools(): void {
        $urls = $this->getPreloadUrls();
        ?>
        <div class="wpsc-preload-tools">
            <p class="description">
                <?php esc_html_e('Preload cache for your most important pages to ensure optimal performance.', 'WPS-Cache'); ?>
            </p>

            <div class="wpsc-preload-urls">
                <h4><?php esc_html_e('URLs to Preload', 'WPS-Cache'); ?></h4>
                <textarea id="wpsc-preload-urls" class="large-text code" rows="5" readonly>
                    <?php echo esc_textarea(implode("\n", $urls)); ?>
                </textarea>
                <p class="description">
                    <?php esc_html_e('These URLs will be preloaded. You can customize the list in settings.', 'WPS-Cache'); ?>
                </p>
            </div>

            <button type="button" class="button button-primary" id="wpsc-preload-cache">
                <?php esc_html_e('Start Preloading', 'WPS-Cache'); ?>
            </button>

            <div id="wpsc-preload-progress" style="display: none;">
                <progress value="0" max="100"></progress>
                <span class="progress-text">0%</span>
            </div>
        </div>
        <?php
    }

    /**
     * Renders cache status information
     */
    private function renderCacheStatus(): void {
        $stats = $this->getCacheStats();
        ?>
        <table class="widefat striped">
            <tbody>
                <tr>
                    <th><?php esc_html_e('HTML Cache', 'WPS-Cache'); ?></th>
                    <td>
                        <?php if ($stats['html']['enabled']): ?>
                            <span class="wpsc-status-ok">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %1$s: number of files, %2$s: total cache size */
                                    __('Active - %1$s files, %2$s total size', 'WPS-Cache'),
                                    number_format_i18n($stats['html']['files']),
                                    size_format($stats['html']['size'])
                                ));
                                ?>
                            </span>
                        <?php else: ?>
                            <span class="wpsc-status-inactive"><?php esc_html_e('Inactive', 'WPS-Cache'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Redis Cache', 'WPS-Cache'); ?></th>
                    <td>
                        <?php if ($stats['redis']['enabled']): ?>
                            <span class="wpsc-status-ok">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %1$s: amount of memory used */
                                    __('Connected - %1$s memory used', 'WPS-Cache'),
                                    size_format($stats['redis']['memory_used'])
                                ));
                                ?>
                            </span>
                        <?php else: ?>
                            <span class="wpsc-status-inactive"><?php esc_html_e('Inactive', 'WPS-Cache'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Varnish Cache', 'WPS-Cache'); ?></th>
                    <td>
                        <?php if ($stats['varnish']['enabled']): ?>
                            <span class="wpsc-status-ok">
                                <?php esc_html_e('Active and responding', 'WPS-Cache'); ?>
                            </span>
                        <?php else: ?>
                            <span class="wpsc-status-inactive"><?php esc_html_e('Inactive', 'WPS-Cache'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Last Cache Clear', 'WPS-Cache'); ?></th>
                    <td>
                        <?php
                        $last_clear = get_transient('wpsc_last_cache_clear');
                        echo esc_html(
                            $last_clear
                                ? human_time_diff($last_clear) . ' ' . __('ago', 'WPS-Cache')
                                : __('Never', 'WPS-Cache')
                        );
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Gets cache statistics
     */
    private function getCacheStats(): array {
        $settings = get_option('wpsc_settings');
        
        return [
            'html'    => $this->getHtmlCacheStats($settings),
            'redis'   => $this->getRedisCacheStats($settings),
            'varnish' => $this->getVarnishCacheStats($settings)
        ];
    }

    /**
     * Gets HTML cache statistics
     */
    private function getHtmlCacheStats(array $settings): array {
        $enabled = (bool)($settings['html_cache'] ?? false);
        if (!$enabled) {
            return ['enabled' => false];
        }

        $cache_dir = WPSC_CACHE_DIR . 'html/';
        $files = glob($cache_dir . '*.html');
        $total_size = 0;
        $file_count = 0;

        if (is_array($files)) {
            $file_count = count($files);
            foreach ($files as $file) {
                if (is_file($file)) {
                    $total_size += filesize($file);
                }
            }
        }

        return [
            'enabled' => true,
            'files'   => $file_count,
            'size'    => $total_size
        ];
    }

    /**
     * Gets Redis cache statistics
     */
    private function getRedisCacheStats(array $settings): array {
        $enabled = (bool)($settings['redis_cache'] ?? false);
        if (!$enabled) {
            return ['enabled' => false];
        }
    
        try {           
            $redis_driver = $this->cache_manager->getDriver('redis');
            if (!$redis_driver instanceof RedisCache) {
                return null;
            }
            
            $stats = $redis_driver->getStats();
    
            return [
                'enabled'           => true,
                'memory_used'       => $stats['memory_used'] ?? 0,
                'connected_clients' => $stats['connected_clients'] ?? 0,
                'hits'              => $stats['hits'] ?? 0,
                'misses'            => $stats['misses'] ?? 0
            ];
        } catch (\Exception $e) {
            return [
                'enabled' => true,
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * Gets Varnish cache statistics
     */
    private function getVarnishCacheStats(array $settings): array {
        $enabled = (bool)($settings['varnish_cache'] ?? false);
        if (!$enabled) {
            return ['enabled' => false];
        }

        try {
            $varnish_host = $settings['varnish_host'] ?? '127.0.0.1';
            $varnish_port = (int)($settings['varnish_port'] ?? 6081);

            // Use wp_remote_get() instead of fsockopen()/fclose()
            $response = wp_remote_get("http://{$varnish_host}:{$varnish_port}", ['timeout' => 1]);
            $connected = !is_wp_error($response);

            return [
                'enabled'   => true,
                'connected' => $connected,
                'error'     => $connected ? null : 'Connection failed'
            ];
        } catch (\Exception $e) {
            return [
                'enabled'   => true,
                'connected' => false,
                'error'     => $e->getMessage()
            ];
        }
    }

    /**
     * Clears all caches
     */
    public function clearAllCaches(): array {
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
            // Removed debug logging.
            $success = false;
        }

        return [
            'success' => $success,
            'cleared' => $cleared
        ];
    }

    /**
     * Gets URLs for preloading
     */
    public function getPreloadUrls(): array {
        $settings = get_option('wpsc_settings');
        $urls = $settings['preload_urls'] ?? [];

        // Add important URLs if not specified
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
     * Preloads cache for specified URLs
     */
    public function preloadCache(): array {
        $urls = $this->getPreloadUrls();
        $total = count($urls);
        $results = [];
        
        foreach ($urls as $index => $url) {
            try {
                $response = wp_remote_get($url, [
                    'timeout'   => 30,
                    'sslverify' => false,
                    'user-agent'=> 'WPSCache Preloader'
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
            
            // Small delay between URLs
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
     * Performs scheduled cache maintenance
     */
    public function performMaintenance(): void {
        try {
            // Clean expired HTML cache files
            $this->cleanExpiredHtmlCache();

            // Optimize Redis if enabled
            if ($this->isRedisEnabled()) {
                $this->optimizeRedisCache();
            }

        } catch (\Exception $e) {
            // Removed debug logging.
        }
    }

    /**
     * Cleans expired HTML cache files
     */
    private function cleanExpiredHtmlCache(): void {
        $settings = get_option('wpsc_settings');
        $lifetime = $settings['cache_lifetime'] ?? 3600;
        $cache_dir = WPSC_CACHE_DIR . 'html/';
        $files = glob($cache_dir . '*.html');
        $cleaned = 0;

        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file) && (time() - filemtime($file)) >= $lifetime) {
                    if (wp_delete_file($file)) {
                        $cleaned++;
                    }
                }
            }
        }

        return;
    }

    /**
     * Optimizes Redis cache
     */
    private function optimizeRedisCache(): void {
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
            // Removed debug logging.
        }
    }

    /**
     * Installs object cache drop-in
     */
    public function installObjectCache(): array {
        try {
            $source      = WPSC_PLUGIN_DIR . 'includes/' . self::OBJECT_CACHE_TEMPLATE;
            $destination = WP_CONTENT_DIR . '/object-cache.php';

            if (file_exists($destination)) {
                return [
                    'status'  => 'error_exists',
                    'message' => esc_html__('Object cache drop-in already exists.', 'WPS-Cache')
                ];
            }

            if (!@copy($source, $destination)) {
                throw new \Exception(esc_html__('Failed to copy object cache drop-in file.', 'WPS-Cache'));
            }

            if (!function_exists('WP_Filesystem')) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
            }
            WP_Filesystem();
            global $wp_filesystem;
            $wp_filesystem->chmod($destination, 0644);

            return [
                'status'  => 'success',
                'message' => esc_html__('Object cache drop-in installed successfully.', 'WPS-Cache')
            ];

        } catch (\Exception $e) {
            return [
                'status'  => 'error_copy',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Removes object cache drop-in
     */
    public function removeObjectCache(): array {
        try {
            $object_cache_file = WP_CONTENT_DIR . '/object-cache.php';

            if (!file_exists($object_cache_file)) {
                return [
                    'status'  => 'error_not_exists',
                    'message' => esc_html__('Object cache drop-in does not exist.', 'WPS-Cache')
                ];
            }

            if (!@wp_delete_file($object_cache_file)) {
                throw new \Exception(esc_html__('Failed to remove object cache drop-in file.', 'WPS-Cache'));
            }

            // Clear object cache
            wp_cache_flush();

            return [
                'status'  => 'success',
                'message' => esc_html__('Object cache drop-in removed successfully.', 'WPS-Cache')
            ];

        } catch (\Exception $e) {
            return [
                'status'  => 'error_remove',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Checks if Redis is enabled
     */
    private function isRedisEnabled(): bool {
        $settings = get_option('wpsc_settings');
        return (bool)($settings['redis_cache'] ?? false);
    }
}
