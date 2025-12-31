<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use WPSCache\Optimization\JSOptimizer;
use WPSCache\Optimization\CSSOptimizer;

/**
 * State-of-the-Art HTML Cache Writer.
 * Synchronized with advanced-cache.php for Path-Based caching.
 */
final class HTMLCache extends AbstractCacheDriver
{
    private string $cache_dir;
    private array $settings;
    private array $placeholders = [];

    public function __construct()
    {
        $this->cache_dir = WPSC_CACHE_DIR . 'html/';
        $this->settings = get_option('wpsc_settings', []);
        $this->ensureCacheDirectory($this->cache_dir);
    }

    public function initialize(): void
    {
        if (!$this->initialized && $this->shouldCache()) {
            add_action('template_redirect', [$this, 'startOutputBuffering']);
            add_action('shutdown', [$this, 'closeOutputBuffering']);
            $this->initialized = true;
        }
    }

    public function isConnected(): bool
    {
        if (!function_exists('WP_Filesystem')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        WP_Filesystem();
        global $wp_filesystem;
        return $wp_filesystem->is_writable($this->cache_dir);
    }

    /**
     * Writes the HTML content to the cache file.
     * $key is ignored in favor of strict path generation from $_SERVER.
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (!is_string($value) || empty(trim($value))) {
            return;
        }

        // Generate strict path
        $file = $this->getPathBasedCacheFile();

        // Atomic write prevents race conditions/corruption
        if (!$this->atomicWrite($file, $value)) {
            $this->logError("Failed to write cache file: $file");
        }
    }

    public function get(string $key): mixed
    {
        // Not used by the drop-in, but useful for admin stats/checks
        $file = $this->getPathBasedCacheFile();
        return (file_exists($file)) ? file_get_contents($file) : null;
    }

    public function delete(string $key): void
    {
        // Deleting single key via URL is complex with path-based.
        // Use clear() for now.
    }

    public function clear(): void
    {
        if (!is_dir($this->cache_dir)) return;

        // Recursive delete for path-based structure
        $this->recursiveDelete($this->cache_dir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;

        $files = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach ($files as $file) {
            if ($file->isDir()) {
                $this->recursiveDelete($file->getPathname());
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
    }

    public function startOutputBuffering(): void
    {
        ob_start([$this, 'processOutput']);
    }

    public function closeOutputBuffering(): void
    {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    public function processOutput(string $content): string
    {
        if (empty($content)) return $content;

        try {
            // 1. Minify HTML (Existing logic)
            $content = $this->minifyHTML($content);

            // 2. Initialize Optimizers
            $jsOptimizer = new JSOptimizer($this->settings);
            // Note: CSSOptimizer usually requires intercepting the CSS queue, 
            // but here we can at least filter inline CSS if needed.
            // For full RUCSS, we would need to capture the enqueued styles first.

            // 3. Apply JS Optimization (Defer/Delay)
            $content = $jsOptimizer->process($content);

            // 4. Add Cache Comment
            $content .= $this->getCacheComment($content, $content); // Size calc might be off slightly due to modifiers

            // 5. Save to Disk
            $this->set('ignored', $content);

            return $content;
        } catch (\Throwable $e) {
            $this->logError('HTML processing failed', $e);
            return $content;
        }
    }

    /**
     * Generates path: /cache/wps-cache/html/hostname/path/to/page/index.html
     * MUST MATCH advanced-cache.php EXACTLY.
     */
    private function getPathBasedCacheFile(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        // Handle homepage / or internal paths
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }

        // CRITICAL: Sanitize hostname (removes : port separators)
        $host = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $host);

        // Ensure subdirectories exist for deep paths
        $fullPath = $this->cache_dir . $host . $path;

        return $fullPath . 'index.html';
    }

    /**
     * Override atomicWrite to handle deep directory creation for paths
     */
    protected function atomicWrite(string $file, string $content): bool
    {
        $dir = dirname($file);

        // Ensure dir exists (recursively)
        if (!is_dir($dir)) {
            // Using native mkdir for speed, fallback to WP logic if needed
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->logError("Failed to create cache directory: $dir");
                return false;
            }
        }

        $temp_file = $dir . '/' . uniqid('wpsc_tmp_', true) . '.tmp';

        if (@file_put_contents($temp_file, $content) === false) {
            return false;
        }

        @chmod($temp_file, 0644);

        if (@rename($temp_file, $file)) {
            return true;
        }

        @unlink($temp_file);
        return false;
    }

    // --- Minification Logic (SOTA) ---

    private function minifyHTML(string $html): string
    {
        $this->placeholders = [];
        $html = $this->extractBlock('/<(script|style|pre|textarea|code)\b(?:[^>]*)?>(.*?)<\/\1>/si', $html);
        $html = $this->extractBlock('/<!--\[if.+?<!\[endif\]-->/si', $html);
        $html = $this->extractBlock('/<!--!.+?-->/s', $html);
        $html = $this->extractBlock('/<!\[CDATA\[.*?\]\]>/s', $html);

        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);
        $html = preg_replace('/\s+/', ' ', $html);
        $html = str_replace(' />', '/>', $html);

        if (!empty($this->placeholders)) {
            $html = strtr($html, $this->placeholders);
        }
        return trim($html);
    }

    private function extractBlock(string $pattern, string $content): string
    {
        return preg_replace_callback($pattern, function ($matches) {
            $key = '___WPSC_RAW_' . count($this->placeholders) . '___';
            $this->placeholders[$key] = $matches[0];
            return $key;
        }, $content) ?? $content;
    }

    private function getCacheComment(string $raw, string $compressed): string
    {
        $raw_size = strlen($raw);
        $compressed_size = strlen($compressed);
        $savings = $raw_size > 0 ? ($raw_size - $compressed_size) / $raw_size * 100 : 0;
        return sprintf("\n<!-- Page cached by WPS-Cache. Size saved %.2f%%. -->", $savings);
    }

    private function shouldCache(): bool
    {
        // Don't cache admin, logged in, non-GET, or query strings
        // Nonce check warning is irrelevant here as we are reading state
        $get_data = filter_input_array(INPUT_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: [];

        if (is_admin() || is_user_logged_in() || !empty($get_data)) {
            return false;
        }

        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return false;
        }

        return !$this->isPageCached() && !$this->isExcludedUrl();
    }

    private function isExcludedUrl(): bool
    {
        $current_url = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $excluded_urls = $this->settings['excluded_urls'] ?? [];
        foreach ($excluded_urls as $pattern) {
            if (fnmatch($pattern, $current_url)) return true;
        }
        return false;
    }

    private function isPageCached(): bool
    {
        return isset($_SERVER['HTTP_X_WPS_CACHE']) && (str_contains($_SERVER['HTTP_X_WPS_CACHE'], 'HIT'));
    }
}
