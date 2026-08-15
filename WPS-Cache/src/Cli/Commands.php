<?php

declare(strict_types=1);

namespace WPSCache\Cli;

use WPSCache\Cache\CacheManager;
use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;
use WPSCache\Maintenance\DatabaseOptimizer;
use WPSCache\Optimization\Media\ImageOptimizer;
use WPSCache\Scheduling\PreloadUrlProvider;

final class Commands implements Module
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly PreloadUrlProvider $preload,
        private readonly ImageOptimizer $images,
        private readonly DatabaseOptimizer $database,
        private readonly Settings $settings,
    ) {
    }

    public function id(): string
    {
        return 'cli';
    }

    public function boot(): void
    {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('wps-cache', $this);
        }
    }

    /** @param list<string> $args @param array<string, mixed> $assoc */
    public function purge(array $args, array $assoc): void
    {
        $success = isset($assoc['url']) ? $this->cache->clearUrl((string) $assoc['url']) : $this->cache->clearAllCaches();
        $success ? \WP_CLI::success('Cache purged.') : \WP_CLI::error('Cache purge failed.');
    }

    /** @param list<string> $args @param array<string, mixed> $assoc */
    public function preload(array $args, array $assoc): void
    {
        $limit = max(1, min(100000, (int) ($assoc['limit'] ?? 10000)));
        $urls = $this->preload->discover($limit);
        $progress = \WP_CLI\Utils\make_progress_bar('Preloading', count($urls));
        foreach ($urls as $url) {
            wp_safe_remote_get($url, ['timeout' => 15, 'headers' => ['User-Agent' => 'WPS-Cache-CLI/' . WPSC_VERSION]]);
            $progress->tick();
        }
        $progress->finish();
    }

    /** @param list<string> $args @param array<string, mixed> $assoc */
    public function images(array $args, array $assoc): void
    {
        if (isset($assoc['path'])) {
            $path = (string) $assoc['path'];
            if (is_dir($path)) {
                $processed = 0;
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $entry) {
                    if ($entry->isFile() && in_array(strtolower($entry->getExtension()), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'pdf'], true)) {
                        !empty($assoc['restore']) ? $this->images->restore($entry->getPathname()) : $this->images->optimizeFile($entry->getPathname());
                        $processed++;
                    }
                }
                \WP_CLI::success($processed . ' custom-folder files processed.');
                return;
            }
            $result = !empty($assoc['restore']) ? ['success' => $this->images->restore($path)] : $this->images->optimizeFile($path);
            !empty($result['success']) ? \WP_CLI::success('Image processed.') : \WP_CLI::error((string) ($result['message'] ?? 'Image operation failed.'));
            return;
        }
        $query = new \WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => -1, 'fields' => 'ids']);
        foreach ($query->posts as $id) {
            $file = get_attached_file((int) $id);
            if (is_string($file)) {
                !empty($assoc['restore']) ? $this->images->restore($file) : $this->images->optimizeFile($file, (int) $id);
            }
        }
        \WP_CLI::success(count($query->posts) . ' media items processed.');
    }

    /** @param list<string> $args @param array<string, mixed> $assoc */
    public function database(array $args, array $assoc): void
    {
        $items = isset($assoc['all']) ? array_keys(DatabaseOptimizer::ITEMS) : $args;
        $count = $this->database->processCleanup($items);
        \WP_CLI::success($count . ' database operations completed.');
    }

    public function status(): void
    {
        \WP_CLI::line(wp_json_encode([
            'version' => WPSC_VERSION,
            'page_cache' => $this->settings->enabled('html_cache'),
            'redis' => $this->settings->enabled('redis_cache'),
            'varnish' => $this->settings->enabled('varnish_cache'),
        ], JSON_PRETTY_PRINT));
    }
}
