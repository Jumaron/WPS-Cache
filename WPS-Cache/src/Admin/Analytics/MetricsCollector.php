<?php

declare(strict_types=1);

namespace WPSCache\Admin\Analytics;

use WPSCache\Cache\CacheManager;
use WPSCache\Cache\Drivers\RedisCache;

/**
 * Service responsible for gathering performance data.
 * Caches results in Transients to prevent admin panel slowdowns.
 */
class MetricsCollector
{
    private CacheManager $cacheManager;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    /**
     * Aggregates stats from all active drivers.
     */
    public function getStats(): array
    {
        // Try to get cached stats first
        $cached = get_transient('wpsc_stats_cache');
        if ($cached !== false) {
            return $cached;
        }

        $stats = [
            'timestamp' => current_time('mysql'),
            'html'      => $this->getHtmlStats(),
            'redis'     => $this->getRedisStats(),
            'system'    => $this->getSystemStats()
        ];

        // Cache for 5 minutes
        set_transient('wpsc_stats_cache', $stats, 5 * MINUTE_IN_SECONDS);

        return $stats;
    }

    /**
     * efficient directory counting using Iterators.
     */
    private function getHtmlStats(): array
    {
        $dir = defined('WPSC_CACHE_DIR') ? WPSC_CACHE_DIR . 'html/' : WP_CONTENT_DIR . '/cache/wps-cache/html/';

        $count = 0;
        $size = 0;

        if (is_dir($dir)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'html') {
                        $count++;
                        $size += $file->getSize();
                    }
                }
            } catch (\Exception $e) {
                // Permissions error or path issue
                $count = -1;
            }
        }

        return [
            'enabled' => (bool) get_option('wpsc_settings')['html_cache'] ?? false,
            'files'   => $count,
            'size'    => size_format($size),
        ];
    }

    /**
     * Fetches low-level Redis info.
     */
    private function getRedisStats(): array
    {
        $driver = $this->cacheManager->getDriver('redis');

        if (!$driver || !method_exists($driver, 'getConnection')) {
            return ['enabled' => false];
        }

        try {
            /** @var \Redis $redis */
            $redis = $driver->getConnection();

            if (!$redis) {
                return ['enabled' => true, 'connected' => false];
            }

            $info = $redis->info();

            // Calculate Hit Ratio
            $hits = $info['keyspace_hits'] ?? 0;
            $misses = $info['keyspace_misses'] ?? 0;
            $total = $hits + $misses;
            $ratio = $total > 0 ? round(($hits / $total) * 100, 2) : 0;

            return [
                'enabled'     => true,
                'connected'   => true,
                'memory_used' => $info['used_memory_human'] ?? '0B',
                'hit_ratio'   => $ratio,
                'hits'        => $hits,
                'misses'      => $misses,
                'uptime'      => $info['uptime_in_days'] ?? 0
            ];
        } catch (\Throwable $e) {
            return ['enabled' => true, 'connected' => false, 'error' => $e->getMessage()];
        }
    }

    private function getSystemStats(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'server'      => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'memory_limit' => ini_get('memory_limit'),
            'max_exec'    => ini_get('max_execution_time')
        ];
    }
}
