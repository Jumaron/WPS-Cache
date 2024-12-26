<?php
declare(strict_types=1);

namespace WPSCache\Cache\Interfaces;

/**
 * Enhanced interface with clearer contract and common cache operations
 */
interface CacheDriverInterface {
    /**
     * Initialize the cache driver
     */
    public function initialize(): void;
    
    /**
     * Check if the cache driver is properly connected and working
     */
    public function isConnected(): bool;
    
    /**
     * Retrieve a value from cache
     * 
     * @param string $key Cache key
     * @return mixed The cached value or null if not found
     */
    public function get(string $key): mixed;
    
    /**
     * Store a value in cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void;
    
    /**
     * Delete a specific cache entry
     * 
     * @param string $key Cache key
     */
    public function delete(string $key): void;
    
    /**
     * Clear all cache entries
     */
    public function clear(): void;
}