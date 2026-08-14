<?php

declare(strict_types=1);

namespace WPSCache\Scheduling;

use WPSCache\Cache\CacheManager;
use WPSCache\Contracts\Module;
use WPSCache\Maintenance\DatabaseOptimizer;

final class MaintenanceScheduler implements Module
{
    public const CACHE_HOOK = 'wpsc_cache_cleanup';
    public const DATABASE_HOOK = 'wpsc_db_cleanup';

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly DatabaseOptimizer $databaseOptimizer,
    ) {
    }

    public function id(): string
    {
        return 'maintenance-scheduler';
    }

    public function boot(): void
    {
        add_action(self::CACHE_HOOK, [$this->cacheManager, 'clearAllCaches']);
        add_action(self::DATABASE_HOOK, [$this->databaseOptimizer, 'runScheduledCleanup']);
    }

    public function scheduleCacheCleanup(): void
    {
        if (!wp_next_scheduled(self::CACHE_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CACHE_HOOK);
        }
    }

    /** @param array<string, mixed> $settings */
    public function updateDatabaseSchedule(array $settings): void
    {
        wp_clear_scheduled_hook(self::DATABASE_HOOK);
        $interval = (string) ($settings['db_schedule'] ?? 'disabled');

        if ($interval !== 'disabled') {
            $firstRun = (new \DateTimeImmutable('tomorrow 00:00:00'))->getTimestamp();
            wp_schedule_event($firstRun, $interval, self::DATABASE_HOOK);
        }
    }

    public function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CACHE_HOOK);
        wp_clear_scheduled_hook(self::DATABASE_HOOK);
    }
}
