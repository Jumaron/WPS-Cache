<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Assets;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;

/** Rule-based per-page asset unloader. Rule format: handle|script/style|URL wildcard|role wildcard. */
final class ScriptManager implements Module
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'script-manager';
    }

    public function boot(): void
    {
        if ($this->settings->strings('asset_unload_rules') !== []) {
            add_action('wp_enqueue_scripts', [$this, 'applyRules'], PHP_INT_MAX);
        }
    }

    public function applyRules(): void
    {
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $role = 'visitor';
        if (is_user_logged_in() && function_exists('wp_get_current_user')) {
            $roles = wp_get_current_user()->roles ?? [];
            $role = sanitize_key((string) ($roles[0] ?? 'logged-in'));
        }
        foreach ($this->settings->strings('asset_unload_rules') as $line) {
            $parts = array_map('trim', explode('|', $line));
            [$handle, $type, $urlPattern, $rolePattern] = array_pad($parts, 4, '*');
            if ($handle === '' || !$this->matches($path, $urlPattern) || !$this->matches($role, $rolePattern)) {
                continue;
            }
            if ($type === 'style') {
                wp_dequeue_style($handle);
            } elseif ($type === 'script') {
                wp_dequeue_script($handle);
            }
            do_action('wpsc_asset_unloaded', $handle, $type, $path, $role);
        }
    }

    private function matches(string $value, string $pattern): bool
    {
        return preg_match('/^' . str_replace('\\*', '.*', preg_quote($pattern ?: '*', '/')) . '$/i', $value) === 1;
    }
}
