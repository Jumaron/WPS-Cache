<?php

declare(strict_types=1);

namespace WPSCache\Cache;

use Throwable;
use WPSCache\Contracts\Module;
use WPSCache\Contracts\Purgeable;
use WPSCache\Cache\Page\PageCache;
use WPSCache\Cache\ReverseProxy\VarnishCache;
use WPSCache\Cache\Rest\RestResponseCache;

/** Registry and purge coordinator for cache-owning runtime modules. */
final class CacheManager
{
    /** @var array<string, Module&Purgeable> */
    private array $modules = [];

    /** @var array<string, string> */
    private array $errors = [];

    private bool $booted = false;

    public function register(Module&Purgeable $module): void
    {
        $id = $module->id();
        if (isset($this->modules[$id])) {
            throw new \LogicException('Duplicate cache module: ' . $id);
        }

        $this->modules[$id] = $module;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->modules as $id => $module) {
            try {
                $module->boot();
            } catch (Throwable $exception) {
                $this->errors[$id] = $exception->getMessage();
                error_log(sprintf(
                    '[WPS-Cache] Module %s failed to boot: %s',
                    $module::class,
                    $exception->getMessage(),
                ));
            }
        }

        $this->booted = true;
    }

    /** Backward-compatible hook callback. */
    public function initializeCache(): void
    {
        $this->boot();
    }

    public function clearContentCaches(): bool
    {
        return $this->purge(false);
    }

    public function clearAllCaches(): bool
    {
        return $this->purge(true);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function get(string $id): ?Module
    {
        return $this->modules[$id] ?? null;
    }

    /** Backward-compatible alias used by dashboard metrics. */
    public function getDriver(string $id): ?Module
    {
        return $this->get($id);
    }

    public function clearHtmlCache(): bool
    {
        return $this->purgeOne('page', true);
    }

    public function clearRedisCache(): bool
    {
        return $this->purgeOne('redis');
    }

    public function clearVarnishCache(): bool
    {
        return $this->purgeOne('varnish');
    }

    public function clearUrl(string $url): bool
    {
        $pageCache = $this->modules['page'] ?? null;
        $success = $pageCache instanceof PageCache ? $pageCache->purgeUrl($url) : false;
        do_action('wpsc_cache_url_cleared', $url, $success);
        return $success;
    }

    public function clearPost(int $postId): bool
    {
        if ($postId <= 0 || !function_exists('get_permalink')) {
            return false;
        }
        $urls = array_filter([
            get_permalink($postId),
            home_url('/'),
            function_exists('get_post_type_archive_link') ? get_post_type_archive_link((string) get_post_type($postId)) : false,
        ], 'is_string');
        foreach (function_exists('get_object_taxonomies') ? get_object_taxonomies((string) get_post_type($postId)) : [] as $taxonomy) {
            foreach (function_exists('get_the_terms') ? (array) get_the_terms($postId, $taxonomy) : [] as $term) {
                $link = function_exists('get_term_link') ? get_term_link($term) : false;
                if (is_string($link)) {
                    $urls[] = $link;
                }
            }
        }
        $success = true;
        foreach (array_unique($urls) as $url) {
            $success = $this->clearUrl($url) && $success;
        }
        $varnish = $this->modules['varnish'] ?? null;
        if ($varnish instanceof VarnishCache) {
            $varnish->purgePost($postId);
        }
        $rest = $this->modules['rest'] ?? null;
        if ($rest instanceof RestResponseCache) {
            $rest->purge();
        }
        return $success;
    }

    private function purge(bool $includeRuntimeCaches): bool
    {
        $this->errors = [];

        foreach ($this->modules as $id => $module) {
            try {
                $module->purge();
            } catch (Throwable $exception) {
                $this->errors[$id] = $exception->getMessage();
            }
        }

        if ($includeRuntimeCaches) {
            wp_cache_flush();
            $this->clearDatabaseTransients();
            $this->invalidateDropInOpcache();
        }

        $this->forceCleanupHtmlDirectory();
        $success = $this->errors === [];
        do_action('wpsc_cache_cleared', $success, array_values($this->errors));

        return $success;
    }

    private function purgeOne(string $id, bool $fallbackToFilesystem = false): bool
    {
        $module = $this->modules[$id] ?? null;
        if ($module === null) {
            if ($fallbackToFilesystem) {
                $this->forceCleanupHtmlDirectory();
                return true;
            }

            return false;
        }

        try {
            $module->purge();
            return true;
        } catch (Throwable $exception) {
            $this->errors[$id] = $exception->getMessage();
            return false;
        }
    }

    private function clearDatabaseTransients(): void
    {
        global $wpdb;

        try {
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'");

            if (is_multisite() && isset($wpdb->sitemeta)) {
                $wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\\_site\\_transient\\_%'");
            } else {
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_%'");
            }
        } catch (Throwable $exception) {
            $this->errors['database'] = $exception->getMessage();
        }
    }

    private function invalidateDropInOpcache(): void
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        }

        @opcache_invalidate(WP_CONTENT_DIR . '/advanced-cache.php', true);
        @opcache_invalidate(WP_CONTENT_DIR . '/object-cache.php', true);
        @opcache_invalidate(ABSPATH . 'wp-config.php', true);
    }

    private function forceCleanupHtmlDirectory(): void
    {
        if (!defined('WPSC_CACHE_DIR')) {
            return;
        }

        $directory = WPSC_CACHE_DIR . 'html/';
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
        @mkdir($directory, 0755, true);
    }
}
