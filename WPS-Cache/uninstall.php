<?php

declare(strict_types=1);

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
if (!defined("WP_UNINSTALL_PLUGIN")) {
    exit();
}

// 1. Remove Drop-ins
$removedAdvancedCache = false;
$dropins = [
    WP_CONTENT_DIR . "/advanced-cache.php",
    WP_CONTENT_DIR . "/object-cache.php",
];

foreach ($dropins as $file) {
    if (file_exists($file)) {
        // Read first to ensure we don't delete another plugin's drop-in
        $content = file_get_contents($file);
        if (is_string($content) && (
            str_contains($content, "WPS Cache") ||
            str_contains($content, "WPSCache") ||
            str_contains($content, "WPS-Cache")
        )) {
            if (@unlink($file) && basename($file) === 'advanced-cache.php') {
                $removedAdvancedCache = true;
            }
        }
    }
}

// 2. Remove Cache Directory
$cache_dir = WP_CONTENT_DIR . "/cache/wps-cache/";

// Simple recursive delete helper
function wpsc_uninstall_rrmdir(string $dir): void
{
    if (is_dir($dir)) {
        $objects = scandir($dir);
        if ($objects === false) {
            return;
        }
        foreach ($objects as $object) {
            if ($object !== "." && $object !== "..") {
                if (
                    is_dir($dir . DIRECTORY_SEPARATOR . $object) &&
                    !is_link($dir . "/" . $object)
                ) {
                    wpsc_uninstall_rrmdir($dir . DIRECTORY_SEPARATOR . $object);
                } else {
                    @unlink($dir . DIRECTORY_SEPARATOR . $object);
                }
            }
        }
        @rmdir($dir);
    }
}

if (is_dir($cache_dir)) {
    wpsc_uninstall_rrmdir($cache_dir);
}

// 3. Cleanup .htaccess
$htaccess = ABSPATH . ".htaccess";
if (file_exists($htaccess) && is_writable($htaccess)) {
    $content = file_get_contents($htaccess);
    if ($content) {
        $new_content = preg_replace(
            '/^[ \t]*# BEGIN WPS Cache[^\r\n]*\R.*?^[ \t]*# END WPS Cache[^\r\n]*(?:\R)?/ms',
            "",
            $content,
        );
        if ($new_content !== $content) {
            @file_put_contents($htaccess, $new_content, LOCK_EX);
        }
    }
}

// 4. Cleanup wp-config.php (WP_CACHE constant)
// Note: Modifying wp-config on uninstall is risky and often discouraged due to permissions,
// but we attempt it safely.
$config = ABSPATH . "wp-config.php";
if (!file_exists($config)) {
    $config = dirname(rtrim(ABSPATH, '/\\')) . '/wp-config.php';
}
if ($removedAdvancedCache && file_exists($config) && is_writable($config)) {
    $content = file_get_contents($config);
    $new_content = preg_replace(
        "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*true\s*\)\s*;\s*/i",
        "",
        $content,
    );
    if ($new_content !== $content) {
        @file_put_contents($config, $new_content, LOCK_EX);
    }
}

// 5. Remove Database Options
delete_option("wpsc_settings");
delete_option('wpsc_version');
delete_transient("wpsc_stats_cache");
delete_transient("wpsc_admin_notices");
delete_option('wpsc_last_preload');
delete_option('wpsc_preload_queue');
delete_option('wpsc_rest_cache_keys');
delete_option('wpsc_settings_history');
delete_option('wpsc_image_stats');
delete_option('wpsc_rum_metrics');
delete_option('wpsc_uptime_history');
delete_option('wpsc_lcp_images');
delete_option('wpsc_above_fold_images');
delete_option('wpsc_image_background_cursor');
if (is_multisite()) {
    delete_site_option('wpsc_network_settings_enabled');
    delete_site_option('wpsc_network_settings');
}
wp_clear_scheduled_hook('wpsc_cache_cleanup');
wp_clear_scheduled_hook('wpsc_scheduled_preload');
wp_clear_scheduled_hook('wpsc_db_cleanup');
wp_clear_scheduled_hook('wpsc_preload_batch');
wp_clear_scheduled_hook('wpsc_uptime_check');
wp_clear_scheduled_hook('wpsc_image_background_optimize');

// Clear opcode cache to ensure no old code remains in memory
if (function_exists("opcache_invalidate")) {
    @opcache_invalidate(WP_CONTENT_DIR . "/advanced-cache.php", true);
    @opcache_invalidate(WP_CONTENT_DIR . "/object-cache.php", true);
    @opcache_invalidate(ABSPATH . "wp-config.php", true);
}
