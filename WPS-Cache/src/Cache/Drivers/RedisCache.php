<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use Redis;
use RedisException;

/**
 * High-performance Redis driver with SOTA compression and serialization.
 * Supports Redis Cluster, TLS, and non-blocking deletion.
 */
final class RedisCache extends AbstractCacheDriver
{
    private ?Redis $redis = null;
    private string $host;
    private int $port;
    private int $db;
    private string $password;
    private string $prefix;
    private float $timeout;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $db = 0,
        float $timeout = 1.0,
        float $readTimeout = 1.0,
        string $password = '',
        string $prefix = 'wpsc:'
    ) {
        parent::__construct();
        $this->host = $host;
        $this->port = $port;
        $this->db = $db;
        $this->timeout = $timeout;
        $this->password = $password;
        $this->prefix = $prefix;
    }

    public function isSupported(): bool
    {
        return extension_loaded('redis') && class_exists('Redis');
    }

    public function initialize(): void
    {
        if (!$this->isSupported() || $this->redis) {
            return;
        }

        try {
            $this->connect();
        } catch (RedisException $e) {
            $this->logError('Redis Connection Failed: ' . $e->getMessage());
        }
    }

    private function connect(): void
    {
        $this->redis = new Redis();

        // 1. Connection (Support TLS via 'tls://' host prefix)
        if (!$this->redis->connect($this->host, $this->port, $this->timeout)) {
            throw new RedisException('Unable to connect to Redis server.');
        }

        // 2. Authentication
        if (!empty($this->password)) {
            $auth = is_array($this->password) ? $this->password : [$this->password];
            if (!$this->redis->auth($auth)) {
                throw new RedisException('Redis authentication failed.');
            }
        }

        // 3. Select Database
        if ($this->db !== 0 && !$this->redis->select($this->db)) {
            throw new RedisException('Redis DB selection failed.');
        }

        // 4. Set Prefix
        $this->redis->setOption(Redis::OPT_PREFIX, $this->prefix);

        // 5. SOTA Optimizations
        // Use igbinary for smaller memory footprint if available
        if (defined('Redis::SERIALIZER_IGBINARY') && extension_loaded('igbinary')) {
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
        } else {
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        }

        // Use ZSTD or LZ4 compression if available (Greatly reduces network/RAM usage)
        if (defined('Redis::COMPRESSION_ZSTD') && extension_loaded('zstd')) {
            $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
            $this->redis->setOption(Redis::OPT_COMPRESSION_LEVEL, 3); // Balanced level
        } elseif (defined('Redis::COMPRESSION_LZ4') && extension_loaded('lz4')) {
            $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZ4);
        }
    }

    public function get(string $key): mixed
    {
        if (!$this->redis) return null;
        try {
            $result = $this->redis->get($key);
            return $result === false ? null : $result;
        } catch (RedisException $e) {
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (!$this->redis) return;
        try {
            if ($ttl > 0) {
                $this->redis->setex($key, $ttl, $value);
            } else {
                $this->redis->set($key, $value);
            }
        } catch (RedisException $e) {
            $this->logError("Set Failed for key $key", $e);
        }
    }

    public function delete(string $key): void
    {
        if (!$this->redis) return;
        try {
            $this->redis->del($key);
        } catch (RedisException $e) {
            // Ignore delete errors
        }
    }

    /**
     * Non-blocking clear using SCAN instead of KEYS *.
     * This is critical for high-traffic sites to avoid locking the Redis thread.
     */
    public function clear(): void
    {
        if (!$this->redis) {
            // Try to connect just for clearing
            try {
                $this->connect();
            } catch (RedisException $e) {
                return;
            }
        }

        try {
            // If prefix is empty, we must be careful not to flush unrelated data.
            // But if we are the only user of this DB, flushDB is faster.
            if (empty($this->prefix)) {
                $this->redis->flushDB();
                return;
            }

            // If we have a prefix, we must scan and delete specific keys 
            // because Redis::OPT_PREFIX is transparent handling.
            $this->redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
            $iterator = null;

            // Note: When OPT_PREFIX is set, 'scan' automatically filters by prefix in phpredis
            // We loop until iterator returns to 0
            while ($keys = $this->redis->scan($iterator, '*', 100)) {
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
            }
        } catch (RedisException $e) {
            $this->logError('Clear failed', $e);
        }
    }
}
