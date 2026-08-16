<?php

declare(strict_types=1);

namespace WPSCache\Admin;

use WPSCache\Config\Settings;
use WPSCache\Config\SettingsRepository;

final class NetworkController
{
    public function boot(): void
    {
        if (!is_multisite()) {
            return;
        }
        add_action('network_admin_menu', [$this, 'menu']);
        add_action('network_admin_edit_wpsc_network_settings', [$this, 'save']);
    }

    public function menu(): void
    {
        add_submenu_page('settings.php', 'WPS Cache Network', 'WPS Cache', 'manage_network_options', 'wps-cache-network', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_network_options')) {
            return;
        }
        $enabled = (bool) get_site_option('wpsc_network_settings_enabled', false);
        ?>
        <div class="wrap"><h1>WPS Cache Network</h1>
            <p>Central settings apply to every site while keeping each site's cache namespace and URLs isolated.</p>
            <form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=wpsc_network_settings')); ?>">
                <?php wp_nonce_field('wpsc_network_settings'); ?>
                <label><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>> Use centralized network settings</label>
                <p><label><input type="checkbox" name="copy_current" value="1"> Copy the current site's complete settings into the network profile</label></p>
                <?php submit_button('Save network mode'); ?>
            </form>
        </div>
        <?php
    }

    public function save(): never
    {
        check_admin_referer('wpsc_network_settings');
        if (!current_user_can('manage_network_options')) {
            wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
        }
        update_site_option('wpsc_network_settings_enabled', !empty($_POST['enabled']));
        if (!empty($_POST['copy_current'])) {
            $current = get_option(Settings::OPTION, Settings::defaults());
            update_site_option('wpsc_network_settings', is_array($current) ? $current : Settings::defaults());
        }
        do_action('wpscac_settings_updated', (new SettingsRepository())->load()->all());
        wp_safe_redirect(network_admin_url('settings.php?page=wps-cache-network&updated=1'));
        exit;
    }
}
