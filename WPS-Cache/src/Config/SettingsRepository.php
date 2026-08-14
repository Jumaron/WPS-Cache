<?php

declare(strict_types=1);

namespace WPSCache\Config;

final class SettingsRepository
{
    public function load(): Settings
    {
        $stored = get_option(Settings::OPTION, []);

        return new Settings(is_array($stored) ? $stored : []);
    }

    public function installDefaults(): void
    {
        if (get_option(Settings::OPTION, false) === false) {
            update_option(Settings::OPTION, Settings::defaults());
        }
    }
}
