<?php
/**
 * Plugin Name: WPS-Cache
 * Plugin URI: https://github.com/Jumaron/WPS-Cache
 * Description: Free and Open-Source High-performance caching solution with Redis, Varnish, and HTML cache support
 * Version: 0.0.3
 * Requires PHP: 8.3
 * Author: Jumaron
 * License: GPL v2 or later
 */

declare(strict_types=1);

namespace WPSCache;

if (!defined('ABSPATH')) {
    exit;
}

// Autoloader setup
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, __NAMESPACE__)) {
        $file = __DIR__ . '/src/' . str_replace(['WPSCache\\', '\\'], ['', '/'], $class) . '.php';
        if (file_exists($file)) {
            require_once($file);
        }
    }
});

// Bootstrap the plugin
Plugin::getInstance()->initialize();