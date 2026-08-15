<?php

declare(strict_types=1);

namespace WPSCache\Monitoring;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;

/** Privacy-conscious local RUM and loopback availability monitoring. */
final class PerformanceMonitor implements Module
{
    public const UPTIME_HOOK = 'wpsc_uptime_check';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'performance-monitor';
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        if ($this->settings->enabled('rum_enable')) {
            add_action('wp_footer', [$this, 'renderBeacon'], PHP_INT_MAX);
        }
        add_action(self::UPTIME_HOOK, [$this, 'checkUptime']);
        add_action('wpscac_settings_updated', [$this, 'updateSchedule'], 30, 1);
        if ($this->settings->enabled('uptime_monitor') && !wp_next_scheduled(self::UPTIME_HOOK)) {
            wp_schedule_event(time() + 300, $this->settings->string('uptime_interval'), self::UPTIME_HOOK);
        }
    }

    /** @param array<string, mixed> $settings */
    public function updateSchedule(array $settings): void
    {
        wp_clear_scheduled_hook(self::UPTIME_HOOK);
        if (!empty($settings['uptime_monitor'])) {
            $interval = in_array($settings['uptime_interval'] ?? 'hourly', ['hourly', 'daily'], true) ? $settings['uptime_interval'] : 'hourly';
            wp_schedule_event(time() + 300, (string) $interval, self::UPTIME_HOOK);
        }
    }

    public function registerRoutes(): void
    {
        register_rest_route('wps-cache/v1', '/rum', [
            'methods' => 'POST',
            'callback' => [$this, 'recordRum'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('wps-cache/v1', '/lab', [
            'methods' => 'GET',
            'callback' => [$this, 'runLabTest'],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        ]);
    }

    public function renderBeacon(): void
    {
        $endpoint = add_query_arg('token', $this->rumToken(), rest_url('wps-cache/v1/rum'));
        ?>
        <script id="wpsc-rum">(()=>{if(!navigator.sendBeacon||!window.PerformanceObserver||Math.random()>.1)return;let m={url:location.pathname},f=l=>l.getEntries().forEach(e=>{if(e.entryType==='largest-contentful-paint'){m.lcp=e.startTime;m.lcp_url=e.element&&(e.element.currentSrc||e.element.src)||''}if(e.entryType==='layout-shift'&&!e.hadRecentInput)m.cls=(m.cls||0)+e.value;if(e.entryType==='event')m.inp=Math.max(m.inp||0,e.duration)});['largest-contentful-paint','layout-shift','event'].forEach(t=>{try{new PerformanceObserver(f).observe({type:t,buffered:true,durationThreshold:40})}catch(e){}});addEventListener('load',()=>setTimeout(()=>{let n=performance.getEntriesByType('navigation')[0];if(n)m.ttfb=n.responseStart;m.above_fold=[...document.images].filter(i=>{let r=i.getBoundingClientRect(),s=getComputedStyle(i);return r.bottom>=0&&r.top<=innerHeight&&r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden'}).slice(0,20).map(i=>i.currentSrc||i.src);navigator.sendBeacon(<?php echo wp_json_encode($endpoint); ?>,new Blob([JSON.stringify(m)],{type:'application/json'}))},0),{once:true})})();</script>
        <?php
    }

    public function recordRum(mixed $request): mixed
    {
        if (!$this->settings->enabled('rum_enable')) {
            return new \WP_Error('disabled', 'RUM collection is disabled.', ['status' => 404]);
        }
        $token = is_object($request) && method_exists($request, 'get_param') ? (string) $request->get_param('token') : '';
        if (!hash_equals($this->rumToken(), $token)) {
            return new \WP_Error('invalid_token', 'Invalid RUM token.', ['status' => 403]);
        }
        $originHost = (string) parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
        if ($originHost !== '' && strcasecmp($originHost, (string) parse_url(home_url('/'), PHP_URL_HOST)) !== 0) {
            return new \WP_Error('invalid_origin', 'Cross-origin RUM submission denied.', ['status' => 403]);
        }
        $rateKey = 'wpsc_rum_rate_' . hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), wp_salt('nonce'));
        $rate = (int) get_transient($rateKey);
        if ($rate >= 30) {
            return new \WP_Error('rate_limited', 'RUM submission rate exceeded.', ['status' => 429]);
        }
        set_transient($rateKey, $rate + 1, MINUTE_IN_SECONDS);
        $payload = is_object($request) && method_exists($request, 'get_json_params') ? $request->get_json_params() : [];
        $payload = is_array($payload) ? $payload : [];
        $submittedPath = (string) (parse_url((string) ($payload['url'] ?? '/'), PHP_URL_PATH) ?: '/');
        $entry = ['time' => time(), 'url' => substr('/' . ltrim(sanitize_text_field($submittedPath), '/'), 0, 255)];
        foreach (['lcp', 'cls', 'inp', 'ttfb'] as $metric) {
            $entry[$metric] = max(0.0, min($metric === 'cls' ? 10.0 : 120000.0, (float) ($payload[$metric] ?? 0)));
        }
        $lcpUrl = esc_url_raw((string) ($payload['lcp_url'] ?? ''));
        if ($lcpUrl !== '' && parse_url($lcpUrl, PHP_URL_HOST) === parse_url(home_url('/'), PHP_URL_HOST)) {
            $entry['lcp_url'] = (string) (parse_url($lcpUrl, PHP_URL_PATH) ?: '');
            $map = get_option('wpsc_lcp_images', []);
            $map = is_array($map) ? $map : [];
            $map[$entry['url']] = ['image' => $entry['lcp_url'], 'updated' => time()];
            update_option('wpsc_lcp_images', array_slice($map, -1000, null, true), false);
        }
        $aboveFold = [];
        foreach (array_slice((array) ($payload['above_fold'] ?? []), 0, 20) as $imageUrl) {
            $imageUrl = esc_url_raw((string) $imageUrl);
            if ($imageUrl !== '' && parse_url($imageUrl, PHP_URL_HOST) === parse_url(home_url('/'), PHP_URL_HOST)) {
                $aboveFold[] = (string) (parse_url($imageUrl, PHP_URL_PATH) ?: '');
            }
        }
        if ($aboveFold !== []) {
            $profiles = get_option('wpsc_above_fold_images', []);
            $profiles = is_array($profiles) ? $profiles : [];
            $profiles[$entry['url']] = ['images' => array_values(array_unique($aboveFold)), 'updated' => time()];
            update_option('wpsc_above_fold_images', array_slice($profiles, -1000, null, true), false);
        }
        $entries = get_option('wpsc_rum_metrics', []);
        $entries = is_array($entries) ? $entries : [];
        $cutoff = time() - max(1, $this->settings->integer('metrics_retention')) * DAY_IN_SECONDS;
        $entries = array_values(array_filter($entries, static fn(mixed $item): bool => is_array($item) && (int) ($item['time'] ?? 0) >= $cutoff));
        $entries[] = $entry;
        update_option('wpsc_rum_metrics', array_slice($entries, -10000), false);
        return rest_ensure_response(['stored' => true]);
    }

    public function checkUptime(): void
    {
        if (!$this->settings->enabled('uptime_monitor')) {
            return;
        }
        $start = microtime(true);
        $response = wp_safe_remote_get(home_url('/'), ['timeout' => 15, 'redirection' => 2, 'headers' => ['User-Agent' => 'WPS-Cache-Uptime/' . WPSC_VERSION]]);
        $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $duration = (int) round((microtime(true) - $start) * 1000);
        $history = get_option('wpsc_uptime_history', []);
        $history = is_array($history) ? $history : [];
        $history[] = ['time' => time(), 'status' => $status, 'duration_ms' => $duration];
        update_option('wpsc_uptime_history', array_slice($history, -1000), false);
        $heartbeat = $this->settings->string('uptime_heartbeat_url');
        if ($heartbeat !== '') {
            wp_safe_remote_post($heartbeat, [
                'timeout' => 5,
                'blocking' => false,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode(['status' => $status, 'duration_ms' => $duration, 'checked_at' => gmdate(DATE_ATOM)]),
            ]);
        }
    }

    public function runLabTest(): mixed
    {
        if ($this->settings->string('pagespeed_api_key') !== '') {
            return $this->runPageSpeedInsights();
        }
        $start = microtime(true);
        $response = wp_safe_remote_get(home_url('/'), ['timeout' => 20, 'redirection' => 2, 'headers' => ['Cache-Control' => 'no-cache', 'User-Agent' => 'WPS-Cache-Lab/' . WPSC_VERSION]]);
        if (is_wp_error($response)) {
            return new \WP_Error('request_failed', $response->get_error_message(), ['status' => 502]);
        }
        return rest_ensure_response([
            'status' => wp_remote_retrieve_response_code($response),
            'total_ms' => (int) round((microtime(true) - $start) * 1000),
            'bytes' => strlen((string) wp_remote_retrieve_body($response)),
            'cache_status' => wp_remote_retrieve_header($response, 'x-wps-cache'),
            'scope' => 'HTTP loopback timing; browser rendering metrics come from RUM.',
        ]);
    }

    private function runPageSpeedInsights(): mixed
    {
        $endpoint = add_query_arg([
            'url' => home_url('/'),
            'strategy' => $this->settings->string('pagespeed_strategy') === 'desktop' ? 'desktop' : 'mobile',
            'category' => 'performance',
            'key' => $this->settings->string('pagespeed_api_key'),
        ], 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed');
        $response = wp_safe_remote_get($endpoint, ['timeout' => 120, 'redirection' => 0, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($response)) {
            return new \WP_Error('pagespeed_failed', $response->get_error_message(), ['status' => 502]);
        }
        $status = wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($payload)) {
            $message = is_array($payload) ? (string) ($payload['error']['message'] ?? 'PageSpeed Insights request failed.') : 'PageSpeed Insights returned invalid JSON.';
            return new \WP_Error('pagespeed_failed', $message, ['status' => 502]);
        }
        return rest_ensure_response(self::parsePageSpeedResult($payload, $this->settings->string('pagespeed_strategy')));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function parsePageSpeedResult(array $payload, string $strategy): array
    {
        $lighthouse = is_array($payload['lighthouseResult'] ?? null) ? $payload['lighthouseResult'] : [];
        $audits = is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : [];
        $metric = static fn(string $key): mixed => $audits[$key]['numericValue'] ?? null;
        $opportunities = [];
        foreach ($audits as $id => $audit) {
            $savings = is_array($audit) ? (float) ($audit['details']['overallSavingsMs'] ?? 0) : 0.0;
            if (!is_array($audit) || ($audit['details']['type'] ?? '') !== 'opportunity' || $savings <= 0) {
                continue;
            }
            $opportunities[] = ['id' => (string) $id, 'title' => (string) ($audit['title'] ?? $id), 'savings_ms' => (int) round($savings)];
        }
        usort($opportunities, static fn(array $left, array $right): int => $right['savings_ms'] <=> $left['savings_ms']);
        return [
            'provider' => 'Google PageSpeed Insights v5 / Lighthouse',
            'strategy' => $strategy === 'desktop' ? 'desktop' : 'mobile',
            'performance_score' => (int) round(100 * (float) ($lighthouse['categories']['performance']['score'] ?? 0)),
            'metrics' => [
                'fcp_ms' => $metric('first-contentful-paint'),
                'lcp_ms' => $metric('largest-contentful-paint'),
                'speed_index_ms' => $metric('speed-index'),
                'tbt_ms' => $metric('total-blocking-time'),
                'cls' => $metric('cumulative-layout-shift'),
            ],
            'opportunities' => array_slice($opportunities, 0, 10),
            'fetched_at' => (string) ($lighthouse['fetchTime'] ?? ''),
        ];
    }

    private function rumToken(): string
    {
        return hash_hmac('sha256', 'rum|' . home_url('/'), wp_salt('nonce'));
    }
}
