<?php

declare(strict_types=1);

namespace WPSCache\Admin\Analytics;

use WPSCache\Cache\CacheManager;
use WPSCache\Cache\Drivers\RedisCache;

/**
 * Collects and processes cache metrics
 */
class MetricsCollector
{
    private CacheManager $cache_manager;
    private const METRICS_RETENTION = 30; // Days to keep historical metrics
    private const METRICS_TRANSIENT = 'wpsc_metrics_data';
    private const HISTORICAL_OPTION = 'wpsc_historical_metrics';

    public function __construct(CacheManager $cache_manager)
    {
        $this->cache_manager = $cache_manager;
    }

    /**
     * Collects current metrics from all cache types
     */
    public function collectMetrics(): void
    {
        try {
            $metrics = [
                'timestamp' => current_time('timestamp'),
                'html'      => $this->getHtmlCacheStats(),
                'redis'     => $this->getRedisMetrics(),
                'varnish'   => $this->getVarnishStats(),
                'system'    => $this->getSystemMetrics()
            ];

            // Store current metrics
            set_transient(self::METRICS_TRANSIENT, $metrics, HOUR_IN_SECONDS);

            // Store historical data
            $this->storeHistoricalMetrics($metrics);

            // Cleanup old data
            $this->cleanupHistoricalMetrics();
        } catch (\Exception $e) {
            // Removed error_log() call for production.
        }
    }

    /**
     * Gets current metrics data
     */
    public function getCurrentMetrics(): array
    {
        $metrics = get_transient(self::METRICS_TRANSIENT);

        if (!$metrics) {
            $this->collectMetrics();
            $metrics = get_transient(self::METRICS_TRANSIENT);
        }

        return $metrics ?: [];
    }

    /**
     * Gets historical metrics data
     */
    public function getHistoricalMetrics(): array
    {
        $historical = get_option(self::HISTORICAL_OPTION, []);
        $intervals = ['hourly', 'daily', 'weekly'];
        $aggregated = [];

        foreach ($intervals as $interval) {
            $aggregated[$interval] = $this->aggregateMetrics($historical, $interval);
        }

        return $aggregated;
    }

    /**
     * Gets HTML cache statistics
     */
    public function getHtmlCacheStats(): array
    {
        $cache_dir = WPSC_CACHE_DIR . 'html/';
        $files = glob($cache_dir . '*.html');
        $total_size = 0;
        $file_count = 0;
        $expired_count = 0;

        $settings = get_option('wpsc_settings');
        $lifetime = $settings['cache_lifetime'] ?? 3600;

        if (is_array($files)) {
            $file_count = count($files);
            foreach ($files as $file) {
                if (is_file($file)) {
                    $total_size += filesize($file);
                    if ((time() - filemtime($file)) >= $lifetime) {
                        $expired_count++;
                    }
                }
            }
        }

        // Calculate hit ratio
        $hits = (int)get_transient('wpsc_html_cache_hits') ?: 0;
        $misses = (int)get_transient('wpsc_html_cache_misses') ?: 0;
        $total_requests = $hits + $misses;
        $hit_ratio = $total_requests > 0 ? ($hits / $total_requests) * 100 : 0;

        return [
            'total_files'   => $file_count,
            'expired_files' => $expired_count,
            'total_size'    => $total_size,
            'hits'          => $hits,
            'misses'        => $misses,
            'hit_ratio'     => round($hit_ratio, 2),
            'cache_dir'     => $cache_dir,
        ];
    }

    /**
     * Gets Redis metrics
     */
    private function getRedisMetrics(): ?array
    {
        $redis_driver = $this->cache_manager->getDriver('redis');
        if (!$redis_driver instanceof RedisCache) {
            return null;
        }

        try {
            $info = $redis_driver->getStats();
            $stats = $this->processRedisStats($info);

            // Calculate trends
            $previous_stats = get_transient('wpsc_previous_redis_stats');
            if ($previous_stats) {
                $stats['trends'] = $this->calculateMetricsTrends($stats, $previous_stats);
            }
            set_transient('wpsc_previous_redis_stats', $stats, 5 * MINUTE_IN_SECONDS);

            return $stats;
        } catch (\Exception $e) {
            // Removed error_log() call for production.
            return null;
        }
    }

    /**
     * Processes Redis statistics
     */
    private function processRedisStats(array $info): array
    {
        // Calculate hit ratio
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total_ops = $hits + $misses;
        $hit_ratio = $total_ops > 0 ? ($hits / $total_ops) * 100 : 0;

        return [
            'connected'              => true,
            'version'                => $info['redis_version'] ?? 'unknown',
            'uptime'                 => $info['uptime_in_seconds'] ?? 0,
            'memory_used'            => $info['used_memory'] ?? 0,
            'memory_peak'            => $info['used_memory_peak'] ?? 0,
            'hit_ratio'              => round($hit_ratio, 2),
            'hits'                   => $hits,
            'misses'                 => $misses,
            'total_connections'      => $info['total_connections_received'] ?? 0,
            'connected_clients'      => $info['connected_clients'] ?? 0,
            'evicted_keys'           => $info['evicted_keys'] ?? 0,
            'expired_keys'           => $info['expired_keys'] ?? 0,
            'last_save_time'         => $info['last_save_time'] ?? 0,
            'total_commands_processed' => $info['total_commands_processed'] ?? 0
        ];
    }

    /**
     * Gets Varnish statistics
     */
    public function getVarnishStats(): ?array
    {
        $settings = get_option('wpsc_settings');
        if (!($settings['varnish_cache'] ?? false)) {
            return null;
        }

        try {
            $varnish_host = $settings['varnish_host'] ?? '127.0.0.1';
            $varnish_port = (int)($settings['varnish_port'] ?? 6081);

            // Check Varnish connection using wp_remote_get() instead of fsockopen()
            $url = "http://{$varnish_host}:{$varnish_port}";
            $response = wp_remote_get($url, ['timeout' => 1]);
            $is_active = !is_wp_error($response);

            // Get Varnish headers from test request
            $test_response = wp_remote_get(home_url(), [
                'headers' => ['X-WPSC-Cache-Check' => '1'],
                'timeout' => 5,
            ]);

            $varnish_headers = [];
            if (!is_wp_error($test_response)) {
                $headers = wp_remote_retrieve_headers($test_response);
                foreach ($headers as $key => $value) {
                    if (stripos($key, 'x-varnish') === 0 || stripos($key, 'via') === 0) {
                        $varnish_headers[$key] = $value;
                    }
                }
            }

            return [
                'connected'  => $is_active,
                'is_varnish' => !empty($varnish_headers),
                'status'     => $is_active ? 'Active' : 'Inactive',
                'host'       => $varnish_host,
                'port'       => $varnish_port,
                'headers'    => $varnish_headers,
            ];
        } catch (\Exception $e) {
            // Removed error_log() call for production.
            return null;
        }
    }

    /**
     * Gets system metrics
     */
    private function getSystemMetrics(): array
    {
        return [
            'memory_usage'  => memory_get_usage(true),
            'memory_peak'   => memory_get_peak_usage(true),
            'opcache_enabled' => function_exists('opcache_get_status') &&
                opcache_get_status() !== false,
            'opcache_stats' => $this->getOpcacheStats(),
        ];
    }

    /**
     * Gets OPcache statistics if available
     */
    private function getOpcacheStats(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }

        $stats = opcache_get_status(false);
        if (!$stats) {
            return null;
        }

        return [
            'hits'            => $stats['opcache_statistics']['hits'] ?? 0,
            'misses'          => $stats['opcache_statistics']['misses'] ?? 0,
            'memory_used'     => $stats['memory_usage']['used_memory'] ?? 0,
            'memory_free'     => $stats['memory_usage']['free_memory'] ?? 0,
            'cached_scripts'  => $stats['opcache_statistics']['num_cached_scripts'] ?? 0,
        ];
    }

    /**
     * Stores metrics in historical data
     */
    private function storeHistoricalMetrics(array $metrics): void
    {
        if (!get_option('wpsc_settings')['enable_metrics']) {
            return;
        }

        $historical = get_option(self::HISTORICAL_OPTION, []);
        $timestamp = current_time('timestamp');

        // Store essential metrics
        $historical[$timestamp] = [
            'hit_ratio'     => $metrics['redis']['hit_ratio'] ?? 0,
            'memory_used'   => $metrics['redis']['memory_used'] ?? 0,
            'total_ops'     => ($metrics['redis']['hits'] ?? 0) +
                ($metrics['redis']['misses'] ?? 0),
            'system_memory' => $metrics['system']['memory_usage'] ?? 0,
        ];

        update_option(self::HISTORICAL_OPTION, $historical);
    }

    /**
     * Cleans up old historical metrics
     */
    private function cleanupHistoricalMetrics(): void
    {
        $historical = get_option(self::HISTORICAL_OPTION, []);
        $retention = self::METRICS_RETENTION * DAY_IN_SECONDS;
        $cutoff = current_time('timestamp') - $retention;

        $historical = array_filter(
            $historical,
            fn($time) => $time >= $cutoff,
            ARRAY_FILTER_USE_KEY
        );

        update_option(self::HISTORICAL_OPTION, $historical);
    }

    /**
     * Aggregates metrics for different time intervals
     */
    private function aggregateMetrics(array $metrics, string $interval): array
    {
        $now = current_time('timestamp');
        $period = match ($interval) {
            'hourly' => HOUR_IN_SECONDS,
            'daily'  => DAY_IN_SECONDS,
            'weekly' => WEEK_IN_SECONDS,
            default  => DAY_IN_SECONDS
        };

        $aggregated = [];
        foreach ($metrics as $timestamp => $data) {
            if ($timestamp >= $now - $period) {
                $bucket = floor($timestamp / $period) * $period;
                if (!isset($aggregated[$bucket])) {
                    $aggregated[$bucket] = [
                        'count' => 0,
                        'total' => array_fill_keys(array_keys($data), 0)
                    ];
                }

                foreach ($data as $key => $value) {
                    $aggregated[$bucket]['total'][$key] += $value;
                }
                $aggregated[$bucket]['count']++;
            }
        }

        // Calculate averages
        $result = [];
        foreach ($aggregated as $bucket => $data) {
            $result[$bucket] = [];
            foreach ($data['total'] as $key => $total) {
                $result[$bucket][$key] = $total / $data['count'];
            }
        }

        return $result;
    }

    /**
     * Calculates trends between current and previous metrics
     */
    private function calculateMetricsTrends(array $current, array $previous): array
    {
        $trends = [];
        $trend_metrics = [
            'hits',
            'misses',
            'memory_used',
            'evicted_keys',
            'expired_keys',
            'total_connections'
        ];

        foreach ($trend_metrics as $metric) {
            if (isset($current[$metric], $previous[$metric]) && $previous[$metric] > 0) {
                $change = (($current[$metric] - $previous[$metric]) / $previous[$metric]) * 100;
                $trends[$metric] = round($change, 2);
            } else {
                $trends[$metric] = 0;
            }
        }

        return $trends;
    }
}
