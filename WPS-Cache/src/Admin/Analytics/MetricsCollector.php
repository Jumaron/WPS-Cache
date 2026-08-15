<?php

declare(strict_types=1);

namespace WPSCache\Admin\Analytics;

use WPSCache\Cache\CacheManager;
use WPSCache\Cache\Object\RedisObjectCache;
use WPSCache\Cache\Object\MemcachedObjectCache;
use WPSCache\Config\Settings;

/**
 * Service responsible for gathering performance data.
 * Caches results in Transients to prevent admin panel slowdowns.
 */
class MetricsCollector
{
    private CacheManager $cacheManager;

    public function __construct(CacheManager $cacheManager, private readonly Settings $settings)
    {
        $this->cacheManager = $cacheManager;
    }

    /**
     * Aggregates stats from all active drivers.
     */
    public function getStats(): array
    {
        if (!$this->settings->enabled('enable_metrics')) {
            return [
                "timestamp" => current_time("mysql"),
                "html" => ["enabled" => false, "files" => 0, "size" => "0 B"],
                "redis" => ["enabled" => false, "backend" => "Object"],
                "system" => $this->getSystemStats(),
            ];
        }

        $cached = get_transient("wpsc_stats_cache");
        if ($cached !== false) {
            return $cached;
        }

        $stats = [
            "timestamp" => current_time("mysql"),
            "html" => $this->getHtmlStats(),
            "redis" => $this->getRedisStats(),
            "system" => $this->getSystemStats(),
            "rum" => $this->getRumStats(),
            "uptime" => $this->getUptimeStats(),
        ];

        set_transient("wpsc_stats_cache", $stats, 5 * MINUTE_IN_SECONDS);

        return $stats;
    }

    /**
     * efficient directory counting using Iterators.
     */
    private function getHtmlStats(): array
    {
        $dir = defined("WPSC_CACHE_DIR")
            ? WPSC_CACHE_DIR . "html/"
            : WP_CONTENT_DIR . "/cache/wps-cache/html/";

        $count = 0;
        $size = 0;

        // NOTE: We cannot track "Hits" for HTML cache because Apache serves the file
        // before PHP starts. We can only track "Files Created" and "Disk Usage".
        if (is_dir($dir)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $dir,
                        \FilesystemIterator::SKIP_DOTS,
                    ),
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === "html") {
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
            "enabled" => $this->settings->enabled('html_cache'),
            "files" => $count,
            "size" => size_format($size),
            "traffic" => $this->pageTraffic(),
        ];
    }

    /** @return array<string, int|float> */
    private function pageTraffic(): array
    {
        $file = WPSC_CACHE_DIR . 'page-metrics.json';
        $metrics = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $metrics = is_array($metrics) ? $metrics : [];
        $hits = (int) ($metrics['hits'] ?? 0);
        $misses = (int) ($metrics['misses'] ?? 0);
        return [
            'hits' => $hits,
            'misses' => $misses,
            'stale_hits' => (int) ($metrics['stale_hits'] ?? 0),
            'hit_ratio' => $hits + $misses > 0 ? round($hits / ($hits + $misses) * 100, 2) : 0.0,
            'bytes_served' => (int) ($metrics['bytes_served'] ?? 0),
        ];
    }

    /**
     * Fetches low-level Redis info.
     */
    private function getRedisStats(): array
    {
        $memcached = $this->cacheManager->getDriver('memcached');
        if ($memcached instanceof MemcachedObjectCache) {
            if (!$memcached->isConnected()) {
                return ['enabled' => true, 'connected' => false, 'backend' => 'Memcached', 'error' => 'Connection could not be established.'];
            }
            $info = $memcached->stats();
            $hits = (int) ($info['get_hits'] ?? 0);
            $misses = (int) ($info['get_misses'] ?? 0);
            return [
                'enabled' => true,
                'connected' => true,
                'backend' => 'Memcached',
                'memory_used' => size_format((int) ($info['bytes'] ?? 0)),
                'hit_ratio' => $hits + $misses > 0 ? round($hits / ($hits + $misses) * 100, 2) : 0,
                'hits' => $hits,
                'misses' => $misses,
                'uptime' => isset($info['uptime']) ? round((int) $info['uptime'] / DAY_IN_SECONDS, 1) : 0,
            ];
        }
        $driver = $this->cacheManager->getDriver("redis");

        if (!$driver instanceof RedisObjectCache) {
            return ["enabled" => false, "backend" => "Object"];
        }

        try {
            $redis = $driver->getConnection();

            if ($redis === null) {
                $errorMsg = $driver->getConnectionError();
                return ["enabled" => true, "connected" => false, "backend" => "Redis", "error" => $errorMsg ?: "Connection could not be established."];
            }

            try {
                $info = $redis->info();
            } catch (\Throwable $e) {
                // Some environments or proxies restrict the INFO command or drop the connection when sent
                if ($driver->getConnection() !== null) {
                     $info = ['used_memory_human' => 'Unknown', 'keyspace_hits' => 0, 'keyspace_misses' => 0, 'uptime_in_days' => 0];
                } else {
                     throw $e;
                }
            }
            
            // Calculate Hit Ratio
            $hits = $info["keyspace_hits"] ?? 0;
            $misses = $info["keyspace_misses"] ?? 0;
            $total = $hits + $misses;
            $ratio = $total > 0 ? round(($hits / $total) * 100, 2) : 0;

            return [
                "enabled" => true,
                "connected" => true,
                "backend" => "Redis",
                "memory_used" => $info["used_memory_human"] ?? "0B",
                "hit_ratio" => $ratio, // THIS is the valid Hit Ratio (Redis Only)
                "hits" => $hits,
                "misses" => $misses,
                "uptime" => $info["uptime_in_days"] ?? 0,
            ];
        } catch (\Throwable $e) {
            // Sentinel Fix: Sanitize error message before storing in DB (Transient)
            // Even though RedisCache::getConnection() shouldn't throw leaks now, this is defense in depth.
            $msg = $e->getMessage();
            $msg = preg_replace('/redis:\/\/[^@]+@/', 'redis://***@', $msg);

            return [
                "enabled" => true,
                "connected" => false,
                "backend" => "Redis",
                "error" => $msg,
            ];
        }
    }

    private function getSystemStats(): array
    {
        return [
            "php_version" => PHP_VERSION,
            "server" => $_SERVER["SERVER_SOFTWARE"] ?? "Unknown",
            "memory_limit" => ini_get("memory_limit"),
            "max_exec" => ini_get("max_execution_time"),
        ];
    }

    /** @return array<string, int|float> */
    private function getRumStats(): array
    {
        $entries = get_option('wpsc_rum_metrics', []);
        $entries = is_array($entries) ? $entries : [];
        $result = ['samples' => count($entries)];
        foreach (['lcp', 'cls', 'inp', 'ttfb'] as $metric) {
            $values = [];
            foreach ($entries as $entry) {
                if (is_array($entry) && isset($entry[$metric]) && (float) $entry[$metric] > 0) {
                    $values[] = (float) $entry[$metric];
                }
            }
            sort($values, SORT_NUMERIC);
            $index = $values === [] ? 0 : (int) ceil(count($values) * 0.75) - 1;
            $result[$metric . '_p75'] = $values === [] ? 0 : round($values[max(0, $index)], 3);
        }
        return $result;
    }

    /** @return array{checks: int, availability: float, last_status: int} */
    private function getUptimeStats(): array
    {
        $history = get_option('wpsc_uptime_history', []);
        $history = is_array($history) ? $history : [];
        $success = count(array_filter($history, static fn(mixed $item): bool => is_array($item) && (int) ($item['status'] ?? 0) >= 200 && (int) ($item['status'] ?? 0) < 400));
        $last = $history === [] ? [] : end($history);
        return [
            'checks' => count($history),
            'availability' => $history === [] ? 0.0 : round($success / count($history) * 100, 3),
            'last_status' => is_array($last) ? (int) ($last['status'] ?? 0) : 0,
        ];
    }
}
