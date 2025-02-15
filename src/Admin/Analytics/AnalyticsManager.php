<?php
declare(strict_types=1);

namespace WPSCache\Admin\Analytics;

use WPSCache\Cache\CacheManager;
use WPSCache\Cache\Drivers\RedisCache;

/**
 * Manages cache analytics and metrics functionality
 */
class AnalyticsManager {
    private CacheManager $cache_manager;
    private MetricsCollector $metrics_collector;

    public function __construct(CacheManager $cache_manager) {
        $this->cache_manager = $cache_manager;
        $this->metrics_collector = new MetricsCollector($cache_manager);
        $this->initializeHooks();
    }

    /**
     * Initializes WordPress hooks
     */
    private function initializeHooks(): void {
        // Schedule metrics collection
        if (!wp_next_scheduled('wpsc_collect_metrics')) {
            wp_schedule_event(time(), 'hourly', 'wpsc_collect_metrics');
        }
        add_action('wpsc_collect_metrics', [$this->metrics_collector, 'collectMetrics']);
    }

    /**
     * Renders the analytics tab content
     */
    public function renderTab(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $redis_stats = $this->getRedisStats();
        ?>
        <div class="wpsc-analytics-container">
            <!-- Cache Performance Overview -->
            <div class="wpsc-stats-grid">
                <?php $this->renderStatCards($redis_stats); ?>
            </div>

            <!-- Detailed Metrics -->
            <div class="wpsc-metrics-container">
                <?php $this->renderDetailedMetrics($redis_stats); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handles AJAX request for cache statistics
     */
    public function handleAjaxGetCacheStats(): void {
        check_ajax_referer('wpsc_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        try {
            $stats = [
                'html' => $this->getHtmlCacheStats(),
                'redis' => $this->getRedisStats(),
                'varnish' => $this->getVarnishStats(),
                'last_cleared' => get_transient('wpsc_last_cache_clear'),
                'system' => $this->getSystemStats(),
            ];

            wp_send_json_success($stats);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handles AJAX request for cache metrics
     */
    public function handleAjaxGetCacheMetrics(): void {
        check_ajax_referer('wpsc_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        try {
            $metrics = $this->metrics_collector->getCurrentMetrics();
            $historical = $this->metrics_collector->getHistoricalMetrics();

            wp_send_json_success([
                'current' => $metrics,
                'historical' => $historical,
                'timestamp' => current_time('timestamp')
            ]);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Renders statistics cards
     */
    private function renderStatCards(array $redis_stats): void {
        $stats = [
            [
                'title' => __('Cache Hit Ratio', 'WPS-Cache'),
                'value' => isset($redis_stats['hit_ratio']) ? 
                    number_format_i18n($redis_stats['hit_ratio'], 2) . '%' : 'N/A',
                'metric' => 'hit_ratio'
            ],
            [
                'title' => __('Memory Usage', 'WPS-Cache'),
                'value' => isset($redis_stats['memory_used']) ? 
                    size_format($redis_stats['memory_used']) : 'N/A',
                'metric' => 'memory_used'
            ],
            [
                'title' => __('Cache Operations', 'WPS-Cache'),
                'value' => number_format_i18n(
                    ($redis_stats['hits'] ?? 0) + ($redis_stats['misses'] ?? 0)
                ),
                'metric' => 'total_ops'
            ],
            [
                'title' => __('Server Uptime', 'WPS-Cache'),
                'value' => isset($redis_stats['uptime']) ? 
                    human_time_diff(time() - $redis_stats['uptime']) : 'N/A'
            ]
        ];

        foreach ($stats as $stat) {
            $this->renderStatCard($stat);
        }
    }

    /**
     * Renders individual stat card
     */
    private function renderStatCard(array $stat): void {
        ?>
        <div class="wpsc-stat-card">
            <h3><?php echo esc_html($stat['title']); ?></h3>
            <div class="wpsc-stat-value" id="<?php echo isset($stat['metric']) ? 
                esc_attr($stat['metric']) : ''; ?>">
                <?php echo esc_html($stat['value']); ?>
            </div>
            <?php if (isset($stat['metric'])): ?>
                <div class="wpsc-stat-trend" data-metric="<?php echo esc_attr($stat['metric']); ?>"></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders detailed metrics table
     */
    private function renderDetailedMetrics(array $redis_stats): void {
        ?>
        <h3><?php esc_html_e('Detailed Metrics', 'WPS-Cache'); ?></h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Metric', 'WPS-Cache'); ?></th>
                    <th><?php esc_html_e('Value', 'WPS-Cache'); ?></th>
                    <th><?php esc_html_e('Trend', 'WPS-Cache'); ?></th>
                </tr>
            </thead>
            <tbody id="detailed-metrics">
                <?php $this->renderMetricsTableRows($redis_stats); ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Renders metrics table rows
     */
    private function renderMetricsTableRows(array $stats): void {
        $metrics = [
            'hits' => __('Cache Hits', 'WPS-Cache'),
            'misses' => __('Cache Misses', 'WPS-Cache'),
            'hit_ratio' => __('Hit Ratio', 'WPS-Cache'),
            'memory_used' => __('Memory Usage', 'WPS-Cache'),
            'memory_peak' => __('Peak Memory', 'WPS-Cache'),
            'total_connections' => __('Total Connections', 'WPS-Cache'),
            'connected_clients' => __('Connected Clients', 'WPS-Cache'),
            'evicted_keys' => __('Evicted Keys', 'WPS-Cache'),
            'expired_keys' => __('Expired Keys', 'WPS-Cache'),
        ];

        foreach ($metrics as $key => $label) {
            if (isset($stats[$key])) {
                $value = $this->formatMetricValue($key, $stats[$key]);
                $trend = $stats['trends'][$key] ?? null;
                $this->renderMetricRow($label, $value, $trend);
            }
        }
    }

    /**
     * Renders individual metric row
     */
    private function renderMetricRow(string $label, string $value, ?float $trend): void {
        ?>
        <tr>
            <td><?php echo esc_html($label); ?></td>
            <td><?php echo esc_html($value); ?></td>
            <td class="wpsc-trend <?php echo $trend > 0 ? 'positive' : ($trend < 0 ? 'negative' : ''); ?>">
                <?php if ($trend !== null): ?>
                    <span class="dashicons <?php echo $trend > 0 ? 
                        'dashicons-arrow-up-alt' : 'dashicons-arrow-down-alt'; ?>">
                    </span>
                    <?php echo esc_html(number_format_i18n(abs($trend), 2)) . '%'; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Formats metric value based on type
     */
    private function formatMetricValue(string $key, mixed $value): string {
        return match($key) {
            'hit_ratio' => number_format_i18n($value, 2) . '%',
            'memory_used', 'memory_peak' => size_format($value),
            default => number_format_i18n($value)
        };
    }

    /**
     * Gets Redis cache statistics
     */
    private function getRedisStats(): array {
        $redis_driver = $this->cache_manager->getDriver('redis');
        if (!$redis_driver instanceof RedisCache) {
            return [];
        }
        return $redis_driver->getStats();
    }
    
    /**
     * Gets HTML cache statistics
     */
    private function getHtmlCacheStats(): array {
        return $this->metrics_collector->getHtmlCacheStats();
    }

    /**
     * Gets Varnish cache statistics
     */
    private function getVarnishStats(): ?array {
        return $this->metrics_collector->getVarnishStats();
    }

    /**
     * Gets system statistics
     */
    private function getSystemStats(): array {
        return [
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'plugin_version' => WPSC_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'opcache_enabled' => function_exists('opcache_get_status') && 
                opcache_get_status() !== false,
            'redis_extension' => extension_loaded('redis'),
            'compression_available' => extension_loaded('zlib') || 
                extension_loaded('lz4') || extension_loaded('zstd'),
        ];
    }
}