<?php
/**
 * WPS Cache - Advanced Cache Drop-in
 * This file is automatically copied to wp-content/advanced-cache.php
 */

if (!defined('ABSPATH')) {
    die;
}

// Skip cache for specific conditions
if (
    defined('WP_CLI') || 
    defined('DOING_CRON') ||
    defined('DOING_AJAX') ||
    isset($_GET['preview']) ||
    isset($_POST) && !empty($_POST) ||
    is_admin() ||
    (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET')
) {
    return;
}

// Function to send cached file with proper headers
function wpsc_serve_cached_file($file, $type) {
    if ($content = file_get_contents($file)) {
        $cache_time = filemtime($file);
        $etag = md5($content);
        
        // Check if-none-match
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }

        // Set headers
        header('Content-Type: ' . $type);
        header('Cache-Control: public, max-age=31536000'); // 1 year
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $cache_time) . ' GMT');
        header('X-WPS-Cache: HIT');
        
        echo $content;
        exit;
    }
    return false;
}

// Check if this is a CSS or JS request
$request_uri = $_SERVER['REQUEST_URI'];
if (preg_match('/\.(?:css|js)\?.*ver=(\d+)$/', $request_uri, $matches)) {
    $cache_file = null;
    $content_type = null;
    
    // Extract the file path from the URI
    $file_path = parse_url($request_uri, PHP_URL_PATH);
    $file_key = md5($file_path);
    
    if (strpos($request_uri, '.css') !== false) {
        $cache_file = WP_CONTENT_DIR . '/cache/wps-cache/css/' . $file_key . '.css';
        $content_type = 'text/css; charset=UTF-8';
    } elseif (strpos($request_uri, '.js') !== false) {
        $cache_file = WP_CONTENT_DIR . '/cache/wps-cache/js/' . $file_key . '.js';
        $content_type = 'application/javascript; charset=UTF-8';
    }
    
    if ($cache_file && file_exists($cache_file)) {
        $cache_time = filemtime($cache_file);
        $settings = get_option('wpsc_settings', []);
        $cache_lifetime = $settings['cache_lifetime'] ?? 3600;
        
        if ((time() - $cache_time) < $cache_lifetime) {
            wpsc_serve_cached_file($cache_file, $content_type);
        }
    }
}

// Handle HTML cache
if (!empty($_GET)) {
    header('X-WPS-Cache: BYPASS');
    return;
}

$cache_key = md5($_SERVER['REQUEST_URI']);
$cache_file = WP_CONTENT_DIR . '/cache/wps-cache/html/' . $cache_key . '.html';

// Check if cache file exists and is valid
if (file_exists($cache_file)) {
    $cache_time = filemtime($cache_file);
    $cache_lifetime = 3600; // Default 1 hour

    // Get settings from options table
    if (function_exists('get_option')) {
        $settings = get_option('wpsc_settings', []);
        $cache_lifetime = $settings['cache_lifetime'] ?? 3600;
    }

    if ((time() - $cache_time) < $cache_lifetime) {
        wpsc_serve_cached_file($cache_file, 'text/html; charset=UTF-8');
    }
}

header('X-WPS-Cache: MISS');