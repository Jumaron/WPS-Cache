<?php

declare(strict_types=1);

$testRoot = __DIR__ . '/.tmp';
@mkdir($testRoot . '/wp/wp-content', 0755, true);

defined('ABSPATH') || define('ABSPATH', $testRoot . '/wp/');
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
defined('WPSC_VERSION') || define('WPSC_VERSION', '0.1.1');
defined('WPSC_PLUGIN_FILE') || define('WPSC_PLUGIN_FILE', dirname(__DIR__) . '/WPS-Cache/wps-cache.php');
defined('WPSC_PLUGIN_DIR') || define('WPSC_PLUGIN_DIR', dirname(__DIR__) . '/WPS-Cache/');
defined('WPSC_PLUGIN_URL') || define('WPSC_PLUGIN_URL', 'https://example.test/wp-content/plugins/WPS-Cache/');
defined('WPSC_CACHE_DIR') || define('WPSC_CACHE_DIR', WP_CONTENT_DIR . '/cache/wps-cache/');
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);
defined('WEEK_IN_SECONDS') || define('WEEK_IN_SECONDS', 604800);

spl_autoload_register(static function (string $class): void {
    $prefix = 'WPSCache\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = WPSC_PLUGIN_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

final class WPTestState
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var array<string, int> */
    public static array $scheduled = [];
    public static bool $admin = false;
    public static bool $loggedIn = false;
    public static bool $cart = false;
    public static bool $checkout = false;
    public static bool $account = false;

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$hooks = [];
        self::$scheduled = [];
        self::$admin = false;
        self::$loggedIn = false;
        self::$cart = false;
        self::$checkout = false;
        self::$account = false;
        $_GET = $_POST = $_COOKIE = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.test',
            'SERVER_SOFTWARE' => 'Apache',
        ];
    }
}

final class WooCommerce {}

final class WPDBStub
{
    public string $options = 'wp_options';
    public string $sitemeta = 'wp_sitemeta';
    /** @var list<string> */
    public array $queries = [];

    public function query(string $query): int
    {
        $this->queries[] = $query;
        return 1;
    }
}

$GLOBALS['wpdb'] = new WPDBStub();

function get_option(string $key, mixed $default = false): mixed { return WPTestState::$options[$key] ?? $default; }
function update_option(string $key, mixed $value): bool { WPTestState::$options[$key] = $value; return true; }
function delete_option(string $key): bool { unset(WPTestState::$options[$key]); return true; }
function get_transient(string $key): mixed { return WPTestState::$transients[$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl = 0): bool { WPTestState::$transients[$key] = $value; return true; }
function delete_transient(string $key): bool { unset(WPTestState::$transients[$key]); return true; }
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool { WPTestState::$hooks[$hook][$priority][] = [$callback, $acceptedArgs]; return true; }
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool { return add_action($hook, $callback, $priority, $acceptedArgs); }
function do_action(string $hook, mixed ...$args): void { foreach (WPTestState::$hooks[$hook] ?? [] as $callbacks) { foreach ($callbacks as [$callback, $accepted]) { $callback(...array_slice($args, 0, $accepted)); } } }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { foreach (WPTestState::$hooks[$hook] ?? [] as $callbacks) { foreach ($callbacks as [$callback, $accepted]) { $value = $callback(...array_slice([$value, ...$args], 0, $accepted)); } } return $value; }
function register_activation_hook(string $file, callable $callback): void { add_action('activate_' . basename($file), $callback); }
function register_deactivation_hook(string $file, callable $callback): void { add_action('deactivate_' . basename($file), $callback); }
function wp_next_scheduled(string $hook): int|false { return WPTestState::$scheduled[$hook] ?? false; }
function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool { WPTestState::$scheduled[$hook] = $timestamp; return true; }
function wp_clear_scheduled_hook(string $hook): int { unset(WPTestState::$scheduled[$hook]); return 1; }
function wp_cache_flush(): bool { return true; }
function is_multisite(): bool { return false; }
function is_admin(): bool { return WPTestState::$admin; }
function is_user_logged_in(): bool { return WPTestState::$loggedIn; }
function is_ssl(): bool { return true; }
function is_feed(): bool { return false; }
function is_trackback(): bool { return false; }
function is_singular(): bool { return false; }
function is_archive(): bool { return false; }
function is_home(): bool { return false; }
function is_category(): bool { return false; }
function is_tag(): bool { return false; }
function is_tax(): bool { return false; }
function is_cart(): bool { return WPTestState::$cart; }
function is_checkout(): bool { return WPTestState::$checkout; }
function is_account_page(): bool { return WPTestState::$account; }
function home_url(string $path = ''): string { return 'https://example.test' . ($path === '' ? '' : '/' . ltrim($path, '/')); }
function site_url(string $path = ''): string { return home_url($path); }
function get_home_url(): string { return home_url('/'); }
function content_url(string $path = ''): string { return 'https://example.test/wp-content' . ($path === '' ? '' : '/' . ltrim($path, '/')); }
function plugin_dir_path(string $file): string { return rtrim(dirname($file), '/\\') . '/'; }
function plugin_dir_url(string $file): string { return WPSC_PLUGIN_URL; }
function sanitize_text_field(mixed $value): string { return trim(strip_tags((string) $value)); }
function sanitize_key(mixed $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) ?? ''; }
function absint(mixed $value): int { return abs((int) $value); }
function esc_url_raw(mixed $value): string { return filter_var((string) $value, FILTER_VALIDATE_URL) ? (string) $value : ''; }
function __(string $value, string $domain = 'default'): string { return $value; }
function current_time(string $type): string { return '2026-08-14 12:00:00'; }
function flush_rewrite_rules(): void {}

WPTestState::reset();
