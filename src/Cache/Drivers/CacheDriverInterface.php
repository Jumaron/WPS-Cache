<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Interface for cache drivers implementing cache operations
 */
interface CacheDriverInterface
{
    /**
     * Initialize the cache driver
     */
    public function initialize(): void;

    /**
     * Check if the cache driver is connected and operational
     */
    public function isConnected(): bool;

    /**
     * Get a value from cache
     * 
     * @param string $key Cache key
     * @return mixed The cached value or null if not found
     */
    public function get(string $key): mixed;

    /**
     * Set a value in cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void;

    /**
     * Delete a value from cache
     * 
     * @param string $key Cache key
     */
    public function delete(string $key): void;

    /**
     * Clear all values from this cache
     */
    public function clear(): void;
}
