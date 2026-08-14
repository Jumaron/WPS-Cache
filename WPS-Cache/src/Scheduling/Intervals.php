<?php

declare(strict_types=1);

namespace WPSCache\Scheduling;

use WPSCache\Contracts\Module;

final class Intervals implements Module
{
    public function id(): string
    {
        return 'cron-intervals';
    }

    public function boot(): void
    {
        add_filter('cron_schedules', [$this, 'addIntervals']);
    }

    /** @param array<string, array{interval: int, display: string}> $schedules */
    public function addIntervals(array $schedules): array
    {
        $schedules['weekly'] ??= [
            'interval' => WEEK_IN_SECONDS,
            'display' => __('Once Weekly', 'wps-cache'),
        ];
        $schedules['monthly'] ??= [
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Once Monthly', 'wps-cache'),
        ];

        return $schedules;
    }
}
