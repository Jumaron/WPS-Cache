<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Contract for all caching drivers.
 * Enforces strict typing and standardization across storage engines.
 */
interface CacheDriverInterface
{
    /**
     * Checks if the driver's requirements are met (e.g., extensions loaded).
     */
    public function isSupported(): bool;

    /**
     * Initialize the driver (hooks, connections).
     */
    public function initialize(): void;

    /**
     * Set a value in cache.
     *
     * @param string $key   Unique identifier.
     * @param mixed  $value Data to store.
     * @param int    $ttl   Time-to-live in seconds (0 = infinite).
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void;

    /**
     * Retrieve a value from cache.
     *
     * @param string $key Unique identifier.
     * @return mixed      The value or null if missed/expired.
     */
    public function get(string $key): mixed;

    /**
     * Delete a specific key.
     */
    public function delete(string $key): void;

    /**
     * Flush the entire cache for this driver.
     */
    public function clear(): void;
}
