<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * SOTA Cleanup:
 * 1. Remove Cache Directory (Recursively)
 * 2. Remove Drop-ins (advanced-cache.php, object-cache.php)
 * 3. Remove DB Options
 * 4. Remove .htaccess modifications
 * 
 * @package WPSCache
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 1. Remove Drop-ins
$dropins = [
    WP_CONTENT_DIR . '/advanced-cache.php',
    WP_CONTENT_DIR . '/object-cache.php'
];

foreach ($dropins as $file) {
    if (file_exists($file)) {
        // Read first to ensure we don't delete another plugin's drop-in
        $content = file_get_contents($file);
        if (str_contains($content, 'WPS Cache') || str_contains($content, 'WPSCache')) {
            @unlink($file);
        }
    }
}

// 2. Remove Cache Directory
$cache_dir = WP_CONTENT_DIR . '/cache/wps-cache/';

// Simple recursive delete helper
function wpsc_uninstall_rrmdir($dir)
{
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . "/" . $object))
                    wpsc_uninstall_rrmdir($dir . DIRECTORY_SEPARATOR . $object);
                else
                    @unlink($dir . DIRECTORY_SEPARATOR . $object);
            }
        }
        @rmdir($dir);
    }
}

if (is_dir($cache_dir)) {
    wpsc_uninstall_rrmdir($cache_dir);
}

// 3. Cleanup .htaccess
$htaccess = ABSPATH . '.htaccess';
if (file_exists($htaccess) && is_writable($htaccess)) {
    $content = file_get_contents($htaccess);
    if ($content) {
        $new_content = preg_replace('/# BEGIN WPS Cache.*?# END WPS Cache\s*/s', '', $content);
        if ($new_content !== $content) {
            @file_put_contents($htaccess, $new_content);
        }
    }
}

// 4. Cleanup wp-config.php (WP_CACHE constant)
// Note: Modifying wp-config on uninstall is risky and often discouraged due to permissions,
// but we attempt it safely.
$config = ABSPATH . 'wp-config.php';
if (file_exists($config) && is_writable($config)) {
    $content = file_get_contents($config);
    $new_content = preg_replace("/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*true\s*\)\s*;\s*/i", "", $content);
    if ($new_content !== $content) {
        @file_put_contents($config, $new_content);
    }
}

// 5. Remove Database Options
delete_option('wpsc_settings');
delete_transient('wpsc_stats_cache');
delete_transient('wpsc_admin_notices');

// Clear opcode cache to ensure no old code remains in memory
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
