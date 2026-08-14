<?php

declare(strict_types=1);

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/.tmp/wp/');
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
defined('WPSC_VERSION') || define('WPSC_VERSION', '0.1.1');
defined('WPSC_PLUGIN_FILE') || define('WPSC_PLUGIN_FILE', dirname(__DIR__) . '/WPS-Cache/wps-cache.php');
defined('WPSC_PLUGIN_DIR') || define('WPSC_PLUGIN_DIR', dirname(__DIR__) . '/WPS-Cache/');
defined('WPSC_PLUGIN_URL') || define('WPSC_PLUGIN_URL', 'https://example.test/wp-content/plugins/WPS-Cache/');
defined('WPSC_CACHE_DIR') || define('WPSC_CACHE_DIR', WP_CONTENT_DIR . '/cache/wps-cache/');

const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS = 3600;
const DAY_IN_SECONDS = 86400;
const WEEK_IN_SECONDS = 604800;
const MONTH_IN_SECONDS = 2592000;
