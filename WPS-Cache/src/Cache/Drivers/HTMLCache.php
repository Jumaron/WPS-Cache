<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * State-of-the-Art HTML Cache & Minification.
 * Uses Path-Based caching for Server-Level Bypass.
 */
final class HTMLCache extends AbstractCacheDriver
{
    private string $cache_dir;
    private array $settings;

    // Protected blocks storage
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

    public function get(string $key): mixed
    {
        $file = $this->getPathBasedCacheFile();

        if (!is_readable($file)) return null;

        $lifetime = $this->settings['cache_lifetime'] ?? 3600;
        if ((time() - filemtime($file)) > $lifetime) {
            wp_delete_file($file);
            return null;
        }

        $content = file_get_contents($file);
        return ($content !== false) ? $content : null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (!is_string($value) || empty(trim($value))) return;

        // Note: $key arg is ignored here, we generate path strictly from $_SERVER
        $file = $this->getPathBasedCacheFile();

        if (!$this->atomicWrite($file, $value)) {
            $this->logError("Failed to write cache file: $file");
        }
    }

    public function delete(string $key): void
    {
        // Deletion by key not supported in path-mode, use clear()
    }

    public function clear(): void
    {
        if (!is_dir($this->cache_dir)) return;
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
            $minified = $this->minifyHTML($content);
            $minified .= $this->getCacheComment($content, $minified);

            $this->set('ignored', $minified);

            return $minified;
        } catch (\Throwable $e) {
            $this->logError('HTML processing failed', $e);
            return $content;
        }
    }

    /**
     * Generates path: /cache/wps-cache/html/hostname/path/to/page/index.html
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

        return $this->cache_dir . $host . $path . 'index.html';
    }

    private function minifyHTML(string $html): string
    {
        $this->placeholders = []; // Reset

        // 1. Extract protected blocks
        // We capture <script>, <style>, <pre>, <textarea>, and <code> blocks.
        // The regex uses \b to ensure we don't match <style-custom> and lazy .*? to capture content.
        // It robustly handles attributes containing '>' by relying on the closing tag structure.
        $html = $this->extractBlock('/<(script|style|pre|textarea|code)\b(?:[^>]*)?>(.*?)<\/\1>/si', $html);

        // Extract IE Conditionals and preserved comments <!--! ... -->
        $html = $this->extractBlock('/<!--\[if.+?<!\[endif\]-->/si', $html);
        $html = $this->extractBlock('/<!--!.+?-->/s', $html);
        $html = $this->extractBlock('/<!\[CDATA\[.*?\]\]>/s', $html);

        // 2. Minify the "Safe" HTML

        // Remove standard HTML comments
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);

        // Collapse Whitespace: Replace sequence of whitespace with single space
        $html = preg_replace('/\s+/', ' ', $html);

        // 3. Optimization: Self-Closing Tags
        // Remove space before self-closing slash: <br /> -> <br/>
        // This saves bytes and remains valid HTML5/XHTML.
        $html = str_replace(' />', '/>', $html);

        // 4. Restore protected blocks
        if (!empty($this->placeholders)) {
            $html = strtr($html, $this->placeholders);
        }

        return trim($html);
    }

    /**
     * Helper to extract regex matches and replace with placeholders
     */
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
        $get_data = filter_input_array(INPUT_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: [];
        if (is_admin() || is_user_logged_in() || !empty($get_data)) return false;
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') return false;
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
