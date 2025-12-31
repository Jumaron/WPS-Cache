<?php

/**
 * WordPress Redis Object Cache Backend
 *
 * @package WordPress
 * @subpackage Cache
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Only load if Redis not disabled
if (!defined('WP_REDIS_DISABLED') || !WP_REDIS_DISABLED):

    /**
     * Determines whether the object cache implementation supports a particular feature.
     *
     * @param string $feature The feature to check for.
     * @return bool True if the feature is supported, false otherwise.
     */
    function wp_cache_supports(string $feature): bool
    {
        $supported = [
            'add_multiple',
            'set_multiple',
            'get_multiple',
            'delete_multiple',
            'flush_runtime',
            'flush_group'
        ];
        return in_array($feature, $supported, true);
    }

    /**
     * Initializes the object cache.
     *
     * Loads environment configurations, handles backward compatibility,
     * and initializes the WP_Object_Cache instance if needed.
     *
     * @global WP_Object_Cache $wp_object_cache The WordPress object cache instance.
     */
    function wp_cache_init(): void
    {
        global $wp_object_cache;

        // Optimized environment variable loading (similar to v1, but with type handling)
        $envVars = [
            'WP_REDIS_PREFIX' => ['type' => 'string'],
            'WP_REDIS_SELECTIVE_FLUSH' => ['type' => 'bool'],
            'WP_REDIS_MAXTTL' => ['type' => 'int'], // Add MAXTTL support
        ];

        foreach ($envVars as $env => $config) {
            if (!defined($env) && ($value = getenv($env))) {
                $value = match ($config['type']) {
                    'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    'int' => (int)$value,
                    default => $value,
                };
                define($env, $value);
            }
        }

        // Handle backward compatibility
        if (defined('WP_CACHE_KEY_SALT') && !defined('WP_REDIS_PREFIX')) {
            define('WP_REDIS_PREFIX', WP_CACHE_KEY_SALT);
        }

        if (!($wp_object_cache instanceof WP_Object_Cache)) {
            $failGracefully = defined('WP_REDIS_GRACEFUL') && WP_REDIS_GRACEFUL;
            $wp_object_cache = new WP_Object_Cache($failGracefully);
        }
    }

    /**
     * Adds a value to the cache.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to add.
     * @param string $group The cache group. Default 'default'.
     * @param int $expiration The expiration time in seconds. Default 0 (no expiration).
     * @return bool True if the value was added, false if it already exists.
     */
    function wp_cache_add(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->add($key, $value, $group, $expiration);
    }

    /**
     * Replaces a value in the cache.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to set.
     * @param string $group The cache group. Default 'default'.
     * @param int $expiration The expiration time in seconds. Default 0 (no expiration).
     * @return bool True if the value was replaced, false on failure.
     */
    function wp_cache_replace(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->replace($key, $value, $group, $expiration);
    }

    /**
     * Sets a value in the cache.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to set.
     * @param string $group The cache group. Default 'default'.
     * @param int $expiration The expiration time in seconds. Default 0 (no expiration).
     * @return bool True on success, false on failure.
     */
    function wp_cache_set(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->set($key, $value, $group, $expiration);
    }

    /**
     * Retrieves a value from the cache.
     *
     * @param string $key The cache key.
     * @param string $group The cache group. Default 'default'.
     * @param bool $force Whether to force an update of the local cache. Default false.
     * @param bool|null $found Optional. Whether the key was found in the cache. Default null.
     * @return mixed The cache value if found, false otherwise.
     */
    function wp_cache_get(string $key, string $group = 'default', bool $force = false, ?bool &$found = null): mixed
    {
        global $wp_object_cache;
        return $wp_object_cache->get($key, $group, $force, $found);
    }

    /**
     * Deletes a value from the cache.
     *
     * @param string $key The cache key.
     * @param string $group The cache group. Default empty.
     * @return bool True on success, false on failure.
     */
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->delete($key, $group);
    }

    /**
     * Flushes the entire cache.
     *
     * @return bool True on success, false on failure.
     */
    function wp_cache_flush(): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->flush();
    }

    /**
     * Retrieves multiple values from the cache in one call.
     *
     * @param array $keys Array of cache keys to retrieve.
     * @param string $group Optional. The cache group. Default 'default'.
     * @param bool $force Optional. Whether to force an update of the local cache. Default false.
     * @return array Array of values that were found.
     */
    function wp_cache_get_multiple(array $keys, string $group = 'default', bool $force = false): array
    {
        global $wp_object_cache;
        return $wp_object_cache->getMultiple($keys, $group, $force);
    }

    /**
     * Sets multiple values to the cache in one call.
     *
     * @param array $data Array of key => value pairs to store.
     * @param string $group Optional. The cache group. Default 'default'.
     * @param int $expire Optional. The expiration time, in seconds. Default 0 (no expiration).
     * @return array Array of success/failure for each key.
     */
    function wp_cache_set_multiple(array $data, string $group = 'default', int $expire = 0): array
    {
        global $wp_object_cache;
        return $wp_object_cache->setMultiple($data, $group, $expire);
    }

    /**
     * Deletes multiple values from the cache in one call.
     *
     * @param array $keys Array of cache keys to delete.
     * @param string $group Optional. The cache group. Default empty.
     * @return array Array of success/failure for each key.
     */
    function wp_cache_delete_multiple(array $keys, string $group = ''): array
    {
        global $wp_object_cache;
        return $wp_object_cache->deleteMultiple($keys, $group);
    }

    /**
     * Increments numeric cache item's value.
     *
     * @param string $key The cache key to increment.
     * @param int $offset Optional. The amount by which to increment. Default 1.
     * @param string $group Optional. The cache group. Default empty.
     * @return int|false The updated value on success, false on failure.
     */
    function wp_cache_incr(string $key, int $offset = 1, string $group = ''): int|false
    {
        global $wp_object_cache;
        return $wp_object_cache->increment($key, $offset, $group);
    }

    /**
     * Decrements numeric cache item's value.
     *
     * @param string $key The cache key to decrement.
     * @param int $offset Optional. The amount by which to decrement. Default 1.
     * @param string $group Optional. The cache group. Default empty.
     * @return int|false The updated value on success, false on failure.
     */
    function wp_cache_decr(string $key, int $offset = 1, string $group = ''): int|false
    {
        global $wp_object_cache;
        return $wp_object_cache->decrement($key, $offset, $group);
    }

    /**
     * Switches the internal blog ID.
     *
     * This changes the blog id used to create keys in blog specific groups.
     *
     * @param int $blog_id Blog ID to switch to.
     * @return bool True on success, false on failure.
     */
    function wp_cache_switch_to_blog(int $blog_id): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->switchToBlog($blog_id);
    }

    /**
     * Adds a group or set of groups to the list of global groups.
     *
     * @param string|array $groups A group or an array of groups to add.
     */
    function wp_cache_add_global_groups(string|array $groups): void
    {
        global $wp_object_cache;
        $wp_object_cache->addGlobalGroups($groups);
    }

    /**
     * Adds a group or set of groups to the list of non-persistent groups.
     *
     * @param string|array $groups A group or an array of groups to add.
     */
    function wp_cache_add_non_persistent_groups(string|array $groups): void
    {
        global $wp_object_cache;
        $wp_object_cache->addNonPersistentGroups($groups);
    }

    /**
     * Closes the cache.
     *
     * This function has ceased to do anything since WordPress 2.5.
     * The functionality was removed along with the rest of the persistent cache.
     * However, this does ensure that the object cache is reset at the end of the request.
     *
     * @return bool Always returns true.
     */
    function wp_cache_close(): bool
    {
        global $wp_object_cache;
        return method_exists($wp_object_cache, 'close') ? $wp_object_cache->close() : true;
    }

    /**
     * Core WordPress object cache implementation using Redis.
     */
    class WP_Object_Cache
    {
        /**
         * Maximum number of keys to store in the key cache
         */
        private const MAX_KEY_CACHE_SIZE = 1000;

        /**
         * The Redis client instance
         *
         * @var \Redis|\Predis\Client|null
         */
        private \Redis|\Predis\Client|null $redis;

        /**
         * Whether the Redis connection is established
         */
        private bool $redisConnected = false;

        /**
         * Redis server version
         */
        private ?string $redisVersion = null;

        /**
         * Local in-memory cache
         */
        private array $cache = [];

        /**
         * Cache for derived keys (LRU)
         */
        private static array $keyCache = [];

        /**
         * Array of recorded errors
         */
        private array $errors = [];

        /**
         * Count of cache hits
         */
        private int $cacheHits = 0;

        /**
         * Count of cache misses
         */
        private int $cacheMisses = 0;

        /**
         * Total time spent on cache operations
         */
        private float $cacheTime = 0.0;

        /**
         * Total number of cache calls
         */
        private int $cacheCalls = 0;

        /**
         * SHA1 hash of the flush script
         */
        private string $flushScriptSHA1;

        /**
         * Default global cache groups
         */
        private array $globalGroups = [
            'blog-details',
            'blog-id-cache',
            'blog-lookup',
            'global-posts',
            'networks',
            'rss',
            'sites',
            'site-details',
            'site-lookup',
            'site-options',
            'site-transient',
            'users',
            'useremail',
            'userlogins',
            'usermeta',
            'user_meta',
            'userslugs',
        ];

        /**
         * Non-persistent cache groups
         */
        private array $ignoredGroups = [];

        /**
         * Cached group types for faster lookups
         */
        private array $groupTypes = [];

        /**
         * The blog prefix
         */
        private string $blogKeyPrefix;

        /**
         * The global prefix
         */
        private string $globalKeyPrefix;

        /**
         * Whether to fail gracefully
         */
        private bool $failGracefully;

        /**
         * Secret salt for HMAC signing
         */
        private string $salt;

        /**
         * Constructor.
         *
         * @param bool $failGracefully Whether to fail gracefully on Redis connection errors
         */
        public function __construct(bool $failGracefully = true)
        {
            global $blog_id, $table_prefix;

            $this->failGracefully = $failGracefully;

            // Initialize Salt for HMAC
            // Sentinel: Try standard keys in order of preference
            if (defined('WP_REDIS_SIGNING_KEY')) {
                $this->salt = WP_REDIS_SIGNING_KEY;
            } elseif (defined('WP_CACHE_KEY_SALT')) {
                $this->salt = WP_CACHE_KEY_SALT;
            } elseif (defined('SECURE_AUTH_KEY')) {
                $this->salt = SECURE_AUTH_KEY;
            } elseif (defined('LOGGED_IN_KEY')) {
                $this->salt = LOGGED_IN_KEY;
            } elseif (defined('NONCE_KEY')) {
                $this->salt = NONCE_KEY;
            } else {
                // Last resort: uniqid makes cache invalid on every request (safe but slow)
                $this->salt = uniqid('wpsc_salt_', true);
            }

            // Pre-compute key prefixes
            $prefix = defined('WP_REDIS_PREFIX') ? WP_REDIS_PREFIX : '';
            $this->globalKeyPrefix = $prefix . (is_multisite() ? '' : $table_prefix);
            $this->blogKeyPrefix = $prefix . (is_multisite() ? (string)$blog_id : $table_prefix);

            // Initialize cache configuration
            $this->setupGroups();
            $this->initializeRedis();

            if ($this->redisConnected) {
                $this->flushScriptSHA1 = $this->redis->script('load', $this->getSelectiveFlushScript());
            }
        }

        /**
         * Retrieves a value from the cache.
         *
         * @param string $key The cache key.
         * @param string $group The cache group.
         * @param bool $force Whether to force a cache refresh.
         * @param bool|null $found Optional. Whether the key was found in the cache.
         * @return mixed The cached value or false if not found.
         */
        public function get(string $key, string $group = 'default', bool $force = false, ?bool &$found = null): mixed
        {
            $derivedKey = $this->buildKey($key, $group);

            // Check local cache first
            if (!$force && isset($this->cache[$derivedKey])) {
                $found = true;
                $this->cacheHits++;
                return is_object($this->cache[$derivedKey])
                    ? clone $this->cache[$derivedKey]
                    : $this->cache[$derivedKey];
            }

            if (!$this->redisConnected || $this->isIgnoredGroup($group)) {
                $found = false;
                $this->cacheMisses++;
                return false;
            }

            try {
                $startTime = microtime(true);
                $value = $this->redis->get($derivedKey);

                if ($value === null || $value === false) {
                    $found = false;
                    $this->cacheMisses++;
                    return false;
                }

                $value = $this->maybeUnserialize($value);
                $this->cache[$derivedKey] = $value;

                $found = true;
                $this->cacheHits++;

                return is_object($value) ? clone $value : $value;
            } catch (Exception $e) {
                $this->handleException($e);
                return false;
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Retrieves multiple values from the cache using pipelining.
         *
         * @param array $keys Array of cache keys.
         * @param string $group The cache group.
         * @param bool $force Whether to force a cache refresh.
         * @return array Array of values, with false for keys not found.
         */
        public function getMultiple(array $keys, string $group = 'default', bool $force = false): array
        {
            if (!$this->redisConnected || $this->isIgnoredGroup($group) || empty($keys)) {
                return array_fill_keys($keys, false);
            }

            $count = count($keys);
            $results = array_fill_keys($keys, false);
            $derivedKeys = [];
            $missedIndexes = [];
            $missedKeys = [];

            if (!$force) {
                foreach ($keys as $index => $key) {
                    $derivedKey = $this->buildKey($key, $group);
                    $derivedKeys[$key] = $derivedKey;

                    // Use array_key_exists for better performance with null values
                    if (array_key_exists($derivedKey, $this->cache)) {
                        $value = $this->cache[$derivedKey];
                        $results[$key] = is_object($value) ? clone $value : $value;
                        ++$this->cacheHits;
                    } else {
                        $missedIndexes[] = $index;
                        $missedKeys[] = $key;
                    }
                }
            } else {
                // When forced, all keys are considered missed
                foreach ($keys as $index => $key) {
                    $derivedKeys[$key] = $this->buildKey($key, $group);
                    $missedIndexes[] = $index;
                    $missedKeys[] = $key;
                }
            }

            if (empty($missedKeys)) {
                return $results;
            }

            try {
                $startTime = microtime(true);

                $pipe = $this->redis->pipeline();
                foreach ($missedKeys as $key) {
                    $pipe->get($derivedKeys[$key]);
                }

                $pipelineResults = $pipe->exec();

                foreach ($missedKeys as $index => $key) {
                    $value = $pipelineResults[$index];

                    if ($value !== null && $value !== false) {

                        if (is_string($value) && strlen($value) > 4 && preg_match('/^[absiOCrdN]:[0-9]+/', $value)) {
                            $value = $this->maybeUnserialize($value);
                        }

                        $this->cache[$derivedKeys[$key]] = $value;
                        $results[$key] = is_object($value) ? clone $value : $value;
                        ++$this->cacheHits;
                    } else {
                        ++$this->cacheMisses;
                    }
                }

                return $results;
            } catch (Exception $e) {
                $this->handleException($e);
                return array_fill_keys($keys, false);
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Sets a value in the cache.
         *
         * @param string $key The cache key.
         * @param mixed $value The value to set.
         * @param string $group The cache group.
         * @param int $expiration The expiration time in seconds.
         * @return bool True on success, false on failure.
         */
        public function set(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
        {
            if (!$this->redisConnected || $this->isIgnoredGroup($group)) {
                return false;
            }

            try {
                $startTime = microtime(true);
                $derivedKey = $this->buildKey($key, $group);
                $expiration = $this->validateExpiration($expiration);
                $serializedValue = $this->maybeSerialize($value);

                // Use SETEX for expiration, otherwise regular SET
                $result = ($expiration > 0)
                    ? $this->redis->setex($derivedKey, $expiration, $serializedValue)
                    : $this->redis->set($derivedKey, $serializedValue);

                if ($result) {
                    $this->cache[$derivedKey] = is_object($value) ? clone $value : $value;
                }

                return (bool)$result;
            } catch (Exception $e) {
                $this->handleException($e);
                return false;
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Sets multiple values in the cache using pipelining.
         *
         * @param array $data Array of key => value pairs.
         * @param string $group The cache group.
         * @param int $expiration The expiration time in seconds.
         * @return array Array of results (true/false for each key).
         */
        public function setMultiple(array $data, string $group = 'default', int $expiration = 0): array
        {
            if (!$this->redisConnected || $this->isIgnoredGroup($group) || empty($data)) {
                return array_fill_keys(array_keys($data), false);
            }

            try {
                $startTime = microtime(true);
                $pipe = $this->redis->pipeline();
                $expiration = $this->validateExpiration($expiration);

                // Pre-process all values
                $processedData = [];
                foreach ($data as $key => $value) {
                    $derivedKey = $this->buildKey($key, $group);
                    $serializedValue = $this->maybeSerialize($value);
                    $processedData[$derivedKey] = [
                        'value' => $serializedValue,
                        'original' => $value
                    ];

                    if ($expiration > 0) {
                        $pipe->setex($derivedKey, $expiration, $serializedValue);
                    } else {
                        $pipe->set($derivedKey, $serializedValue);
                    }
                }

                $pipe->exec();

                // Bulk update local cache
                foreach ($processedData as $derivedKey => $item) {
                    $this->cache[$derivedKey] = $item['original'];
                }

                return array_fill_keys(array_keys($data), true);
            } catch (Exception $e) {
                $this->handleException($e);
                return array_fill_keys(array_keys($data), false);
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Sets up the cache groups.
         */
        private function setupGroups(): void
        {
            // Set up global groups if defined
            if (defined('WP_REDIS_GLOBAL_GROUPS') && is_array(WP_REDIS_GLOBAL_GROUPS)) {
                $this->globalGroups = array_unique(array_merge(
                    $this->globalGroups,
                    array_map([$this, 'sanitizeKey'], WP_REDIS_GLOBAL_GROUPS)
                ));
            }

            // Set up ignored groups if defined
            if (defined('WP_REDIS_IGNORED_GROUPS') && is_array(WP_REDIS_IGNORED_GROUPS)) {
                $this->ignoredGroups = array_map([$this, 'sanitizeKey'], WP_REDIS_IGNORED_GROUPS);
            }

            // Add redis-cache to global groups
            $this->globalGroups[] = 'redis-cache';

            // Initialize group types cache
            $this->cacheGroupTypes();
        }

        /**
         * Adds a value to the cache if it doesn't already exist.
         *
         * @param string $key The cache key.
         * @param mixed $value The value to add.
         * @param string $group The cache group.
         * @param int $expiration The expiration time in seconds.
         * @return bool True if the value was added, false if it already exists.
         */
        public function add(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
        {
            if (wp_suspend_cache_addition()) {
                return false;
            }

            $derivedKey = $this->buildKey($key, $group);

            if (isset($this->cache[$derivedKey])) {
                return false;
            }

            if (!$this->redisConnected || $this->isIgnoredGroup($group)) {
                return false;
            }

            try {
                $startTime = microtime(true);
                $expiration = $this->validateExpiration($expiration);
                $serializedValue = $this->maybeSerialize($value);

                $result = $this->redis->set($derivedKey, $serializedValue, ['NX']);
                if ($result && $expiration > 0)
                    $this->redis->expire($derivedKey, $expiration);

                if ($result) {
                    $this->cache[$derivedKey] = is_object($value) ? clone $value : $value;
                }

                return (bool)$result;
            } catch (Exception $e) {
                $this->handleException($e);
                return false;
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Replaces a value in the cache.
         *
         * @param string $key The cache key.
         * @param mixed $value The value to set.
         * @param string $group The cache group.
         * @param int $expiration The expiration time in seconds.
         * @return bool True if the value was replaced, false if the key doesn't exist.
         */
        public function replace(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool
        {
            $derivedKey = $this->buildKey($key, $group);

            if (!isset($this->cache[$derivedKey])) {
                return false;
            }

            if (!$this->redisConnected || $this->isIgnoredGroup($group)) {
                return false;
            }

            try {
                $startTime = microtime(true);
                $expiration = $this->validateExpiration($expiration);
                $serializedValue = $this->maybeSerialize($value);

                $result = $this->redis->set($derivedKey, $serializedValue, ['XX']);
                if ($result && $expiration > 0)
                    $this->redis->expire($derivedKey, $expiration);

                if ($result) {
                    $this->cache[$derivedKey] = is_object($value) ? clone $value : $value;
                }

                return (bool)$result;
            } catch (Exception $e) {
                $this->handleException($e);
                return false;
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Deletes a value from the cache.
         *
         * @param string $key The cache key.
         * @param string $group The cache group.
         * @return bool True on success, false on failure.
         */
        public function delete(string $key, string $group = 'default'): bool
        {
            $derivedKey = $this->buildKey($key, $group);
            unset($this->cache[$derivedKey]);

            if (!$this->redisConnected || $this->isIgnoredGroup($group)) {
                return false;
            }

            try {
                $startTime = microtime(true);
                return (bool)$this->redis->del($derivedKey);
            } catch (Exception $e) {
                $this->handleException($e);
                return false;
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Deletes multiple values from the cache using pipelining.
         *
         * @param array $keys Array of cache keys.
         * @param string $group The cache group.
         * @return array Array of results (true/false for each key).
         */
        public function deleteMultiple(array $keys, string $group = 'default'): array
        {
            if (!$this->redisConnected || $this->isIgnoredGroup($group) || empty($keys)) {
                return array_fill_keys($keys, false);
            }

            try {
                $startTime = microtime(true);
                $pipe = $this->redis->pipeline();
                $derivedKeys = [];

                // Pre-process keys and update local cache
                foreach ($keys as $key) {
                    $derivedKey = $this->buildKey($key, $group);
                    $derivedKeys[] = $derivedKey;
                    unset($this->cache[$derivedKey]);
                }

                // Batch delete operation
                if (!empty($derivedKeys)) {
                    $pipe->del(...$derivedKeys);
                }

                $pipe->exec();
                return array_fill_keys($keys, true);
            } catch (Exception $e) {
                $this->handleException($e);
                return array_fill_keys($keys, false);
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Flushes the cache.
         *
         * @return bool True on success, false on failure.
         */
        public function flush(): bool
        {
            $this->cache = [];

            if (!$this->redisConnected) {
                return false;
            }

            try {
                $startTime = microtime(true);

                // Use selective flush if enabled
                if (defined('WP_REDIS_SELECTIVE_FLUSH') && WP_REDIS_SELECTIVE_FLUSH) {
                    $pattern = $this->globalKeyPrefix . '*';
                    return (bool)$this->redis->evalSha(
                        $this->flushScriptSHA1,
                        [$pattern],
                        1
                    );
                }

                return $this->redis->flushdb();
            } catch (Exception $e) {
                $this->handleException($e);
                return false;
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Flushes all cache keys in a specific group using a Lua script (from v2).
         *
         * @param string $group Cache group to flush.
         * @return bool True on success, false on failure or error.
         */
        public function flush_group(string $group): bool
        {
            if (!$this->redisConnected || $this->isIgnoredGroup($group)) {
                return false;
            }

            if (defined('WP_REDIS_DISABLE_GROUP_FLUSH') && WP_REDIS_DISABLE_GROUP_FLUSH) {
                return $this->flush();
            }

            $startTime = microtime(true);

            try {
                $prefix = $this->isGlobalGroup($group) ? $this->globalKeyPrefix : $this->blogKeyPrefix;
                $pattern = $prefix . $this->sanitizeKey($group) . ':*';

                // Clear internal cache for this group
                foreach ($this->cache as $key => $value) {
                    if (str_starts_with($key, "{$group}:") || strpos($key, ":{$group}:") !== false) {
                        unset($this->cache[$key]);
                    }
                }

                // Use SCAN to find and delete all keys in the group
                $result = (bool)$this->redis->evalSha($this->flushScriptSHA1, [$pattern], 1);

                return $result;
            } catch (Exception $e) {
                $this->handleException($e);
                return false;
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Increments a numeric value in the cache.
         *
         * @param string $key The cache key.
         * @param int $offset The amount to increment by.
         * @param string $group The cache group.
         * @return int|false The incremented value on success, false on failure.
         */
        public function increment(string $key, int $offset = 1, string $group = 'default'): int|false
        {
            if (!$this->redisConnected || $this->isIgnoredGroup($group)) {
                return false;
            }

            try {
                $startTime = microtime(true);
                $derivedKey = $this->buildKey($key, $group);

                // Use INCRBY for atomic operation
                $value = $this->redis->incrBy($derivedKey, $offset);
                $this->cache[$derivedKey] = $value;

                return $value;
            } catch (Exception $e) {
                $this->handleException($e);
                return false;
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Decrements a numeric value in the cache.
         *
         * @param string $key The cache key.
         * @param int $offset The amount to decrement by.
         * @param string $group The cache group.
         * @return int|false The decremented value on success, false on failure.
         */
        public function decrement(string $key, int $offset = 1, string $group = 'default'): int|false
        {
            if (!$this->redisConnected || $this->isIgnoredGroup($group)) {
                return false;
            }

            try {
                $startTime = microtime(true);
                $derivedKey = $this->buildKey($key, $group);

                // Use DECRBY for atomic operation
                $value = $this->redis->decrBy($derivedKey, $offset);
                $this->cache[$derivedKey] = $value;

                return $value;
            } catch (Exception $e) {
                $this->handleException($e);
                return false;
            } finally {
                $this->updateMetrics($startTime);
            }
        }

        /**
         * Builds a key for the cache.
         *
         * @param string|int $key The cache key.
         * @param string $group The cache group.
         * @return string The derived key.
         */
        private function buildKey(string|int $key, string $group = 'default'): string
        {
            $key = (string)$key;
            $group = (string)$group;

            // Direct string concatenation is faster than sprintf
            $cacheKey = $group . ':' . $key;

            // Use array_key_exists instead of isset for null values
            if (!array_key_exists($cacheKey, self::$keyCache)) {
                if (count(self::$keyCache) >= self::MAX_KEY_CACHE_SIZE) {
                    array_shift(self::$keyCache);
                }
                self::$keyCache[$cacheKey] = $this->generateKey($key, $group);
            }

            return self::$keyCache[$cacheKey];
        }

        /**
         * Generates a derived key from the key and group.
         *
         * @param string|int $key The cache key.
         * @param string $group The cache group.
         * @return string The derived key.
         */
        private function generateKey(string|int $key, string $group): string
        {
            $key = (string)$key;
            $prefix = $this->isGlobalGroup($group) ? $this->globalKeyPrefix : $this->blogKeyPrefix;
            $derivedKey = $prefix . $this->sanitizeKey($group ?: 'default') . ':' . $this->sanitizeKey($key);

            // Implement LRU for key cache
            if (count(self::$keyCache) >= self::MAX_KEY_CACHE_SIZE) {
                array_shift(self::$keyCache);
            }

            return $derivedKey;
        }

        /**
         * Serializes data if needed.
         * Sentinel: Adds HMAC signature to serialized objects to prevent tampering.
         *
         * @param mixed $value The value to serialize.
         * @return mixed The serialized value if needed, otherwise the original value.
         */
        private function maybeSerialize(mixed $value): mixed
        {
            if (is_numeric($value) || is_string($value) || is_bool($value)) {
                return $value;
            }

            $serialized = serialize($value);
            $hash = hash_hmac('sha256', $serialized, $this->salt);

            // S:{hash}:{serialized_data}
            return 'S:' . $hash . ':' . $serialized;
        }

        /**
         * Unserializes data if needed.
         * Sentinel: Verifies HMAC signature before unserializing.
         *
         * @param mixed $value The value to unserialize.
         * @return mixed The unserialized value if needed, otherwise the original value.
         */
        private function maybeUnserialize(mixed $value): mixed
        {
            if (!is_string($value) || strlen($value) < 4) {
                return $value;
            }

            // Sentinel: Verify signed payloads
            if (str_starts_with($value, 'S:')) {
                $parts = explode(':', $value, 3);
                if (count($parts) === 3) {
                    $hash = $parts[1];
                    $payload = $parts[2];
                    $calc = hash_hmac('sha256', $payload, $this->salt);

                    if (hash_equals($hash, $calc)) {
                        try {
                            $unserialized = @unserialize($payload);
                            return ($unserialized !== false || $payload === 'b:0;') ? $unserialized : $value;
                        } catch (Exception) {
                            return $value;
                        }
                    }
                }
                // Invalid signature or format: Treat as corrupted/miss
                return false;
            }

            // Sentinel: REJECT unsigned legacy serialization to prevent Object Injection
            // If it looks like serialized data but has no signature, assume it's dangerous.
            static $pattern = '/^[absiOCrdN]:[0-9]+/';
            if (preg_match($pattern, $value)) {
                return false; // Force cache miss
            }

            // It's a primitive string/int matching no pattern
            return $value;
        }

        /**
         * Validates the expiration time, ensuring it's within the allowed range.
         *
         * @param int $expiration The expiration time in seconds.
         * @return int The validated expiration time.
         */
        private function validateExpiration(int $expiration): int
        {
            $expiration = (int)round($expiration);

            if ($expiration < 0) {
                return 0; // Treat negative values as no expiration
            }

            if (defined('WP_REDIS_MAXTTL') && $expiration > WP_REDIS_MAXTTL) {
                return WP_REDIS_MAXTTL; // Enforce maximum TTL if defined
            }

            return $expiration;
        }

        /**
         * Sanitizes the key replacing invalid characters.
         *
         * @param mixed $key The key to sanitize.
         * @return string The sanitized key.
         */
        private function sanitizeKey(mixed $key): string
        {
            return str_replace(' ', '-', (string)$key);
        }

        /**
         * Checks if a group is global.
         *
         * @param string $group The group to check.
         * @return bool True if the group is global.
         */
        private function isGlobalGroup(string $group): bool
        {
            static $cache = [];
            if (!array_key_exists($group, $cache)) {
                $cache[$group] = isset($this->groupTypes[$group]) &&
                    $this->groupTypes[$group] === 'global';
            }
            return $cache[$group];
        }

        /**
         * Checks if a group is ignored.
         *
         * @param string $group The group to check.
         * @return bool True if the group is ignored.
         */
        private function isIgnoredGroup(string $group): bool
        {
            return isset($this->groupTypes[$group]) && $this->groupTypes[$group] === 'ignored';
        }

        /**
         * Updates cache metrics.
         *
         * @param float $startTime The start time of the operation.
         */
        private function updateMetrics(float $startTime): void
        {
            $this->cacheCalls++;
            $this->cacheTime += microtime(true) - $startTime;
        }

        /**
         * Handles exceptions during Redis operations.
         *
         * @param Exception $e The exception to handle.
         * @param string $context Optional context for the error.
         * @throws Exception If fail gracefully is disabled.
         */
        private function handleException(Exception $e, string $context = ''): void
        {
            $this->redisConnected = false;
            $errorMsg = $context ? "[{$context}] " . $e->getMessage() : $e->getMessage();
            $this->errors[] = $errorMsg;

            if (function_exists('do_action')) {
                do_action('redis_object_cache_error', $e, $errorMsg);
            }

            if (!$this->failGracefully) {
                throw $e;
            }

            error_log("WP Redis: {$errorMsg}");
        }

        /**
         * Manages cache groups.
         *
         * @param string|array $groups The groups to manage.
         * @param string $type Type of group ('global' or 'ignored').
         */
        private function manageGroups(string|array $groups, string $type): void
        {
            $groups = (array)$groups;
            $sanitizedGroups = array_map([$this, 'sanitizeKey'], $groups);

            match ($type) {
                'global' => $this->globalGroups = array_unique(array_merge($this->globalGroups, $sanitizedGroups)),
                'ignored' => $this->ignoredGroups = array_unique(array_merge($this->ignoredGroups, $sanitizedGroups)),
            };

            $this->cacheGroupTypes();
        }

        /**
         * Adds global cache groups.
         *
         * @param string|array $groups The groups to add.
         */
        public function addGlobalGroups(string|array $groups): void
        {
            $this->manageGroups($groups, 'global');
        }

        /**
         * Adds non-persistent cache groups.
         *
         * @param string|array $groups The groups to add.
         */
        public function addNonPersistentGroups(string|array $groups): void
        {
            if (function_exists('apply_filters')) {
                $groups = apply_filters('redis_cache_add_non_persistent_groups', (array)$groups);
            }

            $this->manageGroups($groups, 'ignored');
        }

        /**
         * Caches group types for faster lookups.
         */
        private function cacheGroupTypes(): void
        {
            $this->groupTypes = [];

            foreach ($this->globalGroups as $group) {
                $this->groupTypes[$group] = 'global';
            }

            foreach ($this->ignoredGroups as $group) {
                $this->groupTypes[$group] = 'ignored';
            }
        }

        /**
         * Initializes the Redis connection.
         */
        private function initializeRedis(): void
        {
            try {
                $config = $this->buildConfig();

                // Choose the appropriate client (support both Predis and PhpRedis)
                if (class_exists('Redis')) {
                    $this->connectPhpRedis($config);
                } elseif (class_exists('Predis\\Client')) {
                    $this->connectPredis($config);
                } else {
                    throw new Exception('No supported Redis client found. Install either PhpRedis or Predis.');
                }

                // Verify connection
                $this->redis->ping();
                $this->redisConnected = true;

                // Get Redis info
                $info = $this->redis->info();
                $this->redisVersion = $info['redis_version'] ?? null;
            } catch (Exception $e) {
                $this->handleException($e, 'connection');
            }
        }

        /**
         * Builds the Redis configuration.
         *
         * @return array The configuration array.
         */
        private function buildConfig(): array
        {
            $defaults = [
                'scheme' => 'tcp',
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => 1.0,
                'read_timeout' => 1.0,
                'retry_interval' => 0,
                'database' => 0,
            ];

            $config = [];

            // Load configuration from constants
            foreach ($defaults as $key => $default) {
                $constant = 'WP_REDIS_' . strtoupper($key);
                $config[$key] = defined($constant) ? constant($constant) : $default;
            }

            // Handle password separately to avoid security issues
            if (defined('WP_REDIS_PASSWORD')) {
                $config['password'] = WP_REDIS_PASSWORD;
            }

            return $config;
        }

        /**
         * Connects to Redis using PhpRedis.
         *
         * @param array $config The connection configuration.
         */
        private function connectPhpRedis(array $config): void
        {
            $this->redis = new \Redis();

            if ($config['scheme'] === 'unix') {
                $connected = $this->redis->connect($config['path']);
            } else {
                $connected = $this->redis->connect(
                    $config['host'],
                    (int)$config['port'],
                    (float)$config['timeout'],
                    null,
                    (int)$config['retry_interval']
                );
            }

            if (!$connected) {
                throw new Exception('Could not connect to Redis');
            }

            if (!empty($config['password'])) {
                $this->redis->auth($config['password']);
            }

            if ((int)$config['database'] !== 0) {
                $this->redis->select((int)$config['database']);
            }

            // Set read timeout
            $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, (float)$config['read_timeout']);
        }

        /**
         * Connects to Redis using Predis.
         *
         * @param array $config The connection configuration.
         */
        private function connectPredis(array $config): void
        {
            $this->redis = new \Predis\Client($config);
            $this->redis->connect();
        }

        /**
         * Returns the Lua script for selective flushing.
         *
         * @return string The Lua script.
         */
        private function getSelectiveFlushScript(): string
        {
            return "local cursor = \"0\"\nlocal count = 0\nrepeat\n    local result = redis.call('SCAN', cursor, 'MATCH', ARGV[1])\n    cursor = result[1]\n    local keys = result[2]\n    if #keys > 0 then\n        count = count + redis.call('DEL', unpack(keys))\n    end\nuntil cursor == \"0\"\nreturn count";
        }

        /**
         * Switches to a different blog in multisite.
         *
         * @param int $blog_id Blog ID to switch to.
         * @return bool True on success, false if not multisite.
         */
        public function switchToBlog(int $blog_id): bool
        {
            if (!is_multisite()) {
                return false;
            }

            $this->cache = []; // Clear in-memory cache
            $this->blogKeyPrefix = $this->globalKeyPrefix . (string)$blog_id;

            // Reset key cache on blog switch
            self::$keyCache = [];

            if (function_exists('do_action')) {
                do_action('redis_object_cache_switch_blog', $blog_id);
            }

            return true;
        }
    }

endif;
