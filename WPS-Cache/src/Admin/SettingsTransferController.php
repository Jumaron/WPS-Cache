<?php

declare(strict_types=1);

namespace WPSCache\Admin;

use WPSCache\Admin\Settings\SettingsValidator;
use WPSCache\Config\Settings;

final class SettingsTransferController
{
    public function __construct(private readonly SettingsValidator $validator)
    {
    }

    public function boot(): void
    {
        add_action('admin_post_wpsc_export_settings', [$this, 'export']);
        add_action('admin_post_wpsc_import_settings', [$this, 'import']);
        add_action('admin_post_wpsc_apply_preset', [$this, 'preset']);
        add_action('admin_post_wpsc_rollback_settings', [$this, 'rollback']);
    }

    public function export(): never
    {
        $this->authorize('wpsc_export_settings');
        $settings = get_option(Settings::OPTION, Settings::defaults());
        $payload = wp_json_encode([
            'format' => 'wps-cache-settings',
            'version' => WPSC_VERSION,
            'exported_at' => gmdate(DATE_ATOM),
            'settings' => is_array($settings) ? $this->withoutSecrets($settings) : Settings::defaults(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="wps-cache-settings-' . gmdate('Y-m-d') . '.json"');
        echo $payload;
        exit;
    }

    public function import(): never
    {
        $this->authorize('wpsc_import_settings');
        $file = $_FILES['settings_file']['tmp_name'] ?? '';
        $content = is_string($file) && is_uploaded_file($file) ? file_get_contents($file) : false;
        $payload = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($payload) || ($payload['format'] ?? '') !== 'wps-cache-settings' || !is_array($payload['settings'] ?? null)) {
            $this->redirect('Invalid settings file.');
        }
        $clean = $this->validator->sanitizeSettings($payload['settings']);
        update_option(Settings::OPTION, $clean);
        $this->redirect('Settings imported.', false);
    }

    public function preset(): never
    {
        $this->authorize('wpsc_apply_preset');
        $name = sanitize_key($_POST['preset'] ?? 'balanced');
        $presets = [
            'safe' => ['css_minify' => true, 'js_minify' => true, 'html_minify' => true, 'js_defer' => false, 'js_delay' => false, 'css_async' => false],
            'balanced' => ['css_minify' => true, 'js_minify' => true, 'html_minify' => true, 'js_defer' => true, 'js_delay' => false, 'css_async' => false, 'media_lazy_backgrounds' => true],
            'aggressive' => ['css_minify' => true, 'js_minify' => true, 'html_minify' => true, 'js_defer' => true, 'js_defer_inline' => true, 'js_delay' => true, 'css_async' => true, 'remove_unused_css' => true, 'media_lazy_backgrounds' => true],
        ];
        if (!isset($presets[$name])) {
            $this->redirect('Unknown preset.');
        }
        $current = get_option(Settings::OPTION, Settings::defaults());
        $this->snapshot(is_array($current) ? $current : Settings::defaults());
        $next = array_replace(is_array($current) ? $current : Settings::defaults(), $presets[$name], ['settings_preset' => $name]);
        update_option(Settings::OPTION, $next);
        do_action('wpscac_settings_updated', $next);
        $this->redirect(ucfirst($name) . ' preset applied.', false);
    }

    public function rollback(): never
    {
        $this->authorize('wpsc_rollback_settings');
        $history = get_option('wpsc_settings_history', []);
        if (!is_array($history) || $history === []) {
            $this->redirect('No settings snapshot is available.');
        }
        $snapshot = array_pop($history);
        if (!is_array($snapshot['settings'] ?? null)) {
            $this->redirect('The latest snapshot is invalid.');
        }
        update_option(Settings::OPTION, $snapshot['settings']);
        update_option('wpsc_settings_history', $history, false);
        do_action('wpscac_settings_updated', $snapshot['settings']);
        $this->redirect('Previous settings restored.', false);
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function withoutSecrets(array $settings): array
    {
        $settings['redis_password'] = '';
        $settings['cf_api_token'] = '';
        return $settings;
    }

    private function authorize(string $nonce): void
    {
        check_admin_referer($nonce);
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
        }
    }

    /** @param array<string, mixed> $settings */
    private function snapshot(array $settings): void
    {
        $history = get_option('wpsc_settings_history', []);
        $history = is_array($history) ? $history : [];
        $history[] = ['created_at' => gmdate(DATE_ATOM), 'settings' => $settings];
        update_option('wpsc_settings_history', array_slice($history, -10), false);
    }

    private function redirect(string $message, bool $error = true): never
    {
        wp_safe_redirect(add_query_arg(['page' => 'wps-cache', 'tab' => 'tools', $error ? 'wpsc_error' : 'wpsc_notice' => $message], admin_url('admin.php')));
        exit;
    }
}
