<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use WPSCache\Cache\Abstracts\AbstractCacheDriver;
use Redis;
use RedisException;

final class RedisCache extends AbstractCacheDriver {
    private ?Redis $redis = null;
    private bool $is_connected = false;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly int $db = 0,
        private readonly float $timeout = 1.0,
        private readonly ?string $password = null,
        private readonly string $prefix = 'wpsc:',
        private readonly bool $persistent = false
    ) {
        parent::__construct(WPSC_CACHE_DIR . 'redis');
    }

    protected function getFileExtension(): string {
        return '.redis';
    }

    protected function doInitialize(): void {
        try {
            $this->redis = new Redis();
            
            $connect_method = $this->persistent ? 'pconnect' : 'connect';
            $connected = @$this->redis->$connect_method($this->host, $this->port, $this->timeout);

            if (!$connected) {
                throw new RedisException("Failed to connect to Redis server at {$this->host}:{$this->port}");
            }

            if ($this->password && !$this->redis->auth($this->password)) {
                throw new RedisException("Failed to authenticate with Redis server");
            }

            if (!$this->redis->select($this->db)) {
                throw new RedisException("Failed to select Redis database {$this->db}");
            }

            $this->configureRedis();
            $this->is_connected = true;
        } catch (RedisException $e) {
            $this->handleConnectionError($e);
        }
    }

    private function configureRedis(): void {
        $this->redis->setOption(Redis::OPT_PREFIX, $this->prefix);
        $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->timeout);
        
        if (defined('Redis::COMPRESSION_LZ4') && extension_loaded('lz4')) {
            $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZ4);
        } elseif (defined('Redis::COMPRESSION_ZSTD') && extension_loaded('zstd')) {
            $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
        }
    }

    public function isConnected(): bool {
        return $this->is_connected && $this->redis !== null;
    }

    public function get(string $key): mixed {
        if (!$this->ensureConnection()) {
            return null;
        }

        try {
            $result = $this->redis->get($key);
            return $result === false ? null : $result;
        } catch (RedisException $e) {
            $this->handleConnectionError($e);
            return null;
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
        } catch (RedisException $e) {
            $this->handleConnectionError($e);
        }
    }

    public function clear(): void {
        if (!$this->ensureConnection()) {
            return;
        }

        try {
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

        error_log(sprintf(
            'WPS Cache Redis Error: %s (Host: %s, Port: %d, DB: %d)',
            $e->getMessage(),
            $this->host,
            $this->port,
            $this->db
        ));
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