<?php
declare(strict_types=1);

namespace WPSCache\Cache;

use WPSCache\Cache\Interfaces\CacheDriverInterface;
/**
 * Abstract base class implementing common cache functionality
 */
abstract class AbstractCacheDriver implements CacheDriverInterface {
    protected string $cache_dir;
    protected array $settings;
    protected bool $initialized = false;

    public function __construct(string $cache_dir, array $settings = []) {
        $this->cache_dir = rtrim($cache_dir, '/') . '/';
        $this->settings = $settings;
        $this->ensureCacheDirectory();
    }

    protected function ensureCacheDirectory(): void {
        if (!file_exists($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
    }

    public function initialize(): void {
        if ($this->initialized) {
            return;
        }
        
        $this->doInitialize();
        $this->initialized = true;
    }

    /**
     * Template method for specific initialization logic
     */
    protected function doInitialize(): void {}

    public function isConnected(): bool {
        return is_writable($this->cache_dir);
    }

    /**
     * Common method to generate cache file path
     */
    protected function getCacheFile(string $key): string {
        return $this->cache_dir . $this->sanitizeKey($key) . $this->getFileExtension();
    }

    /**
     * Sanitize cache keys for file system storage
     */
    protected function sanitizeKey(string $key): string {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
    }

    /**
     * Get file extension for the cache type
     */
    abstract protected function getFileExtension(): string;
    
    /**
     * Check if URL should be excluded from caching
     */
    protected function isExcludedUrl(string $url): bool {
        $excluded_urls = $this->settings['excluded_urls'] ?? [];
        
        foreach ($excluded_urls as $pattern) {
            if (fnmatch($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
}