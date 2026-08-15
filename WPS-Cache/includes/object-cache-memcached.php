<?php

/**
 * WPS-Cache Memcached Object Cache Backend
 *
 * Namespace generations provide selective, shared-server-safe invalidation.
 * Values are signed before unserialization to reject tampered cache payloads.
 *
 * @package WPSCache
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$wpscObjectConfig = WP_CONTENT_DIR . '/cache/wps-cache/object-runtime.php';
$GLOBALS['wpsc_memcached_config'] = is_file($wpscObjectConfig) ? @include $wpscObjectConfig : [];
unset($wpscObjectConfig);

if (!function_exists('wp_cache_init')) {
    function wp_cache_supports(string $feature): bool
    {
        return in_array($feature, ['add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group'], true);
    }

    function wp_cache_init(): void
    {
        global $wp_object_cache;
        if (!($wp_object_cache instanceof WP_Object_Cache)) {
            $wp_object_cache = new WP_Object_Cache();
        }
    }

    function wp_cache_add(string|int $key, mixed $value, string $group = '', int $expiration = 0): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->add($key, $value, $group, $expiration);
    }

    function wp_cache_replace(string|int $key, mixed $value, string $group = '', int $expiration = 0): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->replace($key, $value, $group, $expiration);
    }

    function wp_cache_set(string|int $key, mixed $value, string $group = '', int $expiration = 0): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->set($key, $value, $group, $expiration);
    }

    function wp_cache_get(string|int $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
    {
        global $wp_object_cache;
        return $wp_object_cache->get($key, $group, $force, $found);
    }

    function wp_cache_delete(string|int $key, string $group = ''): bool
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

    function wp_cache_flush_group(string $group): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->flushGroup($group);
    }

    function wp_cache_get_multiple(array $keys, string $group = '', bool $force = false): array
    {
        global $wp_object_cache;
        return $wp_object_cache->getMultiple($keys, $group, $force);
    }

    function wp_cache_set_multiple(array $data, string $group = '', int $expire = 0): array
    {
        global $wp_object_cache;
        return $wp_object_cache->setMultiple($data, $group, $expire);
    }

    function wp_cache_add_multiple(array $data, string $group = '', int $expire = 0): array
    {
        global $wp_object_cache;
        return $wp_object_cache->addMultiple($data, $group, $expire);
    }

    function wp_cache_delete_multiple(array $keys, string $group = ''): array
    {
        global $wp_object_cache;
        return $wp_object_cache->deleteMultiple($keys, $group);
    }

    function wp_cache_incr(string|int $key, int $offset = 1, string $group = ''): int|false
    {
        global $wp_object_cache;
        return $wp_object_cache->increment($key, $offset, $group);
    }

    function wp_cache_decr(string|int $key, int $offset = 1, string $group = ''): int|false
    {
        global $wp_object_cache;
        return $wp_object_cache->decrement($key, $offset, $group);
    }

    function wp_cache_switch_to_blog(int $blogId): bool
    {
        global $wp_object_cache;
        return $wp_object_cache->switchToBlog($blogId);
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

    final class WP_Object_Cache
    {
        private ?\Memcached $client = null;
        /** @var array<string, mixed> */
        private array $cache = [];
        /** @var list<string> */
        private array $globalGroups = ['blog-details', 'blog-id-cache', 'blog-lookup', 'global-posts', 'networks', 'rss', 'sites', 'site-details', 'site-lookup', 'site-options', 'site-transient', 'users', 'useremail', 'userlogins', 'usermeta', 'user_meta', 'userslugs'];
        /** @var list<string> */
        private array $nonPersistentGroups = [];
        /** @var array<string, int> */
        private array $groupVersions = [];
        private string $prefix;
        private string $blogPrefix;
        private string $salt;
        private int $namespaceVersion = 1;

        public function __construct()
        {
            global $blog_id, $table_prefix;
            $config = $GLOBALS['wpsc_memcached_config'] ?? [];
            $config = is_array($config) ? $config : [];
            $this->blogPrefix = is_multisite() ? (string) ($blog_id ?? 1) : (string) ($table_prefix ?? 'wp_');
            $this->salt = defined('WP_CACHE_KEY_SALT') ? (string) WP_CACHE_KEY_SALT : (defined('AUTH_KEY') ? (string) AUTH_KEY : hash('sha256', (string) (defined('DB_NAME') ? DB_NAME : 'wps-cache')));
            $this->prefix = (string) ($config['memcached_prefix'] ?? 'wpsc:') . substr(hash('sha256', $this->salt), 0, 16) . ':';
            if (!extension_loaded('memcached') || !class_exists('Memcached')) {
                return;
            }
            try {
                $persistentId = (string) ($config['memcached_persistent_id'] ?? 'wps-cache');
                $this->client = $persistentId === '' ? new \Memcached() : new \Memcached($persistentId);
                $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
                if ($this->client->getServerList() === []) {
                    $this->client->addServer((string) ($config['memcached_host'] ?? '127.0.0.1'), (int) ($config['memcached_port'] ?? 11211));
                }
                $namespace = $this->client->get($this->prefix . 'namespace-version');
                if (!is_int($namespace) || $namespace < 1) {
                    $this->client->add($this->prefix . 'namespace-version', 1, 0);
                    $namespace = 1;
                }
                $this->namespaceVersion = $namespace;
            } catch (\Throwable) {
                $this->client = null;
            }
        }

        public function add(string|int $key, mixed $value, string $group = '', int $expiration = 0): bool
        {
            $group = $this->group($group);
            $derived = $this->key($key, $group);
            if ($this->ignored($group) || !$this->client instanceof \Memcached) {
                if (array_key_exists($derived, $this->cache)) {
                    return false;
                }
                $this->cache[$derived] = $value;
                return true;
            }
            $success = $this->client->add($derived, $this->encode($value), max(0, $expiration));
            if ($success) {
                $this->cache[$derived] = $value;
            }
            return $success;
        }

        public function replace(string|int $key, mixed $value, string $group = '', int $expiration = 0): bool
        {
            $group = $this->group($group);
            $derived = $this->key($key, $group);
            if ($this->ignored($group) || !$this->client instanceof \Memcached) {
                if (!array_key_exists($derived, $this->cache)) {
                    return false;
                }
                $this->cache[$derived] = $value;
                return true;
            }
            $success = $this->client->replace($derived, $this->encode($value), max(0, $expiration));
            if ($success) {
                $this->cache[$derived] = $value;
            }
            return $success;
        }

        public function set(string|int $key, mixed $value, string $group = '', int $expiration = 0): bool
        {
            $group = $this->group($group);
            $derived = $this->key($key, $group);
            $this->cache[$derived] = $value;
            return $this->ignored($group) || !$this->client instanceof \Memcached
                ? true
                : $this->client->set($derived, $this->encode($value), max(0, $expiration));
        }

        public function get(string|int $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
        {
            $group = $this->group($group);
            $derived = $this->key($key, $group);
            if (!$force && array_key_exists($derived, $this->cache)) {
                $found = true;
                return $this->copy($this->cache[$derived]);
            }
            if ($this->ignored($group) || !$this->client instanceof \Memcached) {
                $found = false;
                return false;
            }
            $encoded = $this->client->get($derived);
            if ($this->client->getResultCode() !== \Memcached::RES_SUCCESS || !is_string($encoded)) {
                $found = false;
                return false;
            }
            $value = $this->decode($encoded, $valid);
            if (!$valid) {
                $this->client->delete($derived);
                $found = false;
                return false;
            }
            $this->cache[$derived] = $value;
            $found = true;
            return $this->copy($value);
        }

        public function delete(string|int $key, string $group = ''): bool
        {
            $group = $this->group($group);
            $derived = $this->key($key, $group);
            unset($this->cache[$derived]);
            if ($this->ignored($group) || !$this->client instanceof \Memcached) {
                return true;
            }
            $success = $this->client->delete($derived);
            return $success || $this->client->getResultCode() === \Memcached::RES_NOTFOUND;
        }

        public function flush(): bool
        {
            $this->cache = [];
            if (!$this->client instanceof \Memcached) {
                return true;
            }
            $key = $this->prefix . 'namespace-version';
            $next = $this->client->increment($key, 1);
            if ($next === false) {
                $this->client->set($key, $this->namespaceVersion + 1, 0);
                $next = $this->namespaceVersion + 1;
            }
            $this->namespaceVersion = (int) $next;
            $this->groupVersions = [];
            return true;
        }

        public function flushRuntime(): bool
        {
            $this->cache = [];
            return true;
        }

        public function flushGroup(string $group): bool
        {
            $group = $this->group($group);
            foreach (array_keys($this->cache) as $key) {
                if (str_contains($key, ':' . hash('sha256', $group) . ':')) {
                    unset($this->cache[$key]);
                }
            }
            if (!$this->client instanceof \Memcached) {
                return true;
            }
            $versionKey = $this->groupVersionKey($group);
            $next = $this->client->increment($versionKey, 1);
            if ($next === false) {
                $this->client->set($versionKey, 2, 0);
                $next = 2;
            }
            $this->groupVersions[$group] = (int) $next;
            return true;
        }

        public function getMultiple(array $keys, string $group = '', bool $force = false): array
        {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $this->get($key, $group, $force);
            }
            return $result;
        }

        public function setMultiple(array $data, string $group = '', int $expiration = 0): array
        {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->set($key, $value, $group, $expiration);
            }
            return $result;
        }

        public function addMultiple(array $data, string $group = '', int $expiration = 0): array
        {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->add($key, $value, $group, $expiration);
            }
            return $result;
        }

        public function deleteMultiple(array $keys, string $group = ''): array
        {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $this->delete($key, $group);
            }
            return $result;
        }

        public function increment(string|int $key, int $offset = 1, string $group = ''): int|false
        {
            $found = false;
            $value = $this->get($key, $group, true, $found);
            if (!$found || !is_numeric($value)) {
                return false;
            }
            $value = max(0, (int) $value + $offset);
            return $this->set($key, $value, $group) ? $value : false;
        }

        public function decrement(string|int $key, int $offset = 1, string $group = ''): int|false
        {
            return $this->increment($key, -$offset, $group);
        }

        public function switchToBlog(int $blogId): bool
        {
            $this->cache = [];
            $this->blogPrefix = (string) $blogId;
            return true;
        }

        public function addGlobalGroups(string|array $groups): void
        {
            $this->globalGroups = array_values(array_unique(array_merge($this->globalGroups, array_map('strval', (array) $groups))));
        }

        public function addNonPersistentGroups(string|array $groups): void
        {
            $this->nonPersistentGroups = array_values(array_unique(array_merge($this->nonPersistentGroups, array_map('strval', (array) $groups))));
        }

        public function close(): bool
        {
            $this->cache = [];
            if ($this->client instanceof \Memcached) {
                $this->client->quit();
            }
            return true;
        }

        private function key(string|int $key, string $group): string
        {
            $scope = in_array($group, $this->globalGroups, true) ? 'global' : $this->blogPrefix;
            return $this->prefix . $this->namespaceVersion . ':' . hash('sha256', $group) . ':' . $this->groupVersion($group) . ':' . hash('sha256', $scope . '|' . $group . '|' . (string) $key);
        }

        private function groupVersion(string $group): int
        {
            if (isset($this->groupVersions[$group])) {
                return $this->groupVersions[$group];
            }
            if (!$this->client instanceof \Memcached) {
                return $this->groupVersions[$group] = 1;
            }
            $key = $this->groupVersionKey($group);
            $version = $this->client->get($key);
            if (!is_int($version) || $version < 1) {
                $this->client->add($key, 1, 0);
                $version = 1;
            }
            return $this->groupVersions[$group] = $version;
        }

        private function groupVersionKey(string $group): string
        {
            return $this->prefix . 'group-version:' . hash('sha256', $group);
        }

        private function group(string $group): string
        {
            return $group === '' ? 'default' : (string) preg_replace('/[^a-zA-Z0-9_.-]/', '_', $group);
        }

        private function ignored(string $group): bool
        {
            return in_array($group, $this->nonPersistentGroups, true);
        }

        private function encode(mixed $value): string
        {
            $serialized = serialize($value);
            return hash_hmac('sha256', $serialized, $this->salt) . ':' . $serialized;
        }

        private function decode(string $encoded, ?bool &$valid): mixed
        {
            $separator = strpos($encoded, ':');
            if ($separator !== 64) {
                $valid = false;
                return false;
            }
            $signature = substr($encoded, 0, $separator);
            $serialized = substr($encoded, $separator + 1);
            $valid = hash_equals($signature, hash_hmac('sha256', $serialized, $this->salt));
            return $valid ? @unserialize($serialized, ['allowed_classes' => true]) : false;
        }

        private function copy(mixed $value): mixed
        {
            return is_object($value) ? clone $value : $value;
        }
    }

    wp_cache_init();
}
