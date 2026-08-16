<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\WordPress;

use Closure;
use RuntimeException;
use Throwable;
use WPSCache\Config\Settings;

/** Performs fail-closed checks before a persistent object-cache drop-in is written. */
final class ObjectCacheCompatibility
{
    /** @var null|Closure(string, Settings): ?string */
    private readonly ?Closure $connectionProbe;
    /** @var array<string, ObjectCacheCheck> */
    private array $checks = [];

    /**
     * The optional probe is used by hosts and tests that expose their own health
     * check. Returning null means healthy; returning text rejects installation.
     *
     * @param null|callable(string, Settings): ?string $connectionProbe
     */
    public function __construct(
        private readonly DropInManager $dropIns,
        private readonly string $runtimeConfigFile,
        ?callable $connectionProbe = null,
    ) {
        $this->connectionProbe = $connectionProbe === null ? null : Closure::fromCallable($connectionProbe);
    }

    public function inspect(Settings $settings, bool $testConnection = true): ObjectCacheCheck
    {
        $cacheKey = hash('sha256', serialize([$settings->all(), $testConnection]));
        if (isset($this->checks[$cacheKey])) {
            return $this->checks[$cacheKey];
        }
        $redis = $settings->enabled('redis_cache');
        $memcached = $settings->enabled('memcached_cache');
        if (!$redis && !$memcached) {
            return $this->checks[$cacheKey] = new ObjectCacheCheck(null, ['Enable and save either Redis or Memcached before installing a drop-in.']);
        }
        if ($redis && $memcached) {
            return $this->checks[$cacheKey] = new ObjectCacheCheck(null, ['Redis and Memcached cannot both own WordPress object caching.']);
        }

        $backend = $memcached ? 'memcached' : 'redis';
        $issues = [];
        $dropInIssue = $this->dropIns->objectCacheInstallationIssue($backend);
        if ($dropInIssue !== null) {
            $issues[] = $dropInIssue;
        }
        if (!$this->pathCanBeWritten($this->runtimeConfigFile)) {
            $issues[] = 'The object-cache runtime configuration directory is not writable.';
        }

        if ($backend === 'redis' && defined('WP_REDIS_DISABLED') && WP_REDIS_DISABLED) {
            $issues[] = 'Redis is disabled by WP_REDIS_DISABLED.';
        }

        if ($testConnection && $issues === []) {
            $connectionIssue = $this->connectionIssue($backend, $settings);
            if ($connectionIssue !== null) {
                $issues[] = $connectionIssue;
            }
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('wpsc_object_cache_preflight', $issues, $backend, $settings->all(), $testConnection);
            if (is_array($filtered)) {
                $issues = array_values(array_filter($filtered, 'is_string'));
            }
        }

        return $this->checks[$cacheKey] = new ObjectCacheCheck($backend, $issues);
    }

    private function connectionIssue(string $backend, Settings $settings): ?string
    {
        if ($this->connectionProbe instanceof Closure) {
            try {
                return ($this->connectionProbe)($backend, $settings);
            } catch (Throwable) {
                return ucfirst($backend) . ' compatibility probe failed unexpectedly.';
            }
        }

        return $backend === 'memcached'
            ? $this->memcachedConnectionIssue($settings)
            : $this->redisConnectionIssue($settings);
    }

    private function redisConnectionIssue(Settings $settings): ?string
    {
        if (!class_exists('Redis') && !class_exists('Predis\\Client')) {
            return 'No Redis client is available. Install and enable the PHP Redis extension (or load Predis before WordPress drop-ins).';
        }

        $config = $this->redisConfiguration($settings);
        try {
            if (class_exists('Redis')) {
                $client = new \Redis();
                $connected = $config['scheme'] === 'unix'
                    ? $client->connect($config['path'])
                    : $client->connect(
                        $config['scheme'] === 'tls' && !str_starts_with($config['host'], 'tls://') ? 'tls://' . $config['host'] : $config['host'],
                        $config['port'],
                        $config['timeout'],
                    );
                if (!$connected) {
                    throw new RuntimeException('connect');
                }
                if ($config['password'] !== '' && $client->auth($config['password']) === false) {
                    throw new RuntimeException('auth');
                }
                if ($config['database'] !== 0 && $client->select($config['database']) === false) {
                    throw new RuntimeException('database');
                }
                if ($client->ping() === false) {
                    throw new RuntimeException('ping');
                }
                if (method_exists($client, 'close')) {
                    $client->close();
                }
                return null;
            }

            $predis = new \Predis\Client(array_filter([
                'scheme' => $config['scheme'],
                'host' => $config['host'],
                'port' => $config['port'],
                'path' => $config['scheme'] === 'unix' ? $config['path'] : null,
                'database' => $config['database'],
                'password' => $config['password'] === '' ? null : $config['password'],
                'timeout' => $config['timeout'],
                'read_write_timeout' => $config['timeout'],
            ], static fn(mixed $value): bool => $value !== null));
            $predis->connect();
            if ($predis->ping() === false) {
                throw new RuntimeException('ping');
            }
            $predis->disconnect();
            return null;
        } catch (Throwable) {
            return 'The configured Redis service could not be reached or authenticated. No drop-in was installed.';
        }
    }

    private function memcachedConnectionIssue(Settings $settings): ?string
    {
        if (!extension_loaded('memcached') || !class_exists('Memcached')) {
            return 'The PHP Memcached extension is not available.';
        }

        try {
            $client = new \Memcached();
            $client->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 750);
            $client->setOption(\Memcached::OPT_RETRY_TIMEOUT, 1);
            $client->addServer($settings->string('memcached_host'), $settings->integer('memcached_port'));
            $versions = $client->getVersion();
            $healthy = is_array($versions) && array_filter(
                $versions,
                static fn(mixed $version): bool => is_string($version) && $version !== '' && $version !== '255.255.255',
            ) !== [];
            $client->quit();
            return $healthy ? null : 'The configured Memcached service did not answer the health check. No drop-in was installed.';
        } catch (Throwable) {
            return 'The configured Memcached service could not be reached. No drop-in was installed.';
        }
    }

    /** @return array{scheme: string, host: string, port: int, path: string, database: int, password: string, timeout: float} */
    private function redisConfiguration(Settings $settings): array
    {
        $scheme = defined('WP_REDIS_SCHEME') ? (string) WP_REDIS_SCHEME : ($settings->enabled('redis_tls') ? 'tls' : 'tcp');
        return [
            'scheme' => in_array($scheme, ['tcp', 'tls', 'unix'], true) ? $scheme : 'tcp',
            'host' => defined('WP_REDIS_HOST') ? (string) WP_REDIS_HOST : $settings->string('redis_host'),
            'port' => defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : $settings->integer('redis_port'),
            'path' => defined('WP_REDIS_PATH') ? (string) WP_REDIS_PATH : '',
            'database' => defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : $settings->integer('redis_db'),
            'password' => defined('WP_REDIS_PASSWORD') ? (string) WP_REDIS_PASSWORD : $settings->string('redis_password'),
            'timeout' => defined('WP_REDIS_TIMEOUT') ? max(0.1, min(2.0, (float) WP_REDIS_TIMEOUT)) : 0.75,
        ];
    }

    private function pathCanBeWritten(string $file): bool
    {
        if (is_file($file)) {
            return is_writable($file) && is_writable(dirname($file));
        }

        $directory = dirname($file);
        while (!is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }
        return is_writable($directory);
    }
}
