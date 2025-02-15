<?php
/**
 * WPS Cache - Advanced Cache Drop-in
 * 
 * This file is automatically deployed to wp-content/advanced-cache.php
 * It handles serving cached files and manages cache bypassing logic
 */

if (!defined('ABSPATH')) {
    exit('Direct access not allowed.');
}

class WPSAdvancedCache {
    private const CACHE_BYPASS_CONDITIONS = [
        'WP_CLI',
        'DOING_CRON',
        'DOING_AJAX',
        'REST_REQUEST',
        'XMLRPC_REQUEST',
        'WP_ADMIN',
    ];

    private const CONTENT_TYPES = [
        'css'  => 'text/css; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8'
    ];

    private const DEFAULT_CACHE_LIFETIME = 3600;
    private const YEAR_IN_SECONDS = 31536000;

    private string $request_uri;
    private array $settings;
    private int $cache_lifetime;

    public function __construct() {
        // Unsash the REQUEST_URI before using it.
        $this->request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $this->settings = $this->getSettings();
        $this->cache_lifetime = $this->settings['cache_lifetime'] ?? self::DEFAULT_CACHE_LIFETIME;
    }

    /**
     * Main execution method
     */
    public function execute(): void {
        if ($this->shouldBypassCache()) {
            $this->setHeader('BYPASS');
            return;
        }

        // Check for static asset caching (CSS/JS)
        if ($this->handleStaticAsset()) {
            return;
        }

        // Handle HTML caching
        $this->handleHtmlCache();
    }

    /**
     * Checks if cache should be bypassed
     */
    private function shouldBypassCache(): bool {
        // Check PHP constants
        foreach (self::CACHE_BYPASS_CONDITIONS as $condition) {
            if (defined($condition) && constant($condition)) {
                return true;
            }
        }

        // Sanitize and unslash server variables before usage.
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? wp_unslash($_SERVER['REQUEST_METHOD']) : 'GET';
        $requested_with = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower(wp_unslash($_SERVER['HTTP_X_REQUESTED_WITH'])) : '';

        // Check request conditions
        return (
            isset($_GET['preview']) ||
            !empty($_POST) ||
            is_admin() ||
            $request_method !== 'GET' ||
            !empty($_GET) || // Query parameters bypass cache
            ($requested_with === 'xmlhttprequest') // AJAX requests
        );
    }

    /**
     * Handles static asset caching (CSS/JS)
     */
    private function handleStaticAsset(): bool {
        if (!preg_match('/\.(?:css|js)\?.*ver=(\d+)$/', $this->request_uri, $matches)) {
            return false;
        }

        // Use wp_parse_url() instead of parse_url() for consistent output.
        $file_path = wp_parse_url($this->request_uri, PHP_URL_PATH);
        if (!$file_path) {
            return false;
        }

        $extension = pathinfo($file_path, PATHINFO_EXTENSION);
        $file_key = md5($file_path);
        $cache_file = WP_CONTENT_DIR . "/cache/wps-cache/$extension/" . $file_key . ".$extension";

        if (file_exists($cache_file) && $this->isCacheValid($cache_file)) {
            return $this->serveCachedFile($cache_file, self::CONTENT_TYPES[$extension]);
        }

        return false;
    }

    /**
     * Handles HTML page caching
     */
    private function handleHtmlCache(): void {
        $cache_key = md5($this->request_uri);
        $cache_file = WP_CONTENT_DIR . '/cache/wps-cache/html/' . $cache_key . '.html';

        if (file_exists($cache_file) && $this->isCacheValid($cache_file)) {
            $this->serveCachedFile($cache_file, self::CONTENT_TYPES['html']);
            return;
        }

        $this->setHeader('MISS');
    }

    /**
     * Serves a cached file with appropriate headers
     */
    private function serveCachedFile(string $file, string $content_type): bool {
        $content = @file_get_contents($file);
        if ($content === false) {
            return false;
        }

        $cache_time = filemtime($file);
        $etag = '"' . md5($content) . '"';
        
        // Unsash and trim the HTTP_IF_NONE_MATCH header.
        $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'])) : '';
        if ($if_none_match === $etag) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }

        // Set cache headers
        header('Content-Type: ' . $content_type);
        header('Cache-Control: public, max-age=' . self::YEAR_IN_SECONDS);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $cache_time) . ' GMT');
        
        $this->setHeader('HIT');
        
        // Escape the cached content for safe output while preserving allowed HTML.
        echo wp_kses_post($content);
        exit;
    }

    /**
     * Checks if cache file is still valid
     */
    private function isCacheValid(string $file): bool {
        return (time() - filemtime($file)) < $this->cache_lifetime;
    }

    /**
     * Sets WPS Cache header
     */
    private function setHeader(string $status): void {
        header('X-WPS-Cache: ' . $status);
    }

    /**
     * Gets cache settings from WordPress options
     */
    private function getSettings(): array {
        if (!function_exists('get_option')) {
            return [];
        }

        $settings = get_option('wpsc_settings');
        return is_array($settings) ? $settings : [];
    }
}

// Execute caching logic
try {
    $cache = new WPSAdvancedCache();
    $cache->execute();
} catch (Throwable $e) {
    // Log error if debugging is enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('WPS Cache Error: ' . $e->getMessage());
    }
    
    // Continue normal WordPress execution
    header('X-WPS-Cache: ERROR');
}
