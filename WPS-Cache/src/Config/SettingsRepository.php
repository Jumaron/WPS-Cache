<?php

declare(strict_types=1);

namespace WPSCache\Config;

final class SettingsRepository
{
    public function load(): Settings
    {
        if (is_multisite() && function_exists('get_site_option') && get_site_option('wpsc_network_settings_enabled', false)) {
            $network = get_site_option('wpsc_network_settings', []);
            if (is_array($network)) {
                return new Settings($network);
            }
        }
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
