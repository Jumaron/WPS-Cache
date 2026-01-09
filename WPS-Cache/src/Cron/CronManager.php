<?php

declare(strict_types=1);

namespace WPSCache\Cron;

/**
 * Handles background tasks and scheduling.
 * Makes the "Preload Interval" setting actually work.
 */
class CronManager
{
    private const HOOK = "wpsc_scheduled_preload";

    public function initialize(): void
    {
        add_action(self::HOOK, [$this, "runPreload"]);
        add_action("wpscac_settings_updated", [$this, "updateSchedule"]);
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

        if ($interval !== "disabled") {
            // Schedule first run 10 minutes from now (to not slow down save)
            wp_schedule_event(time() + 600, $interval, self::HOOK);
        }
    }

    /**
     * The actual worker function that runs in the background.
     * Updated to preload both Desktop and Mobile versions.
     */
    public function runPreload(): void
    {
        // 1. Get Top URLs (Homepage + Recent Posts)
        // We limit to 50 to prevent server overload during background processing
        $urls = $this->getPriorityUrls(50);

        // Define User Agents
        $desktopUA = "WPS-Cache-Cron-Preloader/1.0";
        // Matches regex in HTMLCache: /(Mobile|Android|...)/i
        $mobileUA =
            "Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1";

        // 2. Crawl them (Warm up cache)
        foreach ($urls as $url) {
            // Sentinel: Restrict preloader to local site only to prevent SSRF
            if (!str_starts_with($url, home_url("/"))) {
                continue;
            }

            // --- Desktop Request ---
            wp_safe_remote_get($url, [
                "timeout" => 5,
                "blocking" => true,
                "cookies" => [],
                "headers" => ["User-Agent" => $desktopUA],
                "sslverify" => apply_filters("https_local_ssl_verify", true),
            ]);

            // Be nice to the CPU
            usleep(100000); // 0.1s pause

            // --- Mobile Request ---
            wp_safe_remote_get($url, [
                "timeout" => 5,
                "blocking" => true,
                "cookies" => [],
                "headers" => ["User-Agent" => $mobileUA],
                "sslverify" => apply_filters("https_local_ssl_verify", true),
            ]);

            // Be nice to the CPU
            usleep(100000); // 0.1s pause
        }

        // 3. Log last run time for the Admin UI
        update_option("wpsc_last_preload", current_time("mysql"));
    }

    private function getPriorityUrls(int $limit): array
    {
        $urls = [home_url("/")];

        $query = new \WP_Query([
            "post_type" => ["post", "page", "product"],
            "post_status" => "publish",
            "posts_per_page" => $limit,
            "fields" => "ids",
            "orderby" => "date",
            "order" => "DESC",
        ]);

        foreach ($query->posts as $id) {
            $urls[] = get_permalink($id);
        }

        return array_unique($urls);
    }
}
