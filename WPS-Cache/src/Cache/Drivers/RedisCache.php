<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use Redis;
use RedisException;

/**
 * High-performance Redis driver with SOTA compression and serialization.
 * Supports Redis Cluster, TLS, and non-blocking deletion.
 * Sentinel: Implements HMAC signing to prevent PHP Object Injection.
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
    private string $salt;
    private bool $useIgbinary = false;

    public function __construct(
        string $host = "127.0.0.1",
        int $port = 6379,
        int $db = 0,
        float $timeout = 1.0,
        float $readTimeout = 1.0,
        string $password = "",
        string $prefix = "wpsc:",
    ) {
        parent::__construct();
        $this->host = $host;
        $this->port = $port;
        $this->db = $db;
        $this->timeout = $timeout;
        $this->password = $password;
        $this->prefix = $prefix;

        $this->useIgbinary = extension_loaded("igbinary");

        // Sentinel: Initialize Salt for HMAC signing
        // Use standard WP keys if available.
        if (defined("WP_REDIS_SIGNING_KEY")) {
            $this->salt = WP_REDIS_SIGNING_KEY;
        } elseif (defined("WP_CACHE_KEY_SALT")) {
            $this->salt = WP_CACHE_KEY_SALT;
        } elseif (defined("AUTH_KEY")) {
            $this->salt = AUTH_KEY;
        } elseif (defined("SECURE_AUTH_KEY")) {
            $this->salt = SECURE_AUTH_KEY;
        } elseif (defined("LOGGED_IN_KEY")) {
            $this->salt = LOGGED_IN_KEY;
        } elseif (defined("NONCE_KEY")) {
            $this->salt = NONCE_KEY;
        } elseif (defined("AUTH_SALT")) {
            $this->salt = AUTH_SALT;
        } elseif (defined("SECURE_AUTH_SALT")) {
            $this->salt = SECURE_AUTH_SALT;
        } elseif (defined("LOGGED_IN_SALT")) {
            $this->salt = LOGGED_IN_SALT;
        } elseif (defined("NONCE_SALT")) {
            $this->salt = NONCE_SALT;
        } elseif (function_exists("wp_salt")) {
            $this->salt = wp_salt("auth");
        } else {
            // Sentinel Fix: Use DB credentials to generate a consistent, site-specific salt.
            // This prevents using the hardcoded fallback which is publicly known.
            // We use constants available in wp-config.php which is loaded before plugins.
            $secret = defined("DB_NAME") ? DB_NAME : "";
            $secret .= defined("DB_USER") ? DB_USER : "";
            $secret .= defined("DB_PASSWORD") ? DB_PASSWORD : "";

            if (empty($secret)) {
                $secret = "wpsc_fallback_entropy_" . __FILE__;
            }

            $this->salt = hash("sha256", $secret);
        }
    }

    public function isSupported(): bool
    {
        return extension_loaded("redis") && class_exists("Redis");
    }

    /**
     * Sentinel Enhancement: Safely expose the Redis connection for metrics.
     * Returns null if not connected.
     */
    public function getConnection(): ?Redis
    {
        return $this->redis;
    }

    public function initialize(): void
    {
        if (!$this->isSupported() || $this->redis) {
            return;
        }

        try {
            $this->connect();
        } catch (RedisException $e) {
            // Sentinel Fix: Redact sensitive info from error logs
            $safeMsg = $this->redactSensitiveInfo($e->getMessage());
            $this->logError("Redis Connection Failed: " . $safeMsg);
        }
    }

    private function connect(): void
    {
        $this->redis = new Redis();

        // 1. Connection (Support TLS via 'tls://' host prefix)
        // Note: connect() throws RedisException on failure (phpredis > 5.0)
        // or returns false (which we check).
        if (!$this->redis->connect($this->host, $this->port, $this->timeout)) {
            throw new RedisException("Unable to connect to Redis server.");
        }

        // 2. Authentication
        if (!empty($this->password)) {
            $auth = is_array($this->password)
                ? $this->password
                : [$this->password];
            // Don't pass the password here if we can avoid it in stack traces,
            // but phpredis needs it. We handle the exception to redact it.
            try {
                if (!$this->redis->auth($auth)) {
                    throw new RedisException("Redis authentication failed.");
                }
            } catch (RedisException $e) {
                // Re-throw with redacted message if it contains the password
                $safeMsg = $this->redactSensitiveInfo($e->getMessage());
                throw new RedisException($safeMsg, 0, $e);
            }
        }

        // 3. Select Database
        if ($this->db !== 0 && !$this->redis->select($this->db)) {
            throw new RedisException("Redis DB selection failed.");
        }

        // 4. Set Prefix
        $this->redis->setOption(Redis::OPT_PREFIX, $this->prefix);

        // 5. SOTA Optimizations
        // Sentinel: Removed automatic serialization (Redis::OPT_SERIALIZER)
        // We now handle serialization manually with HMAC signing to prevent Object Injection.

        // Use ZSTD or LZ4 compression if available (Greatly reduces network/RAM usage)
        if (defined("Redis::COMPRESSION_ZSTD") && extension_loaded("zstd")) {
            $this->redis->setOption(
                Redis::OPT_COMPRESSION,
                Redis::COMPRESSION_ZSTD,
            );
            $this->redis->setOption(Redis::OPT_COMPRESSION_LEVEL, 3); // Balanced level
        } elseif (
            defined("Redis::COMPRESSION_LZ4") &&
            extension_loaded("lz4")
        ) {
            $this->redis->setOption(
                Redis::OPT_COMPRESSION,
                Redis::COMPRESSION_LZ4,
            );
        }
    }

    /**
     * Sentinel Security: Scrub sensitive data (like passwords) from error messages.
     */
    private function redactSensitiveInfo(string $message): string
    {
        if (empty($this->password)) {
            return $message;
        }

        // Handle array passwords (ACL users)
        $passwords = is_array($this->password) ? $this->password : [$this->password];

        foreach ($passwords as $pwd) {
            if (is_string($pwd) && !empty($pwd)) {
                $message = str_replace($pwd, "******", $message);
            }
        }

        // Also scrub host/port if they appear in standard connection strings to be safe
        // e.g., "redis://user:pass@1.2.3.4:6379"
        $message = preg_replace('/redis:\/\/[^@]+@/', 'redis://***@', $message);

        return $message;
    }

    public function get(string $key): mixed
    {
        if (!$this->redis) {
            return null;
        }
        try {
            $result = $this->redis->get($key);
            if ($result === false) {
                return null;
            }

            // Sentinel: Verify signature and unserialize
            // Returns [success, value] to strictly distinguish between valid 'false' value and error.
            $unpacked = $this->maybeUnserialize($result);

            if ($unpacked[0] === false) {
                return null; // Cache miss (invalid signature or legacy data)
            }

            return $unpacked[1];
        } catch (RedisException $e) {
            // No logging here to prevent log flooding on cache misses/errors
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (!$this->redis) {
            return;
        }
        try {
            // Sentinel: Serialize and sign all values to preserve types and ensure security
            $value = $this->maybeSerialize($value);

            if ($ttl > 0) {
                $this->redis->setex($key, $ttl, $value);
            } else {
                $this->redis->set($key, $value);
            }
        } catch (RedisException $e) {
            // Sentinel Fix: Redact sensitive info
            $safeMsg = $this->redactSensitiveInfo($e->getMessage());
            $this->logError("Set Failed for key $key: " . $safeMsg);
        }
    }

    public function delete(string $key): void
    {
        if (!$this->redis) {
            return;
        }
        try {
            if (method_exists($this->redis, "unlink")) {
                $this->redis->unlink($key);
            } else {
                $this->redis->del($key);
            }
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
                // Async flush if available (Redis 4.0+)
                if (version_compare(phpversion("redis"), "3.1.3", ">=")) {
                    $this->redis->flushDB(true);
                } else {
                    $this->redis->flushDB();
                }
                return;
            }

            // If we have a prefix, we must scan and delete specific keys
            // because Redis::OPT_PREFIX is transparent handling.
            $this->redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
            $iterator = null;

            // Note: When OPT_PREFIX is set, 'scan' automatically filters by prefix in phpredis
            // We loop until iterator returns to 0
            while ($keys = $this->redis->scan($iterator, "*", 100)) {
                if (!empty($keys)) {
                    if (method_exists($this->redis, "unlink")) {
                        $this->redis->unlink($keys);
                    } else {
                        $this->redis->del($keys);
                    }
                }
            }
        } catch (RedisException $e) {
            // Sentinel Fix: Redact sensitive info
            $safeMsg = $this->redactSensitiveInfo($e->getMessage());
            $this->logError("Clear failed: " . $safeMsg);
        }
    }

    /**
     * Serializes data if needed.
     * Sentinel: Adds HMAC signature to serialized objects to prevent tampering.
     * NOTE: We serialize ALL values (even primitives) to ensure type fidelity (e.g. true vs "1")
     * and uniform security handling.
     */
    private function maybeSerialize(mixed $value): string
    {
        if ($this->useIgbinary) {
            $serialized = igbinary_serialize($value);
            $hash = hash_hmac("sha256", $serialized, $this->salt);
            return "I:" . $hash . ":" . $serialized;
        }

        $serialized = serialize($value);
        $hash = hash_hmac("sha256", $serialized, $this->salt);

        // S:{hash}:{serialized_data}
        return "S:" . $hash . ":" . $serialized;
    }

    /**
     * Unserializes data if needed.
     * Sentinel: Verifies HMAC signature before unserializing.
     *
     * @return array{0: bool, 1: mixed} [success, value]
     */
    private function maybeUnserialize(mixed $value): array
    {
        if (!is_string($value) || strlen($value) < 4) {
            // Should not happen if we serialize everything, but safe fallback
            // Treat as failure because we expect S:... format
            return [false, null];
        }

        $type = $value[0];
        $sep = $value[1];

        // Sentinel: Verify signed payloads
        if (($type === "S" || $type === "I") && $sep === ":") {
            $parts = explode(":", $value, 3);
            if (count($parts) === 3) {
                $hash = $parts[1];
                $payload = $parts[2];
                $calc = hash_hmac("sha256", $payload, $this->salt);

                if (hash_equals($hash, $calc)) {
                    try {
                        if ($type === "I") {
                            // Check extension availability before attempting decode
                            if (!$this->useIgbinary && !function_exists("igbinary_unserialize")) {
                                return [false, null];
                            }
                            $val = @igbinary_unserialize($payload);
                        } else {
                            $val = @unserialize($payload);
                        }

                        // If unserialize returns false, it could be the value false or an error.
                        // Since we signed it, we trust it.
                        return [true, $val];
                    } catch (\Exception $e) {
                        return [false, null];
                    }
                }
            }
            // Invalid signature or format
            return [false, null];
        }

        // Sentinel: REJECT unsigned legacy serialization
        return [false, null];
    }
}
