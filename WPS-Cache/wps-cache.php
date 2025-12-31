<?php

/**
 * Plugin Name: WPS-Cache
 * Plugin URI: https://github.com/Jumaron/WPS-Cache
 * Description: Free and Open-Source High-performance caching solution with Redis, Varnish, and HTML cache support.
 * Version: 0.0.3
 * Requires PHP: 8.3
 * Author: Jumaron
 * License: GPL v2 or later
 * Text Domain: wps-cache
 */

declare(strict_types=1);

namespace WPSCache;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// 1. Strict Requirement Check (Fail Fast)
if (version_compare(PHP_VERSION, '8.3', '<')) {
    add_action('admin_notices', function (): void {
        $message = sprintf(
            esc_html__('WPS-Cache requires PHP 8.3+. You are running PHP %s. The plugin has been disabled.', 'wps-cache'),
            PHP_VERSION
        );
        echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
    });
    return;
}

// 2. Constants Definition (Early binding)
const VERSION = '0.0.3';
const FILE    = __FILE__;
const DIR     = __DIR__;

// 3. PSR-4 Compliant Autoloader
spl_autoload_register(function (string $class): void {
    // Project-specific namespace prefix
    $prefix = 'WPSCache\\';

    // Base directory for the namespace prefix
    $base_dir = DIR . '/src/';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require_once $file;
    }
});

// 4. Bootstrap
try {
    Plugin::getInstance()->initialize();
} catch (\Throwable $e) {
    // Fail silently in production, log in debug
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('WPS-Cache Bootstrap Error: ' . $e->getMessage());
    }
}
