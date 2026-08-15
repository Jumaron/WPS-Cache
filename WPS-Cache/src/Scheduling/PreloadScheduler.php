<?php

declare(strict_types=1);

namespace WPSCache\Scheduling;

use WPSCache\Contracts\Module;
use WPSCache\Infrastructure\Http\SameOriginUrlGuard;
use WPSCache\Config\Settings;

/**
 * Handles background tasks and scheduling.
 * Makes the "Preload Interval" setting actually work.
 */
final class PreloadScheduler implements Module
{
    public const HOOK = "wpsc_scheduled_preload";
    public const BATCH_HOOK = 'wpsc_preload_batch';
    private const QUEUE_OPTION = 'wpsc_preload_queue';

    private readonly Settings $settings;
    private readonly PreloadUrlProvider $provider;

    public function __construct(private readonly SameOriginUrlGuard $urlGuard, ?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
        $this->provider = new PreloadUrlProvider($this->settings, $urlGuard);
    }

    public function id(): string
    {
        return 'preload-scheduler';
    }

    public function boot(): void
    {
        add_action(self::HOOK, [$this, "runPreload"]);
        add_action(self::BATCH_HOOK, [$this, 'processBatch']);
    }

    /**
     * Called whenever settings are saved.
     * Reschedules the cron event if the interval changed.
     */
    public function updateSchedule(array $settings): void
    {
        $interval = $settings["preload_interval"] ?? "daily";

        // Always clear existing to reset the timer
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::BATCH_HOOK);
        delete_option(self::QUEUE_OPTION);

        if ($interval !== "disabled") {
            // Schedule first run 10 minutes from now (to not slow down save)
            wp_schedule_event(time() + 600, $interval, self::HOOK);
        }
    }

    public function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * The actual worker function that runs in the background.
     * Updated to preload both Desktop and Mobile versions.
     */
    public function runPreload(): void
    {
        $urls = $this->provider->discover(10000);
        update_option(self::QUEUE_OPTION, ['urls' => $urls, 'cursor' => 0, 'started' => time()], false);
        $this->processBatch();
    }

    public function processBatch(): void
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        if (!is_array($queue) || !is_array($queue['urls'] ?? null)) {
            return;
        }
        $urls = array_values(array_filter($queue['urls'], 'is_string'));
        $cursor = max(0, (int) ($queue['cursor'] ?? 0));
        $batchSize = max(1, min(500, $this->settings->integer('preload_batch_size')));
        $batch = array_slice($urls, $cursor, $batchSize);

        // Define User Agents
        $desktopUA = 'WPS-Cache-Cron-Preloader/' . WPSC_VERSION;
        // Matches regex in HTMLCache: /(Mobile|Android|...)/i
        $mobileUA =
            "Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1";
        $agents = [$desktopUA];
        if ($this->settings->string('cache_device_mode') !== 'shared') {
            $agents[] = $mobileUA;
        }
        if ($this->settings->string('cache_device_mode') === 'tablet') {
            $agents[] = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Safari/604.1';
        }

        // 2. Crawl them (Warm up cache)
        foreach ($batch as $url) {
            // Sentinel: Restrict preloader to local site only to prevent SSRF
            if (!$this->urlGuard->allows($url)) {
                continue;
            }

            foreach ($agents as $agent) {
                wp_safe_remote_get($url, [
                    "timeout" => 5,
                    "blocking" => true,
                    "cookies" => [],
                    "headers" => ["User-Agent" => $agent],
                    "sslverify" => apply_filters("https_local_ssl_verify", true),
                ]);
            }

        }
        $cursor += count($batch);
        if ($cursor < count($urls)) {
            update_option(self::QUEUE_OPTION, array_replace($queue, ['urls' => $urls, 'cursor' => $cursor]), false);
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + 15, self::BATCH_HOOK);
            }
            return;
        }
        delete_option(self::QUEUE_OPTION);
        update_option("wpsc_last_preload", current_time("mysql"));
    }

}
