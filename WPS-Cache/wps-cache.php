<?php

/**
 * Plugin Name: WPS-Cache
 * Plugin URI: https://github.com/Jumaron/WPS-Cache
 * Description: Free and Open-Source High-performance caching solution with Redis, Varnish, and HTML cache support.
 * Version: 0.1.1
 * Requires PHP: 8.3
 * Author: Jumaron
 * License: GPL v2 or later
 * Text Domain: wps-cache
 */

declare(strict_types=1);

if (!defined("ABSPATH")) {
    exit();
}

if (version_compare(PHP_VERSION, '8.3', '<')) {
    add_action("admin_notices", function (): void {
        $message = sprintf(
            esc_html__(
                'WPS-Cache requires PHP 8.3+. You are running PHP %s. The plugin has been disabled.',
                'wps-cache',
            ),
            PHP_VERSION,
        );
        echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
    });
    return;
}

define('WPSC_VERSION', '0.1.1');
define('WPSC_PLUGIN_FILE', __FILE__);
define('WPSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPSC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPSC_CACHE_DIR', WP_CONTENT_DIR . '/cache/wps-cache/');

spl_autoload_register(static function (string $class): void {
    $prefix = 'WPSCache\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $relativeClass)) {
        return;
    }

    $file = WPSC_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

try {
    \WPSCache\Bootstrap\Application::boot();
} catch (\Throwable $exception) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[WPS-Cache] Bootstrap failed: ' . $exception->getMessage());
    }
}
