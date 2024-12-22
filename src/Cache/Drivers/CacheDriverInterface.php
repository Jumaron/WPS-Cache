<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Interface for cache drivers implementing basic cache operations
 */
interface CacheDriverInterface {
    public function initialize(): void;
    public function isConnected(): bool;
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 3600): void;
    public function delete(string $key): void;
    public function clear(): void;
}