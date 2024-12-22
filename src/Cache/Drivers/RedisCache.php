<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use Redis;
use RedisException;

/**
 * Enhanced Redis cache driver for WPS Cache
 */
final class RedisCache implements CacheDriverInterface {
    private ?Redis $redis = null;
    private bool $is_connected = false;
    private array $metrics = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'deletes' => 0,
    ];

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly int $db = 0,
        private readonly float $timeout = 1.0,
        private readonly float $read_timeout = 1.0,
        private readonly ?string $password = null,
        private readonly string $prefix = 'wpsc:',
        private readonly bool $persistent = false
    ) {}

    public function initialize(): void {
        if ($this->is_connected) {
            return;
        }

        try {
            $this->redis = new Redis();
            
            // Use persistent connections when possible
            $connect_method = $this->persistent ? 'pconnect' : 'connect';
            $connected = @$this->redis->$connect_method($this->host, $this->port, $this->timeout);

            if (!$connected) {
                throw new RedisException("Failed to connect to Redis server at {$this->host}:{$this->port}");
            }

            if ($this->password) {
                if (!$this->redis->auth($this->password)) {
                    throw new RedisException("Failed to authenticate with Redis server");
                }
            }

            if (!$this->redis->select($this->db)) {
                throw new RedisException("Failed to select Redis database {$this->db}");
            }

            // Optimize Redis settings
            $this->redis->setOption(Redis::OPT_PREFIX, $this->prefix);
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
            $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->read_timeout);
            
            // Enable compression if available
            if (defined('Redis::COMPRESSION_LZ4') && extension_loaded('lz4')) {
                $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZ4);
            } elseif (defined('Redis::COMPRESSION_ZSTD') && extension_loaded('zstd')) {
                $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
            }

            $this->is_connected = true;
        } catch (RedisException $e) {
            $this->handleConnectionError($e);
        }
    }

    public function isConnected(): bool {
        return $this->is_connected;
    }

    public function get(string $key): mixed {
        if (!$this->ensureConnection()) {
            $this->metrics['misses']++;
            return false;
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
            $this->handleConnectionError($e);
            return false;
        }
    }

    public function getMultiple(array $keys): array {
        if (!$this->ensureConnection()) {
            return array_fill_keys($keys, false);
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
            $this->handleConnectionError($e);
            return array_fill_keys($keys, false);
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {
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
            $this->handleConnectionError($e);
        }
    }

    public function setMultiple(array $values, int $ttl = 3600): void {
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
            $this->handleConnectionError($e);
        }
    }

    public function delete(string $key): void {
        if (!$this->ensureConnection()) {
            return;
        }

        try {
            $this->redis->del($key);
            $this->metrics['deletes']++;
        } catch (RedisException $e) {
            $this->handleConnectionError($e);
        }
    }

    public function deleteMultiple(array $keys): void {
        if (!$this->ensureConnection()) {
            return;
        }

        try {
            $this->redis->del(...$keys);
            $this->metrics['deletes'] += count($keys);
        } catch (RedisException $e) {
            $this->handleConnectionError($e);
        }
    }

    public function clear(): void {
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
            $this->handleConnectionError($e);
        }
    }

    public function getStats(): array {
        if (!$this->ensureConnection()) {
            return [
                'connected' => false,
                'error' => 'Not connected to Redis server'
            ];
        }

        try {
            $info = $this->redis->info();
            $metrics = $this->metrics;

            // Calculate hit ratio including Redis stats
            $total_hits = $metrics['hits'] + ($info['keyspace_hits'] ?? 0);
            $total_misses = $metrics['misses'] + ($info['keyspace_misses'] ?? 0);
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
                'writes' => $metrics['writes'],
                'deletes' => $metrics['deletes'],
                'total_connections' => $info['total_connections_received'] ?? 0,
                'connected_clients' => $info['connected_clients'] ?? 0,
                'evicted_keys' => $info['evicted_keys'] ?? 0,
                'expired_keys' => $info['expired_keys'] ?? 0,
                'last_save_time' => $info['last_save_time'] ?? 0,
                'total_commands_processed' => $info['total_commands_processed'] ?? 0
            ];
        } catch (RedisException $e) {
            $this->handleConnectionError($e);
            return [
                'connected' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function deleteExpired(): void {
        if (!$this->ensureConnection()) {
            return;
        }
    
        try {
            // Use SCAN to iterate through keys
            $iterator = null;
            $pattern = $this->prefix . '*';
            
            while ($keys = $this->redis->scan($iterator, $pattern, 100)) {
                foreach ($keys as $key) {
                    // Check TTL for each key
                    $ttl = $this->redis->ttl($key);
                    // If TTL is -2, the key has expired
                    if ($ttl === -2) {
                        $this->redis->del($key);
                        $this->metrics['deletes']++;
                    }
                }
            }
        } catch (RedisException $e) {
            $this->handleConnectionError($e);
        }
    }

    private function ensureConnection(): bool {
        if (!$this->is_connected) {
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

    private function handleConnectionError(RedisException $e): void {
        $this->is_connected = false;
        $this->redis = null;

        $error_message = sprintf(
            'WPS Cache Redis Error: %s (Host: %s, Port: %d, DB: %d)',
            $e->getMessage(),
            $this->host,
            $this->port,
            $this->db
        );

        error_log($error_message);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            trigger_error($error_message, E_USER_WARNING);
        }
    }

    public function __destruct() {
        if ($this->redis && $this->is_connected && !$this->persistent) {
            try {
                $this->redis->close();
            } catch (RedisException $e) {
                error_log('WPS Cache Redis Error on close: ' . $e->getMessage());
            }
        }
    }
}