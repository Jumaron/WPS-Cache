<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use Redis;
use RedisException;

/**
 * Enhanced Redis cache driver with metrics and connection management
 */
final class RedisCache extends AbstractCacheDriver
{
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 6379;
    private const DEFAULT_TIMEOUT = 1.0;
    private const DEFAULT_PREFIX = 'wpsc:';

    private ?Redis $redis = null;
    private bool $is_connected = false;
    private array $metrics = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'deletes' => 0,
    ];

    private string $host;
    private int $port;
    private int $db;
    private float $timeout;
    private float $read_timeout;
    private ?string $password;
    private string $prefix;
    private bool $persistent;

    public function __construct(
        string $host = self::DEFAULT_HOST,
        int $port = self::DEFAULT_PORT,
        int $db = 0,
        float $timeout = self::DEFAULT_TIMEOUT,
        float $read_timeout = self::DEFAULT_TIMEOUT,
        ?string $password = null,
        string $prefix = self::DEFAULT_PREFIX,
        bool $persistent = false
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->db = $db;
        $this->timeout = $timeout;
        $this->read_timeout = $read_timeout;
        $this->password = $password;
        $this->prefix = $prefix;
        $this->persistent = $persistent;
    }

    public function initialize(): void
    {
        if ($this->initialized || $this->is_connected) {
            return;
        }

        try {
            $this->setupRedisConnection();
            $this->optimizeRedisSettings();
            $this->is_connected = true;
            $this->initialized = true;
        } catch (RedisException $e) {
            $this->logError("Redis initialization failed", $e);
            $this->handleConnectionError($e);
        }
    }

    private function setupRedisConnection(): void
    {
        $this->redis = new Redis();

        // Use persistent connections when possible
        $connect_method = $this->persistent ? 'pconnect' : 'connect';
        $connected = @$this->redis->$connect_method($this->host, $this->port, $this->timeout);

        /* translators: %1$s: Redis host, %2$d: Redis port */
        if (!$connected) {
            throw new RedisException(
                esc_html(sprintf(__("Failed to connect to Redis server at %1\$s:%2\$d", "wps-cache"), $this->host, $this->port))
            );
        }

        if ($this->password && !$this->redis->auth($this->password)) {
            throw new RedisException(esc_html__('Failed to authenticate with Redis server', "wps-cache"));
        }

        /* translators: %1$d: Redis database number */
        if (!$this->redis->select($this->db)) {
            throw new RedisException(
                esc_html(sprintf(__("Failed to select Redis database %1\$d", "wps-cache"), $this->db))
            );
        }
    }

    private function optimizeRedisSettings(): void
    {
        if (!$this->redis) {
            return;
        }

        // Basic settings
        $this->redis->setOption(Redis::OPT_PREFIX, $this->prefix);

        // Security Fix: Use JSON or IGBINARY instead of PHP serialization to prevent Object Injection
        if (defined('Redis::SERIALIZER_IGBINARY') && extension_loaded('igbinary')) {
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
        } elseif (defined('Redis::SERIALIZER_JSON')) {
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
        } else {
            // Fallback if neither is available, but JSON is strongly recommended
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        }

        $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->read_timeout);

        // Enable compression if available
        if (defined('Redis::COMPRESSION_LZ4') && extension_loaded('lz4')) {
            $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZ4);
        } elseif (defined('Redis::COMPRESSION_ZSTD') && extension_loaded('zstd')) {
            $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
        }
    }

    public function isConnected(): bool
    {
        return $this->is_connected && $this->redis !== null;
    }

    public function get(string $key): mixed
    {
        if (!$this->ensureConnection()) {
            $this->metrics['misses']++;
            return null;
        }

        try {
            $result = $this->redis->get($key);
            if ($result === false) {
                $this->metrics['misses']++;
                return null;
            }
            $this->metrics['hits']++;
            return $result;
        } catch (RedisException $e) {
            $this->logError("Redis get failed", $e);
            return null;
        }
    }

    public function getMultiple(array $keys): array
    {
        if (!$this->ensureConnection()) {
            return array_fill_keys($keys, null);
        }

        try {
            $pipe = $this->redis->pipeline();
            foreach ($keys as $key) {
                $pipe->get($key);
            }
            $results = $pipe->exec();

            $output = [];
            foreach ($keys as $i => $key) {
                if ($results[$i] === false) {
                    $this->metrics['misses']++;
                    $output[$key] = null;
                } else {
                    $this->metrics['hits']++;
                    $output[$key] = $results[$i];
                }
            }
            return $output;
        } catch (RedisException $e) {
            $this->logError("Redis multi-get failed", $e);
            return array_fill_keys($keys, null);
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        try {
            if ($ttl > 0) {
                $this->redis->setex($key, $ttl, $value);
            } else {
                $this->redis->set($key, $value);
            }
            $this->metrics['writes']++;
        } catch (RedisException $e) {
            $this->logError("Redis set failed", $e);
        }
    }

    public function setMultiple(array $values, int $ttl = 3600): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        try {
            $pipe = $this->redis->pipeline();
            foreach ($values as $key => $value) {
                if ($ttl > 0) {
                    $pipe->setex($key, $ttl, $value);
                } else {
                    $pipe->set($key, $value);
                }
            }
            $pipe->exec();
            $this->metrics['writes'] += count($values);
        } catch (RedisException $e) {
            $this->logError("Redis multi-set failed", $e);
        }
    }

    public function delete(string $key): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        try {
            $this->redis->del($key);
            $this->metrics['deletes']++;
        } catch (RedisException $e) {
            $this->logError("Redis delete failed", $e);
        }
    }

    public function clear(): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        try {
            // Use SCAN instead of KEYS for better performance
            $iterator = null;
            $pattern = $this->prefix . '*';

            while ($keys = $this->redis->scan($iterator, $pattern, 100)) {
                if (!empty($keys)) {
                    $this->redis->del(...$keys);
                }
            }
        } catch (RedisException $e) {
            $this->logError("Redis clear failed", $e);
        }
    }

    public function getStats(): array
    {
        if (!$this->ensureConnection()) {
            return [
                'connected' => false,
                'error' => 'Not connected to Redis server'
            ];
        }

        try {
            $info = $this->redis->info();
            return $this->buildStatsArray($info);
        } catch (RedisException $e) {
            $this->logError("Redis stats failed", $e);
            return [
                'connected' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function optimizeRedisCache(): bool
    {
        if (!$this->ensureConnection()) {
            return false;
        }

        try {
            $this->redis->bgrewriteaof();
            $this->deleteExpired();
            return true;
        } catch (RedisException $e) {
            $this->logError("Redis optimization failed", $e);
            return false;
        }
    }

    /**
     * Deletes expired keys from Redis
     */
    public function deleteExpired(): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        try {
            $iterator = null;
            $pattern = $this->prefix . '*';

            while ($keys = $this->redis->scan($iterator, $pattern, 100)) {
                foreach ($keys as $key) {
                    $ttl = $this->redis->ttl($key);
                    if ($ttl === -2) {
                        $this->redis->del($key);
                        $this->metrics['deletes']++;
                    }
                }
            }
        } catch (RedisException $e) {
            $this->logError("Redis expired keys deletion failed", $e);
        }
    }

    private function buildStatsArray(array $info): array
    {
        $total_hits = $this->metrics['hits'] + ($info['keyspace_hits'] ?? 0);
        $total_misses = $this->metrics['misses'] + ($info['keyspace_misses'] ?? 0);
        $total_ops = $total_hits + $total_misses;
        $hit_ratio = $total_ops > 0 ? ($total_hits / $total_ops) * 100 : 0;

        return [
            'connected' => true,
            'version' => $info['redis_version'] ?? 'unknown',
            'uptime' => $info['uptime_in_seconds'] ?? 0,
            'memory_used' => $info['used_memory'] ?? 0,
            'memory_peak' => $info['used_memory_peak'] ?? 0,
            'hit_ratio' => round($hit_ratio, 2),
            'hits' => $total_hits,
            'misses' => $total_misses,
            'writes' => $this->metrics['writes'],
            'deletes' => $this->metrics['deletes'],
            'total_connections' => $info['total_connections_received'] ?? 0,
            'connected_clients' => $info['connected_clients'] ?? 0,
            'evicted_keys' => $info['evicted_keys'] ?? 0,
            'expired_keys' => $info['expired_keys'] ?? 0,
            'last_save_time' => $info['last_save_time'] ?? 0,
            'total_commands_processed' => $info['total_commands_processed'] ?? 0
        ];
    }

    private function ensureConnection(): bool
    {
        if (!$this->is_connected || !$this->redis) {
            $this->initialize();
        }

        if ($this->is_connected && $this->redis) {
            try {
                $this->redis->ping();
                return true;
            } catch (RedisException $e) {
                $this->handleConnectionError($e);
                return false;
            }
        }

        return false;
    }

    private function handleConnectionError(RedisException $e): void
    {
        $this->is_connected = false;
        $this->redis = null;
        $this->initialized = false;
    }

    public function __destruct()
    {
        if ($this->redis && $this->is_connected && !$this->persistent) {
            try {
                $this->redis->close();
            } catch (RedisException $e) {
                $this->logError("Redis close failed", $e);
            }
        }
    }
}
