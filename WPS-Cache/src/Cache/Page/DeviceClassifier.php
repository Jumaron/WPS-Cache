<?php

declare(strict_types=1);

namespace WPSCache\Cache\Page;

final class DeviceClassifier
{
    public static function suffix(string $userAgent, string $mode): string
    {
        if ($mode === 'shared' || $userAgent === '') {
            return '';
        }

        $tablet = preg_match('/(iPad|Tablet|Nexus (?:7|9|10)|Kindle|Silk\/)(?!.*Mobile)/i', $userAgent) === 1;
        if ($mode === 'tablet' && $tablet) {
            return '-tablet';
        }

        $mobile = preg_match('/(Mobile|Android|iPhone|iPod|BlackBerry|Opera Mini|Opera Mobi)/i', $userAgent) === 1;
        return ($mobile || $tablet) ? '-mobile' : '';
    }
}
