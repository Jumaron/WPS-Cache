<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * HTML cache implementation for static file caching
 */
final class HTMLCache implements CacheDriverInterface {
    private string $cache_dir;

    public function __construct() {
        $this->cache_dir = WPSC_CACHE_DIR . 'html/';
        if (!file_exists($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
    }

    public function initialize(): void {
        if (!is_admin()) {
            add_action('template_redirect', [$this, 'startOutputBuffering']);
            add_action('shutdown', [$this, 'closeOutputBuffering']);
        }
    }

    public function isConnected(): bool {
        return is_writable($this->cache_dir);
    }

    public function get(string $key): mixed {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        // Check if cache is expired
        $settings = get_option('wpsc_settings', []);
        $lifetime = $settings['cache_lifetime'] ?? 3600;
        
        if ((time() - filemtime($file)) > $lifetime) {
            unlink($file);
            return null;
        }

        return $content;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {
        if (!is_string($value)) {
            return;
        }

        $file = $this->getCacheFile($key);
        file_put_contents($file, $value);
    }

    public function delete(string $key): void {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function clear(): void {
        $files = glob($this->cache_dir . '*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function startOutputBuffering(): void {
        // Don't cache for logged in users or admin pages
        if (is_user_logged_in() || is_admin()) {
            return;
        }

        // Don't cache POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }

        // Don't cache if there are GET parameters
        if (!empty($_GET)) {
            return;
        }

        // Check excluded URLs
        $settings = get_option('wpsc_settings', []);
        $excluded_urls = $settings['excluded_urls'] ?? [];
        
        $current_url = $_SERVER['REQUEST_URI'];
        foreach ($excluded_urls as $pattern) {
            if (fnmatch($pattern, $current_url)) {
                return;
            }
        }

        ob_start([$this, 'processOutput']);
    }

    public function closeOutputBuffering(): void {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    private function processOutput(string $content): string {
        if (empty($content)) {
            return $content;
        }
    
        // Add cache signature as HTML comment
        $timestamp = date('Y-m-d H:i:s');
        $cache_signature = sprintf(
            "\n<!-- Page cached by WPS-Cache on %s -->\n",
            $timestamp
        );
    
        // Add signature before closing </body> tag if it exists
        // Otherwise append it to the end of content
        if (stripos($content, '</body>') !== false) {
            $content = preg_replace(
                '/<\/body>/i',
                $cache_signature . '</body>',
                $content,
                1
            );
        } else {
            $content .= $cache_signature;
        }
    
        // Generate cache key from URL
        $key = md5($_SERVER['REQUEST_URI']);
        $this->set($key, $content);
    
        return $content;
    }

    private function getCacheFile(string $key): string {
        return $this->cache_dir . $key . '.html';
    }
}