<?php

/**
 * WPS-Cache Object Cache Drop-In
 *
 * Ultra-high performance Redis object cache for WordPress 6.9+
 * Optimized for: Redis 8.x | PHP 8.4+ | PhpRedis extension
 *
 * @package WPSCache
 * @version 3.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit();

if (!defined('WP_REDIS_DISABLED') || !WP_REDIS_DISABLED):

// ─── Global API Functions ────────────────────────────────────────────────────

/**
 * Reports which optional cache features this drop-in supports.
 */
function wp_cache_supports(string $feature): bool
{
    return match ($feature) {
        'add_multiple',
        'set_multiple',
        'get_multiple',
        'delete_multiple',
        'flush_runtime',
        'flush_group' => true,
        default => false,
    };
}

/**
 * Bootstraps the object cache.
 *
 * @global WP_Object_Cache $wp_object_cache
 */
function wp_cache_init(): void
{
    global $wp_object_cache;

    // Load env vars that may define constants
    static $envMap = [
        'WP_REDIS_PREFIX'          => 'string',
        'WP_REDIS_SELECTIVE_FLUSH' => 'bool',
        'WP_REDIS_MAXTTL'          => 'int',
    ];

    foreach ($envMap as $name => $type) {
        if (!defined($name) && ($val = getenv($name)) !== false) {
            define($name, match ($type) {
                'bool' => filter_var($val, FILTER_VALIDATE_BOOLEAN),
                'int'  => (int) $val,
                default => $val,
            });
        }
    }

    // Backward compat
    if (defined('WP_CACHE_KEY_SALT') && !defined('WP_REDIS_PREFIX')) {
        define('WP_REDIS_PREFIX', WP_CACHE_KEY_SALT);
    }

    if (!($wp_object_cache instanceof WP_Object_Cache)) {
        $wp_object_cache = new WP_Object_Cache(
            defined('WP_REDIS_GRACEFUL') && WP_REDIS_GRACEFUL
        );
    }
}

function wp_cache_add(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
{
    global $wp_object_cache;
    return $wp_object_cache->add($key, $value, $group, $expiration);
}

function wp_cache_replace(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
{
    global $wp_object_cache;
    return $wp_object_cache->replace($key, $value, $group, $expiration);
}

function wp_cache_set(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
{
    global $wp_object_cache;
    return $wp_object_cache->set($key, $value, $group, $expiration);
}

function wp_cache_get(string $key, string $group = 'default', bool $force = false, ?bool &$found = null): mixed
{
    global $wp_object_cache;
    return $wp_object_cache->get($key, $group, $force, $found);
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    global $wp_object_cache;
    return $wp_object_cache->delete($key, $group);
}

function wp_cache_flush(): bool
{
    global $wp_object_cache;
    return $wp_object_cache->flush();
}

function wp_cache_flush_runtime(): bool
{
    global $wp_object_cache;
    return $wp_object_cache->flushRuntime();
}

function wp_cache_get_multiple(array $keys, string $group = 'default', bool $force = false): array
{
    global $wp_object_cache;
    return $wp_object_cache->getMultiple($keys, $group, $force);
}

function wp_cache_set_multiple(array $data, string $group = 'default', int $expire = 0): array
{
    global $wp_object_cache;
    return $wp_object_cache->setMultiple($data, $group, $expire);
}

function wp_cache_delete_multiple(array $keys, string $group = ''): array
{
    global $wp_object_cache;
    return $wp_object_cache->deleteMultiple($keys, $group);
}

function wp_cache_incr(string $key, int $offset = 1, string $group = ''): int|false
{
    global $wp_object_cache;
    return $wp_object_cache->increment($key, $offset, $group);
}

function wp_cache_decr(string $key, int $offset = 1, string $group = ''): int|false
{
    global $wp_object_cache;
    return $wp_object_cache->decrement($key, $offset, $group);
}

function wp_cache_switch_to_blog(int $blog_id): bool
{
    global $wp_object_cache;
    return $wp_object_cache->switchToBlog($blog_id);
}

function wp_cache_add_global_groups(string|array $groups): void
{
    global $wp_object_cache;
    $wp_object_cache->addGlobalGroups($groups);
}

function wp_cache_add_non_persistent_groups(string|array $groups): void
{
    global $wp_object_cache;
    $wp_object_cache->addNonPersistentGroups($groups);
}

function wp_cache_close(): bool
{
    global $wp_object_cache;
    return $wp_object_cache->close();
}

// ─── Core Cache Engine ───────────────────────────────────────────────────────

class WP_Object_Cache
{
    // ── Configuration Constants ──────────────────────────────────────────────

    /** Compression threshold in bytes — values larger than this are compressed */
    private const COMPRESS_THRESHOLD = 2048;

    /** Prefix for HMAC-signed serialized payloads */
    private const SIGN_PREFIX = 'S:';

    /** Prefix for compressed payloads */
    private const COMPRESS_PREFIX = 'Z:';

    /** SCAN COUNT hint for Lua flush scripts — larger = fewer iterations */
    private const SCAN_COUNT = 1000;

    // ── Connection State ─────────────────────────────────────────────────────

    private \Redis|null $redis = null;
    private bool $connected = false;
    private readonly bool $failGracefully;

    // ── Serialization Strategy ───────────────────────────────────────────────

    /** Whether PhpRedis handles serialization natively (igbinary/msgpack) */
    private bool $nativeSerializer = false;

    /** Compression algorithm available: 'lz4', 'zstd', or null */
    private ?string $compressor = null;

    /** HMAC salt for signed serialization */
    private readonly string $salt;

    // ── Key Prefixes (precomputed at init) ────────────────────────────────────

    private string $blogPrefix;
    private string $globalPrefix;

    // ── Group Registries (hash-sets for O(1) lookup) ─────────────────────────

    /** @var array<string, true> Global groups — keys shared across sites */
    private array $globalGroups = [];

    /** @var array<string, true> Non-persistent groups — in-memory only */
    private array $ignoredGroups = [];

    // ── In-Memory Cache ──────────────────────────────────────────────────────

    /** @var array<string, mixed> Local L1 cache */
    private array $cache = [];

    // ── Deferred Write Pipeline ──────────────────────────────────────────────

    /** @var array<string, array{value: mixed, expiration: int}> Pending writes */
    private array $deferred = [];
    private readonly bool $deferWrites;

    // ── Lua Script Hashes ────────────────────────────────────────────────────

    private string $flushSHA = '';
    private string $msetSHA = '';

    // ── Metrics ──────────────────────────────────────────────────────────────

    public int $cache_hits = 0;
    public int $cache_misses = 0;
    private float $cacheTime = 0.0;
    private int $cacheCalls = 0;
    /** @var string[] */
    private array $errors = [];

    // ─── Default Global Group List ───────────────────────────────────────────

    private const DEFAULT_GLOBAL_GROUPS = [
        'blog-details', 'blog-id-cache', 'blog-lookup',
        'global-posts', 'networks', 'rss',
        'sites', 'site-details', 'site-lookup',
        'site-options', 'site-transient',
        'users', 'useremail', 'userlogins',
        'usermeta', 'user_meta', 'userslugs',
        'redis-cache',
    ];

    // ═════════════════════════════════════════════════════════════════════════
    //  CONSTRUCTOR
    // ═════════════════════════════════════════════════════════════════════════

    public function __construct(bool $failGracefully = true)
    {
        global $blog_id, $table_prefix;

        $this->failGracefully = $failGracefully;
        $this->deferWrites = defined('WP_REDIS_DEFERRED_WRITES') && WP_REDIS_DEFERRED_WRITES;

        // ── Resolve HMAC salt ────────────────────────────────────────────
        $this->salt = match (true) {
            defined('WP_REDIS_SIGNING_KEY') => WP_REDIS_SIGNING_KEY,
            defined('WP_CACHE_KEY_SALT')    => WP_CACHE_KEY_SALT,
            defined('SECURE_AUTH_KEY')       => SECURE_AUTH_KEY,
            defined('LOGGED_IN_KEY')         => LOGGED_IN_KEY,
            defined('NONCE_KEY')             => NONCE_KEY,
            default => hash('xxh128', (defined('DB_NAME') ? DB_NAME : '')
                . (defined('DB_USER') ? DB_USER : '')
                . (defined('DB_PASSWORD') ? DB_PASSWORD : '')),
        };

        // ── Precompute key prefixes ──────────────────────────────────────
        $pfx = defined('WP_REDIS_PREFIX') ? WP_REDIS_PREFIX : '';
        $multi = function_exists('is_multisite') ? is_multisite() : (defined('MULTISITE') && MULTISITE);
        $this->globalPrefix = $pfx . ($multi ? '' : ($table_prefix ?? ''));
        $this->blogPrefix   = $pfx . ($multi ? ((string) ($blog_id ?? 1)) : ($table_prefix ?? ''));

        // ── Initialize group hash-sets ───────────────────────────────────
        foreach (self::DEFAULT_GLOBAL_GROUPS as $g) {
            $this->globalGroups[$g] = true;
        }
        if (defined('WP_REDIS_GLOBAL_GROUPS') && is_array(WP_REDIS_GLOBAL_GROUPS)) {
            foreach (WP_REDIS_GLOBAL_GROUPS as $g) {
                $this->globalGroups[str_replace(' ', '-', $g)] = true;
            }
        }
        if (defined('WP_REDIS_IGNORED_GROUPS') && is_array(WP_REDIS_IGNORED_GROUPS)) {
            foreach (WP_REDIS_IGNORED_GROUPS as $g) {
                $this->ignoredGroups[str_replace(' ', '-', $g)] = true;
            }
        }

        // ── Detect compression extensions ────────────────────────────────
        $this->compressor = match (true) {
            function_exists('lz4_compress')  => 'lz4',
            function_exists('zstd_compress') => 'zstd',
            default => null,
        };

        // ── Connect to Redis ─────────────────────────────────────────────
        $this->boot();

        // ── Register shutdown for deferred writes ────────────────────────
        if ($this->deferWrites) {
            register_shutdown_function([$this, 'flushDeferred']);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  GET — Hot Path #1
    // ═════════════════════════════════════════════════════════════════════════

    public function get(string $key, string $group = 'default', bool $force = false, ?bool &$found = null): mixed
    {
        $dkey = $this->key($key, $group);

        // ── L1 hit (in-memory) ───────────────────────────────────────────
        if (!$force && isset($this->cache[$dkey])) {
            $found = true;
            ++$this->cache_hits;
            $v = $this->cache[$dkey];
            return is_object($v) ? clone $v : $v;
        }

        // Non-persistent groups: memory-only, no Redis
        if (isset($this->ignoredGroups[$group])) {
            $found = false;
            ++$this->cache_misses;
            return false;
        }

        if (!$this->connected) {
            $found = false;
            ++$this->cache_misses;
            return false;
        }

        // ── L2 fetch (Redis) ─────────────────────────────────────────────
        try {
            $t = hrtime(true);
            $raw = $this->redis->get($dkey);

            if ($raw === false || $raw === null) {
                $found = false;
                ++$this->cache_misses;
                return false;
            }

            $value = $this->decode($raw);
            $this->cache[$dkey] = $value;
            $found = true;
            ++$this->cache_hits;

            return is_object($value) ? clone $value : $value;
        } catch (\Exception $e) {
            $this->fail($e);
            $found = false;
            return false;
        } finally {
            $this->metric($t ?? 0);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  GET MULTIPLE — MGET single round-trip
    // ═════════════════════════════════════════════════════════════════════════

    public function getMultiple(array $keys, string $group = 'default', bool $force = false): array
    {
        if (empty($keys)) {
            return [];
        }

        $results = array_fill_keys($keys, false);

        // Non-persistent groups: memory only
        if (isset($this->ignoredGroups[$group])) {
            if (!$force) {
                foreach ($keys as $k) {
                    $dk = $this->key($k, $group);
                    if (isset($this->cache[$dk])) {
                        $v = $this->cache[$dk];
                        $results[$k] = is_object($v) ? clone $v : $v;
                        ++$this->cache_hits;
                    } else {
                        ++$this->cache_misses;
                    }
                }
            }
            return $results;
        }

        if (!$this->connected) {
            return $results;
        }

        // ── Check L1 cache first, collect misses ─────────────────────────
        $dkeyMap = [];   // original key => derived key
        $missKeys = [];  // original keys that need Redis fetch
        $missDkeys = []; // derived keys for MGET

        foreach ($keys as $k) {
            $dk = $this->key($k, $group);
            $dkeyMap[$k] = $dk;

            if (!$force && isset($this->cache[$dk])) {
                $v = $this->cache[$dk];
                $results[$k] = is_object($v) ? clone $v : $v;
                ++$this->cache_hits;
            } else {
                $missKeys[] = $k;
                $missDkeys[] = $dk;
            }
        }

        if (empty($missKeys)) {
            return $results;
        }

        // ── Single MGET round-trip ───────────────────────────────────────
        try {
            $t = hrtime(true);
            $rawValues = $this->redis->mGet($missDkeys);

            foreach ($missKeys as $i => $k) {
                $raw = $rawValues[$i] ?? false;
                if ($raw !== false && $raw !== null) {
                    $value = $this->decode($raw);
                    $this->cache[$dkeyMap[$k]] = $value;
                    $results[$k] = is_object($value) ? clone $value : $value;
                    ++$this->cache_hits;
                } else {
                    ++$this->cache_misses;
                }
            }

            return $results;
        } catch (\Exception $e) {
            $this->fail($e);
            return $results;
        } finally {
            $this->metric($t ?? 0);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  SET — Hot Path #2
    // ═════════════════════════════════════════════════════════════════════════

    public function set(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
    {
        $dkey = $this->key($key, $group);

        // Non-persistent groups: in-memory only (WP 6.9 compliance)
        if (isset($this->ignoredGroups[$group])) {
            $this->cache[$dkey] = is_object($value) ? clone $value : $value;
            return true;
        }

        if (!$this->connected) {
            return false;
        }

        $expiration = $this->ttl($expiration);
        $encoded = $this->encode($value);

        // ── Deferred write mode: batch at shutdown ───────────────────────
        if ($this->deferWrites) {
            $this->cache[$dkey] = is_object($value) ? clone $value : $value;
            $this->deferred[$dkey] = ['value' => $encoded, 'expiration' => $expiration];
            return true;
        }

        // ── Immediate write ──────────────────────────────────────────────
        try {
            $t = hrtime(true);

            $result = $expiration > 0
                ? $this->redis->setex($dkey, $expiration, $encoded)
                : $this->redis->set($dkey, $encoded);

            if ($result) {
                $this->cache[$dkey] = is_object($value) ? clone $value : $value;
            }

            return (bool) $result;
        } catch (\Exception $e) {
            $this->fail($e);
            return false;
        } finally {
            $this->metric($t ?? 0);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  SET MULTIPLE — Lua MSET + per-key EXPIRE in single round-trip
    // ═════════════════════════════════════════════════════════════════════════

    public function setMultiple(array $data, string $group = 'default', int $expiration = 0): array
    {
        if (empty($data)) {
            return [];
        }

        $resultKeys = array_keys($data);

        // Non-persistent groups: memory only
        if (isset($this->ignoredGroups[$group])) {
            foreach ($data as $k => $v) {
                $dk = $this->key($k, $group);
                $this->cache[$dk] = is_object($v) ? clone $v : $v;
            }
            return array_fill_keys($resultKeys, true);
        }

        if (!$this->connected) {
            return array_fill_keys($resultKeys, false);
        }

        $expiration = $this->ttl($expiration);

        try {
            $t = hrtime(true);

            // Build KEYS and ARGV arrays for Lua MSET script
            $luaKeys = [];
            $luaArgs = [];
            $originals = [];

            foreach ($data as $k => $v) {
                $dk = $this->key($k, $group);
                $luaKeys[] = $dk;
                $luaArgs[] = $this->encode($v);
                $originals[$dk] = is_object($v) ? clone $v : $v;
            }

            if ($expiration > 0 && $this->msetSHA !== '') {
                // Lua script: MSET all keys then EXPIRE each — 1 round trip
                $this->redis->evalSha(
                    $this->msetSHA,
                    array_merge($luaKeys, $luaArgs, [(string) $expiration]),
                    count($luaKeys),
                );
            } else {
                // No expiration: use native MSET
                $pairs = [];
                foreach ($luaKeys as $i => $dk) {
                    $pairs[$dk] = $luaArgs[$i];
                }
                $this->redis->mSet($pairs);

                if ($expiration > 0) {
                    // Fallback: pipeline EXPIRE if Lua script not loaded
                    $pipe = $this->redis->pipeline();
                    foreach ($luaKeys as $dk) {
                        $pipe->expire($dk, $expiration);
                    }
                    $pipe->exec();
                }
            }

            // Bulk update L1 cache
            foreach ($originals as $dk => $v) {
                $this->cache[$dk] = $v;
            }

            return array_fill_keys($resultKeys, true);
        } catch (\Exception $e) {
            $this->fail($e);
            return array_fill_keys($resultKeys, false);
        } finally {
            $this->metric($t ?? 0);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  ADD — Atomic SET NX + EX (single round-trip)
    // ═════════════════════════════════════════════════════════════════════════

    public function add(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
    {
        if (function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition()) {
            return false;
        }

        $dkey = $this->key($key, $group);

        // Already exists in L1 → not added
        if (isset($this->cache[$dkey])) {
            return false;
        }

        // Non-persistent groups: memory only
        if (isset($this->ignoredGroups[$group])) {
            $this->cache[$dkey] = is_object($value) ? clone $value : $value;
            return true;
        }

        if (!$this->connected) {
            return false;
        }

        try {
            $t = hrtime(true);
            $expiration = $this->ttl($expiration);
            $encoded = $this->encode($value);

            // ── Atomic SET NX EX — single command, single round-trip ─────
            $opts = ['NX'];
            if ($expiration > 0) {
                $opts['EX'] = $expiration;
            }
            $result = $this->redis->set($dkey, $encoded, $opts);

            if ($result) {
                $this->cache[$dkey] = is_object($value) ? clone $value : $value;
            }

            return (bool) $result;
        } catch (\Exception $e) {
            $this->fail($e);
            return false;
        } finally {
            $this->metric($t ?? 0);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  REPLACE — Atomic SET XX + EX (single round-trip)
    // ═════════════════════════════════════════════════════════════════════════

    public function replace(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
    {
        $dkey = $this->key($key, $group);

        if (!isset($this->cache[$dkey])) {
            return false;
        }

        if (isset($this->ignoredGroups[$group])) {
            $this->cache[$dkey] = is_object($value) ? clone $value : $value;
            return true;
        }

        if (!$this->connected) {
            return false;
        }

        try {
            $t = hrtime(true);
            $expiration = $this->ttl($expiration);
            $encoded = $this->encode($value);

            // ── Atomic SET XX EX — single command, single round-trip ─────
            $opts = ['XX'];
            if ($expiration > 0) {
                $opts['EX'] = $expiration;
            }
            $result = $this->redis->set($dkey, $encoded, $opts);

            if ($result) {
                $this->cache[$dkey] = is_object($value) ? clone $value : $value;
            }

            return (bool) $result;
        } catch (\Exception $e) {
            $this->fail($e);
            return false;
        } finally {
            $this->metric($t ?? 0);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  DELETE — UNLINK (non-blocking)
    // ═════════════════════════════════════════════════════════════════════════

    public function delete(string $key, string $group = 'default'): bool
    {
        $dkey = $this->key($key, $group);
        unset($this->cache[$dkey], $this->deferred[$dkey]);

        if (isset($this->ignoredGroups[$group]) || !$this->connected) {
            return true;
        }

        try {
            $t = hrtime(true);
            // UNLINK: async free — Redis reclaims memory in background thread
            return (bool) $this->redis->unlink($dkey);
        } catch (\Exception $e) {
            $this->fail($e);
            return false;
        } finally {
            $this->metric($t ?? 0);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  DELETE MULTIPLE — Batched UNLINK
    // ═════════════════════════════════════════════════════════════════════════

    public function deleteMultiple(array $keys, string $group = 'default'): array
    {
        if (empty($keys)) {
            return [];
        }

        $dkeys = [];
        foreach ($keys as $k) {
            $dk = $this->key($k, $group);
            unset($this->cache[$dk], $this->deferred[$dk]);
            $dkeys[] = $dk;
        }

        if (isset($this->ignoredGroups[$group]) || !$this->connected) {
            return array_fill_keys($keys, true);
        }

        try {
            $t = hrtime(true);
            // Batch UNLINK — single command for all keys
            $this->redis->unlink(...$dkeys);
            return array_fill_keys($keys, true);
        } catch (\Exception $e) {
            $this->fail($e);
            return array_fill_keys($keys, false);
        } finally {
            $this->metric($t ?? 0);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  INCREMENT / DECREMENT — Atomic INCRBY / DECRBY
    // ═════════════════════════════════════════════════════════════════════════

    public function increment(string $key, int $offset = 1, string $group = 'default'): int|false
    {
        if (isset($this->ignoredGroups[$group]) || !$this->connected) {
            return false;
        }

        try {
            $t = hrtime(true);
            $dk = $this->key($key, $group);
            $val = $this->redis->incrBy($dk, $offset);
            $this->cache[$dk] = $val;
            return $val;
        } catch (\Exception $e) {
            $this->fail($e);
            return false;
        } finally {
            $this->metric($t ?? 0);
        }
    }

    public function decrement(string $key, int $offset = 1, string $group = 'default'): int|false
    {
        if (isset($this->ignoredGroups[$group]) || !$this->connected) {
            return false;
        }

        try {
            $t = hrtime(true);
            $dk = $this->key($key, $group);
            $val = $this->redis->decrBy($dk, $offset);
            $this->cache[$dk] = $val;
            return $val;
        } catch (\Exception $e) {
            $this->fail($e);
            return false;
        } finally {
            $this->metric($t ?? 0);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  FLUSH — Full, Runtime, Group
    // ═════════════════════════════════════════════════════════════════════════

    public function flush(): bool
    {
        $this->cache = [];
        $this->deferred = [];

        if (!$this->connected) {
            return false;
        }

        try {
            $t = hrtime(true);

            if (defined('WP_REDIS_SELECTIVE_FLUSH') && WP_REDIS_SELECTIVE_FLUSH && $this->flushSHA !== '') {
                return (bool) $this->redis->evalSha($this->flushSHA, [$this->globalPrefix . '*'], 1);
            }

            return $this->redis->flushDb();
        } catch (\Exception $e) {
            $this->fail($e);
            return false;
        } finally {
            $this->metric($t ?? 0);
        }
    }

    /**
     * Flushes only the in-memory runtime cache without touching Redis.
     * Required by WordPress 6.9's flush_runtime feature.
     */
    public function flushRuntime(): bool
    {
        $this->cache = [];
        return true;
    }

    /**
     * Flushes all keys in a specific cache group using optimized Lua + UNLINK.
     */
    public function flush_group(string $group): bool
    {
        if (isset($this->ignoredGroups[$group]) || !$this->connected) {
            return false;
        }

        if (defined('WP_REDIS_DISABLE_GROUP_FLUSH') && WP_REDIS_DISABLE_GROUP_FLUSH) {
            return $this->flush();
        }

        try {
            $t = hrtime(true);
            $prefix = isset($this->globalGroups[$group]) ? $this->globalPrefix : $this->blogPrefix;
            $pattern = $prefix . str_replace(' ', '-', $group) . ':*';

            // Clear matching keys from L1
            $groupToken = ':' . $group . ':';
            $groupStart = $group . ':';
            foreach ($this->cache as $k => $_) {
                if (str_starts_with($k, $groupStart) || str_contains($k, $groupToken)) {
                    unset($this->cache[$k]);
                }
            }

            if ($this->flushSHA !== '') {
                return (bool) $this->redis->evalSha($this->flushSHA, [$pattern], 1);
            }

            return false;
        } catch (\Exception $e) {
            $this->fail($e);
            return false;
        } finally {
            $this->metric($t ?? 0);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  GROUP MANAGEMENT
    // ═════════════════════════════════════════════════════════════════════════

    public function addGlobalGroups(string|array $groups): void
    {
        foreach ((array) $groups as $g) {
            $this->globalGroups[str_replace(' ', '-', $g)] = true;
        }
    }

    public function addNonPersistentGroups(string|array $groups): void
    {
        $groups = (array) $groups;
        if (function_exists('apply_filters')) {
            $groups = apply_filters('redis_cache_add_non_persistent_groups', $groups);
        }
        foreach ($groups as $g) {
            $this->ignoredGroups[str_replace(' ', '-', $g)] = true;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  MULTISITE — Blog Switching
    // ═════════════════════════════════════════════════════════════════════════

    public function switchToBlog(int $blog_id): bool
    {
        if (!is_multisite()) {
            return false;
        }

        $this->cache = [];
        $this->blogPrefix = (defined('WP_REDIS_PREFIX') ? WP_REDIS_PREFIX : '') . (string) $blog_id;

        if (function_exists('do_action')) {
            do_action('redis_object_cache_switch_blog', $blog_id);
        }

        return true;
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  CONNECTION CLOSE + DEFERRED FLUSH
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Flushes all deferred writes to Redis in a single pipeline.
     * Called automatically at shutdown when WP_REDIS_DEFERRED_WRITES is on.
     */
    public function flushDeferred(): void
    {
        if (empty($this->deferred) || !$this->connected) {
            return;
        }

        try {
            $pipe = $this->redis->pipeline();

            foreach ($this->deferred as $dk => $item) {
                if ($item['expiration'] > 0) {
                    $pipe->setex($dk, $item['expiration'], $item['value']);
                } else {
                    $pipe->set($dk, $item['value']);
                }
            }

            $pipe->exec();
            $this->deferred = [];
        } catch (\Exception $e) {
            $this->fail($e);
        }
    }

    /**
     * Closes the Redis connection cleanly.
     */
    public function close(): bool
    {
        // Flush any deferred writes before closing
        $this->flushDeferred();

        if ($this->redis instanceof \Redis && $this->connected) {
            try {
                $this->redis->close();
            } catch (\Exception) {
                // Ignore close errors
            }
            $this->connected = false;
        }

        return true;
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  DIAGNOSTICS — Public Accessors
    // ═════════════════════════════════════════════════════════════════════════

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getMetrics(): array
    {
        return [
            'hits'   => $this->cache_hits,
            'misses' => $this->cache_misses,
            'calls'  => $this->cacheCalls,
            'time'   => round($this->cacheTime * 1000, 3), // ms
        ];
    }

    public function getRedisInfo(): array|false
    {
        if (!$this->connected) {
            return false;
        }
        try {
            return $this->redis->info();
        } catch (\Exception) {
            return false;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  PRIVATE — Key Generation (zero-overhead, inlined)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Generates the Redis key.
     * No caching layer — direct concatenation is faster than LRU overhead.
     */
    private function key(string $key, string $group): string
    {
        $group = $group ?: 'default';
        $prefix = isset($this->globalGroups[$group]) ? $this->globalPrefix : $this->blogPrefix;
        return $prefix . str_replace(' ', '-', $group) . ':' . str_replace(' ', '-', $key);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  PRIVATE — Serialization / Deserialization
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Encodes a PHP value for Redis storage.
     *
     * Priority chain:
     * 1. Native PhpRedis serializer (igbinary/msgpack) — fastest, handled in C
     * 2. Primitives (int/string/bool) — stored raw
     * 3. Complex types — PHP serialize + HMAC sign + optional compression
     */
    private function encode(mixed $value): mixed
    {
        // If PhpRedis handles serialization natively, bypass everything
        if ($this->nativeSerializer) {
            return $value;
        }

        // Primitives: store directly
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $value;
        }

        // Complex type: serialize + HMAC sign
        $serialized = serialize($value);
        $hash = hash_hmac('xxh128', $serialized, $this->salt);
        $payload = self::SIGN_PREFIX . $hash . ':' . $serialized;

        // Compress if beneficial
        if ($this->compressor !== null && strlen($payload) >= self::COMPRESS_THRESHOLD) {
            $compressed = match ($this->compressor) {
                'lz4'  => lz4_compress($payload),
                'zstd' => zstd_compress($payload),
                default => false,
            };

            if ($compressed !== false && strlen($compressed) < strlen($payload)) {
                return self::COMPRESS_PREFIX . $this->compressor[0] . $compressed;
            }
        }

        return $payload;
    }

    /**
     * Decodes a Redis value back to PHP.
     */
    private function decode(mixed $value): mixed
    {
        if ($this->nativeSerializer) {
            return $value;
        }

        if (!is_string($value) || strlen($value) < 4) {
            return $value;
        }

        // ── Decompress if needed ─────────────────────────────────────────
        if (str_starts_with($value, self::COMPRESS_PREFIX)) {
            $algo = $value[2]; // 'l' for lz4, 'z' for zstd
            $compressed = substr($value, 3);
            $value = match ($algo) {
                'l' => function_exists('lz4_uncompress') ? lz4_uncompress($compressed) : false,
                'z' => function_exists('zstd_uncompress') ? zstd_uncompress($compressed) : false,
                default => false,
            };
            if ($value === false) {
                return false; // Corrupted or unsupported
            }
        }

        // ── HMAC-signed payload ──────────────────────────────────────────
        if (str_starts_with($value, self::SIGN_PREFIX)) {
            $colonPos = strpos($value, ':', 2);
            if ($colonPos === false) {
                return false;
            }

            $hash = substr($value, 2, $colonPos - 2);
            $payload = substr($value, $colonPos + 1);
            $calc = hash_hmac('xxh128', $payload, $this->salt);

            if (!hash_equals($hash, $calc)) {
                return false; // Tampered
            }

            $unserialized = @unserialize($payload);
            return ($unserialized !== false || $payload === 'b:0;') ? $unserialized : false;
        }

        // ── Reject unsigned legacy serialized data (Object Injection defense) ──
        if (preg_match('/^[absiOCrdN]:\d+/', $value)) {
            return false;
        }

        return $value;
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  PRIVATE — TTL Validation
    // ═════════════════════════════════════════════════════════════════════════

    private function ttl(int $expiration): int
    {
        if ($expiration < 0) {
            return 0;
        }
        if (defined('WP_REDIS_MAXTTL') && WP_REDIS_MAXTTL > 0 && ($expiration === 0 || $expiration > WP_REDIS_MAXTTL)) {
            return WP_REDIS_MAXTTL;
        }
        return $expiration;
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  PRIVATE — Metrics
    // ═════════════════════════════════════════════════════════════════════════

    private function metric(int $startNs): void
    {
        ++$this->cacheCalls;
        if ($startNs > 0) {
            $this->cacheTime += (hrtime(true) - $startNs) / 1e9;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  PRIVATE — Error Handling
    // ═════════════════════════════════════════════════════════════════════════

    private function fail(\Exception $e, string $ctx = ''): void
    {
        $this->connected = false;
        $msg = $ctx ? "[{$ctx}] {$e->getMessage()}" : $e->getMessage();
        $this->errors[] = $msg;

        if (function_exists('do_action')) {
            do_action('redis_object_cache_error', $e, $msg);
        }

        if (!$this->failGracefully && $ctx !== 'boot') {
            throw $e;
        }

        error_log("WPS-Cache Redis: {$msg}");
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  PRIVATE — Redis Bootstrap
    // ═════════════════════════════════════════════════════════════════════════

    private function boot(): void
    {
        if (!class_exists('Redis')) {
            $msg = 'PhpRedis extension not found. Object cache disabled.';
            if ($this->failGracefully) {
                error_log("WPS-Cache: {$msg}");
            } else {
                $this->fail(new \RuntimeException($msg), 'boot');
            }
            return;
        }

        try {
            $cfg = $this->config();
            $this->redis = new \Redis();

            // ── Connect ──────────────────────────────────────────────────
            if (($cfg['scheme'] ?? 'tcp') === 'unix') {
                $this->redis->connect($cfg['path'] ?? '/var/run/redis/redis.sock');
            } else {
                $this->redis->connect(
                    $cfg['host'],
                    (int) $cfg['port'],
                    (float) $cfg['timeout'],
                    null,                         // persistent_id
                    (int) $cfg['retry_interval'],
                    (float) $cfg['read_timeout'], // read_timeout
                );
            }

            // ── Auth ─────────────────────────────────────────────────────
            if (isset($cfg['password']) && $cfg['password'] !== '') {
                if (isset($cfg['username']) && $cfg['username'] !== '') {
                    $this->redis->auth([$cfg['username'], $cfg['password']]);
                } else {
                    $this->redis->auth($cfg['password']);
                }
            }

            // ── Select database ──────────────────────────────────────────
            if (((int) $cfg['database']) !== 0) {
                $this->redis->select((int) $cfg['database']);
            }

            // ── PhpRedis options for maximum throughput ───────────────────

            // Read timeout
            $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, (float) $cfg['read_timeout']);

            // TCP_NODELAY — disable Nagle's algorithm for lower latency
            if (defined('\\Redis::OPT_TCP_NODELAY')) {
                $this->redis->setOption(\Redis::OPT_TCP_NODELAY, 1);
            }

            // TCP keepalive — prevent dead connections
            if (defined('\\Redis::OPT_TCP_KEEPALIVE')) {
                $this->redis->setOption(\Redis::OPT_TCP_KEEPALIVE, 60);
            }

            // ── Serializer: prefer igbinary > msgpack > PHP ──────────────
            if (defined('\\Redis::SERIALIZER_IGBINARY') && extension_loaded('igbinary')) {
                $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_IGBINARY);
                $this->nativeSerializer = true;
            } elseif (defined('\\Redis::SERIALIZER_MSGPACK') && extension_loaded('msgpack')) {
                $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_MSGPACK);
                $this->nativeSerializer = true;
            }
            // else: keep SERIALIZER_NONE — we handle serialization in PHP with HMAC signing

            // ── Verify connection ────────────────────────────────────────
            $this->redis->ping();
            $this->connected = true;

            // ── Enable Redis 8 client-side tracking if available ──────────
            $this->enableTracking();

            // ── Load Lua scripts ─────────────────────────────────────────
            $this->loadScripts();

            // ── Preload critical WordPress groups ────────────────────────
            $this->preload();

        } catch (\Exception $e) {
            $this->fail($e, 'connection');
        }
    }

    /**
     * Builds the Redis connection configuration from WP constants.
     */
    private function config(): array
    {
        $defaults = [
            'scheme'         => 'tcp',
            'host'           => '127.0.0.1',
            'port'           => 6379,
            'timeout'        => 1.0,
            'read_timeout'   => 1.0,
            'retry_interval' => 0,
            'database'       => 0,
        ];

        $cfg = [];
        foreach ($defaults as $k => $v) {
            $const = 'WP_REDIS_' . strtoupper($k);
            $cfg[$k] = defined($const) ? constant($const) : $v;
        }

        if (defined('WP_REDIS_PASSWORD')) {
            $cfg['password'] = WP_REDIS_PASSWORD;
        }
        if (defined('WP_REDIS_USERNAME')) {
            $cfg['username'] = WP_REDIS_USERNAME;
        }

        return $cfg;
    }

    /**
     * Enables Redis 8 client-side tracking for server-assisted invalidation.
     *
     * When tracking is enabled, Redis sends invalidation messages when
     * tracked keys are modified by other clients, keeping our L1 cache
     * automatically in sync.
     */
    private function enableTracking(): void
    {
        if (!defined('WP_REDIS_CLIENT_TRACKING') || !WP_REDIS_CLIENT_TRACKING) {
            return;
        }

        try {
            // CLIENT TRACKING ON BCAST — broadcast mode for all key prefixes
            $this->redis->rawCommand('CLIENT', 'TRACKING', 'ON', 'BCAST');
        } catch (\Exception) {
            // Not supported on this Redis version — silently ignore
        }
    }

    /**
     * Loads Lua scripts into Redis and caches their SHA hashes.
     */
    private function loadScripts(): void
    {
        try {
            // ── Selective flush: SCAN + UNLINK with high COUNT ────────────
            $this->flushSHA = $this->redis->script('load',
                'local cursor = "0" '
                . 'local count = 0 '
                . 'repeat '
                . '  local result = redis.call("SCAN", cursor, "MATCH", ARGV[1], "COUNT", ' . self::SCAN_COUNT . ') '
                . '  cursor = result[1] '
                . '  local keys = result[2] '
                . '  if #keys > 0 then '
                . '    count = count + redis.call("UNLINK", unpack(keys)) '
                . '  end '
                . 'until cursor == "0" '
                . 'return count'
            );

            // ── Lua MSET + EXPIRE: set N keys with TTL in 1 round trip ───
            // KEYS = key1..keyN, ARGV = val1..valN, ttl
            $this->msetSHA = $this->redis->script('load',
                'local n = #KEYS '
                . 'local ttl = tonumber(ARGV[n + 1]) '
                . 'for i = 1, n do '
                . '  redis.call("SET", KEYS[i], ARGV[i]) '
                . '  if ttl > 0 then '
                . '    redis.call("EXPIRE", KEYS[i], ttl) '
                . '  end '
                . 'end '
                . 'return n'
            );
        } catch (\Exception) {
            // Scripting disabled on server — flush_group / mset-with-ttl
            // will fall back to pipeline mode
        }
    }

    /**
     * Preloads critical WordPress groups to eliminate per-key round trips.
     *
     * WordPress loads upwards of 40+ options on every pageload. By preloading
     * them in a single pipeline on init, we save ~30-40 individual GET calls.
     */
    private function preload(): void
    {
        if (defined('WP_REDIS_DISABLE_PRELOAD') && WP_REDIS_DISABLE_PRELOAD) {
            return;
        }

        // Only preload on the first request (not on blog switch)
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        // Preload alloptions if a key is known
        $alloptions_key = $this->key('alloptions', 'options');
        try {
            $raw = $this->redis->get($alloptions_key);
            if ($raw !== false && $raw !== null) {
                $this->cache[$alloptions_key] = $this->decode($raw);
                ++$this->cache_hits;
            }
        } catch (\Exception) {
            // Non-critical — continue without preload
        }
    }
}

endif;
