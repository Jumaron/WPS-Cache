<?php

declare(strict_types=1);

namespace WPSCache\Cache\Object;

use Memcached;
use Throwable;
use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;
use WPSCache\Contracts\Purgeable;

/** Runtime health/purge adapter for the bundled Memcached object-cache drop-in. */
final class MemcachedObjectCache implements Module, Purgeable
{
    private ?Memcached $client = null;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'memcached';
    }

    public function boot(): void
    {
        // WordPress loads the persistent drop-in before normal plugins. This adapter
        // deliberately limits itself to diagnostics and namespace invalidation.
    }

    public function isSupported(): bool
    {
        return extension_loaded('memcached') && class_exists(Memcached::class);
    }

    public function isConnected(): bool
    {
        $client = $this->connection();
        if (!$client instanceof Memcached) {
            return false;
        }
        $versions = $client->getVersion();
        return is_array($versions) && array_filter($versions, static fn(mixed $version): bool => is_string($version) && $version !== '255.255.255') !== [];
    }

    public function purge(): void
    {
        $client = $this->connection();
        if (!$client instanceof Memcached) {
            return;
        }
        $key = $this->scopedPrefix() . 'namespace-version';
        if ($client->increment($key, 1) === false) {
            $client->add($key, 2, 0);
        }
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $client = $this->connection();
        if (!$client instanceof Memcached) {
            return [];
        }
        $stats = $client->getStats();
        $first = is_array($stats) ? reset($stats) : false;
        return is_array($first) ? $first : [];
    }

    private function connection(): ?Memcached
    {
        if ($this->client instanceof Memcached) {
            return $this->client;
        }
        if (!$this->isSupported()) {
            return null;
        }
        try {
            $persistentId = $this->settings->string('memcached_persistent_id');
            $client = $persistentId === '' ? new Memcached() : new Memcached($persistentId);
            $client->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
            if ($client->getServerList() === []) {
                $client->addServer($this->settings->string('memcached_host'), $this->settings->integer('memcached_port'));
            }
            $this->client = $client;
        } catch (Throwable) {
            return null;
        }
        return $this->client;
    }

    private function scopedPrefix(): string
    {
        $salt = defined('WP_CACHE_KEY_SALT') ? (string) WP_CACHE_KEY_SALT : (defined('AUTH_KEY') ? (string) AUTH_KEY : hash('sha256', (string) (defined('DB_NAME') ? DB_NAME : 'wps-cache')));
        return $this->settings->string('memcached_prefix') . substr(hash('sha256', $salt), 0, 16) . ':';
    }
}
