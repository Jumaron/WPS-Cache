<?php

declare(strict_types=1);

namespace WPSCache\Cache\Fragment;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;

/** Signed client-side hole punching for dynamic fragments inside static pages. */
final class FragmentEndpoint implements Module
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'fragment-endpoint';
    }

    public function boot(): void
    {
        if (!$this->settings->enabled('fragment_hole_punch')) {
            return;
        }
        add_action('rest_api_init', [$this, 'registerRoute']);
        add_action('wp_footer', [$this, 'renderLoader'], PHP_INT_MAX - 5);
    }

    public function registerRoute(): void
    {
        register_rest_route('wps-cache/v1', '/fragment/(?P<key>[a-z0-9_-]{1,64})', [
            'methods' => 'GET',
            'callback' => [$this, 'render'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function render(mixed $request): mixed
    {
        $key = is_object($request) && method_exists($request, 'get_param') ? sanitize_key((string) $request->get_param('key')) : '';
        $token = is_object($request) && method_exists($request, 'get_param') ? (string) $request->get_param('token') : '';
        if ($key === '' || !hash_equals(FragmentCache::token($key), $token)) {
            return new \WP_Error('invalid_fragment', 'Invalid fragment token.', ['status' => 403]);
        }
        $rateKey = 'wpsc_fragment_rate_' . hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), wp_salt('nonce'));
        $rate = (int) get_transient($rateKey);
        if ($rate >= 120) {
            return new \WP_Error('rate_limited', 'Fragment request rate exceeded.', ['status' => 429]);
        }
        set_transient($rateKey, $rate + 1, MINUTE_IN_SECONDS);
        $html = apply_filters('wpsc_render_fragment_' . $key, '', $key);
        $html = is_string($html) ? substr($html, 0, 65536) : '';
        $response = rest_ensure_response(['html' => $html]);
        if (is_object($response) && method_exists($response, 'header')) {
            $response->header('Cache-Control', 'private, no-store');
        }
        return $response;
    }

    public function renderLoader(): void
    {
        ?>
        <script id="wpsc-fragment-loader">(()=>{document.querySelectorAll('[data-wpsc-fragment]').forEach(el=>{fetch(el.dataset.wpscFragment,{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(r=>r.ok?r.json():Promise.reject()).then(v=>{if(v&&v.html!==undefined)el.innerHTML=v.html}).catch(()=>{})})})();</script>
        <?php
    }
}
