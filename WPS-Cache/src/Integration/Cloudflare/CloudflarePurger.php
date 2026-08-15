<?php

declare(strict_types=1);

namespace WPSCache\Integration\Cloudflare;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;

/** Invalidates Cloudflare's edge cache after a local cache purge. */
final class CloudflarePurger implements Module
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'cloudflare';
    }

    public function boot(): void
    {
        add_action('wpscac_settings_updated', [$this, 'syncEdgeRule'], 20, 1);
        if ($this->configured()) {
            add_action('wpsc_cache_cleared', [$this, 'purge'], 10, 0);
            add_action('wpsc_cache_url_cleared', [$this, 'purgeUrl'], 10, 1);
        }
    }

    public function purgeUrl(string $url): void
    {
        $this->request('purge_cache', ['files' => [$url]]);
    }

    /** Creates/updates the zone cache rule only after the administrator opts in. */
    /** @param array<string, mixed> $values */
    public function syncEdgeRule(array $values = []): void
    {
        $settings = $values === [] ? $this->settings : new Settings($values);
        if (!$settings->enabled('cf_enable') || !$settings->enabled('cf_edge_cache') || $settings->string('cf_zone_id') === '' || $settings->string('cf_api_token') === '') {
            return;
        }
        $zone = rawurlencode($settings->string('cf_zone_id'));
        $entrypoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone . '/rulesets/phases/http_request_cache_settings/entrypoint';
        $rule = [
            'ref' => 'wps_cache_full_page',
            'action' => 'set_cache_settings',
            'description' => 'WPS Cache anonymous WordPress HTML',
            'expression' => $this->edgeExpression($settings),
            'action_parameters' => [
                'cache' => true,
                'edge_ttl' => ['mode' => 'override_origin', 'default' => $settings->integer('cache_lifetime')],
            ],
            'enabled' => true,
        ];
        $existingResponse = $this->apiRequest($entrypoint, 'GET', [], $settings);
        $existingPayload = !is_wp_error($existingResponse) ? json_decode((string) wp_remote_retrieve_body($existingResponse), true) : null;
        $ruleset = is_array($existingPayload) && is_array($existingPayload['result'] ?? null) ? $existingPayload['result'] : [];
        $rulesetId = is_array($ruleset) ? (string) ($ruleset['id'] ?? '') : '';
        $managedId = '';
        foreach (is_array($ruleset['rules'] ?? null) ? $ruleset['rules'] : [] as $existingRule) {
            if (is_array($existingRule) && ($existingRule['ref'] ?? '') === 'wps_cache_full_page') {
                $managedId = (string) ($existingRule['id'] ?? '');
                break;
            }
        }
        if ($rulesetId !== '') {
            $endpoint = 'https://api.cloudflare.com/client/v4/zones/' . $zone . '/rulesets/' . rawurlencode($rulesetId) . '/rules';
            $response = $this->apiRequest($managedId === '' ? $endpoint : $endpoint . '/' . rawurlencode($managedId), $managedId === '' ? 'POST' : 'PATCH', $rule, $settings);
        } else {
            $response = $this->apiRequest($entrypoint, 'PUT', [
                'name' => 'WPS Cache full-page caching',
                'description' => 'Managed by WPS Cache',
                'kind' => 'zone',
                'phase' => 'http_request_cache_settings',
                'rules' => [$rule],
            ], $settings);
        }
        if (is_wp_error($response)) {
            error_log('[WPS-Cache] Cloudflare edge rule sync failed: ' . $response->get_error_message());
        }
    }

    public function purge(): void
    {
        if (!$this->configured()) {
            return;
        }

        $this->request('purge_cache', ['purge_everything' => true]);
    }

    /** @param array<string, mixed> $body */
    private function request(string $endpoint, array $body): void
    {
        if (!$this->configured()) {
            return;
        }
        $url = sprintf('https://api.cloudflare.com/client/v4/zones/%s/%s', rawurlencode($this->settings->string('cf_zone_id')), $endpoint);
        $response = $this->apiRequest($url, 'POST', $body);

        if (is_wp_error($response)) {
            error_log('[WPS-Cache] Cloudflare purge failed: ' . $response->get_error_message());
            return;
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            error_log(sprintf('[WPS-Cache] Cloudflare request returned HTTP %d.', $status));
        }
    }

    /** @param array<string, mixed> $body */
    private function apiRequest(string $url, string $method, array $body, ?Settings $settings = null): mixed
    {
        $settings ??= $this->settings;
        return wp_remote_request($url, [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $settings->string('cf_api_token'),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => 10,
        ]);
    }

    private function configured(): bool
    {
        return $this->settings->enabled('cf_enable')
            && $this->settings->string('cf_zone_id') !== ''
            && $this->settings->string('cf_api_token') !== '';
    }

    private function edgeExpression(Settings $settings): string
    {
        $clauses = [
            'http.request.method eq "GET"',
            'not http.request.uri.path contains "/wp-admin"',
            'not http.request.uri.path contains "/wp-login.php"',
            'not http.request.uri.query contains "add-to-cart="',
        ];
        $cookies = array_merge([
            'wordpress_logged_in_',
            'wp-postpass_',
            'woocommerce_items_in_cart',
            'woocommerce_cart_hash',
            'wp_woocommerce_session_',
        ], $settings->strings('cache_bypass_cookies'));
        foreach (array_unique($cookies) as $cookie) {
            $clauses[] = 'not http.cookie contains "' . $this->expressionString($cookie) . '"';
        }
        foreach (array_merge(['/cart', '/checkout', '/my-account'], $settings->strings('excluded_urls')) as $path) {
            if ($path !== '') {
                $clauses[] = 'not http.request.uri.path contains "' . $this->expressionString($path) . '"';
            }
        }
        return '(' . implode(' and ', $clauses) . ')';
    }

    private function expressionString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
