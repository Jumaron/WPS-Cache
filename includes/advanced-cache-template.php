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
    isset($_GET) && !empty($_GET) ||
    is_admin() ||
    (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET')
) {
    return;
}

// Get cache file path
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
        $content = file_get_contents($cache_file);
        if ($content !== false) {
            header('X-WPS-Cache: HIT');
            echo $content;
            exit;
        }
    }
}

header('X-WPS-Cache: MISS');