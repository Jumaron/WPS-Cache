<?php
declare(strict_types=1);

namespace WPSCache\Admin\Settings;

/**
 * Renders the settings interface for WPS Cache
 */
class SettingsRenderer {
    /**
     * Renders the main settings page
     */
    public function renderSettingsPage(): void {
        $settings = get_option('wpsc_settings');
        ?>
        <form method="post" action="options.php" class="wpsc-settings-form">
            <?php settings_fields('wpsc_settings'); ?>
    
            <!-- Cache Types Section -->
            <div class="wpsc-section">
                <h2><?php esc_html_e('Cache Types', 'WPS-Cache'); ?></h2>
                <div class="wpsc-cache-types-grid">
                    <?php $this->renderCacheTypeCards($settings); ?>
                </div>
            </div>
    
            <!-- Redis Settings -->
            <div id="redis-settings" class="wpsc-section" 
                 style="<?php echo (!($settings['redis_cache'] ?? false)) ? 'display: none;' : ''; ?>">
                <h2><?php esc_html_e('Redis Configuration', 'WPS-Cache'); ?></h2>
                <table class="form-table">
                    <?php $this->renderRedisSettings($settings); ?>
                </table>
            </div>
    
            <!-- Advanced Settings -->
            <div class="wpsc-section">
                <h2><?php esc_html_e('Advanced Settings', 'WPS-Cache'); ?></h2>
                <table class="form-table">
                    <?php $this->renderAdvancedSettings($settings); ?>
                </table>
            </div>
    
            <?php submit_button(__('Save Settings', 'WPS-Cache')); ?>
        </form>
        <?php
    }

    /**
     * Renders cache type selection cards
     */
    private function renderCacheTypeCards(array $settings): void {
        $cache_types = [
            'html_cache' => [
                'label'       => __('Static HTML Cache', 'WPS-Cache'),
                'description' => __('Cache static HTML pages for faster delivery', 'WPS-Cache')
            ],
            'redis_cache' => [
                'label'       => __('Redis Object Cache', 'WPS-Cache'),
                'description' => __('Cache database queries using Redis', 'WPS-Cache')
            ],
            'varnish_cache' => [
                'label'       => __('Varnish Cache', 'WPS-Cache'),
                'description' => __('HTTP cache acceleration using Varnish', 'WPS-Cache')
            ],
            'css_minify' => [
                'label'       => __('CSS Minification', 'WPS-Cache'),
                'description' => __('Minify CSS files', 'WPS-Cache')
            ],
            'js_minify' => [
                'label'       => __('JavaScript Minification', 'WPS-Cache'),
                'description' => __('Minify JavaScript files', 'WPS-Cache')
            ]
        ];

        foreach ($cache_types as $type => $info) {
            $this->renderCacheTypeCard($type, $info, $settings[$type] ?? false);
        }
    }

    /**
     * Renders individual cache type card
     */
    private function renderCacheTypeCard(string $type, array $info, bool $enabled): void {
        ?>
        <div class="wpsc-cache-type-card">
            <label>
                <input type="checkbox"
                       name="wpsc_settings[<?php echo esc_attr($type); ?>]"
                       value="1"
                       <?php checked($enabled); ?>
                       class="wpsc-toggle-settings"
                       data-target="<?php echo esc_attr(str_replace('_', '-', $type)); ?>-settings">
                <?php echo esc_html($info['label']); ?>
            </label>
            <p class="description">
                <?php echo esc_html($info['description']); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Renders Redis settings fields
     */
    private function renderRedisSettings(array $settings): void {
        $redis_fields = [
            'redis_host' => [
                'label'       => __('Redis Host', 'WPS-Cache'),
                'type'        => 'text',
                'description' => __('Redis server hostname or IP address', 'WPS-Cache')
            ],
            'redis_port' => [
                'label'       => __('Redis Port', 'WPS-Cache'),
                'type'        => 'number',
                'min'         => 1,
                'max'         => 65535,
                'description' => __('Redis server port number', 'WPS-Cache')
            ],
            'redis_password' => [
                'label'       => __('Redis Password', 'WPS-Cache'),
                'type'        => 'password',
                'description' => __('Redis server password (leave empty if no authentication required)', 'WPS-Cache')
            ],
            'redis_db' => [
                'label'       => __('Redis Database', 'WPS-Cache'),
                'type'        => 'number',
                'min'         => 0,
                'max'         => 15,
                'description' => __('Redis database index (0-15)', 'WPS-Cache')
            ],
            'redis_prefix' => [
                'label'       => __('Redis Key Prefix', 'WPS-Cache'),
                'type'        => 'text',
                'description' => __('Prefix for Redis keys (default: wpsc:)', 'WPS-Cache')
            ]
        ];

        foreach ($redis_fields as $key => $field) {
            $this->renderSettingField($key, $field, $settings[$key] ?? '');
        }

        // Render Redis options
        $this->renderCheckboxField(
            'redis_persistent',
            __('Persistent Connections', 'WPS-Cache'),
            $settings['redis_persistent'] ?? false,
            __('Maintains persistent connections to Redis between requests', 'WPS-Cache')
        );
        
        $this->renderCheckboxField(
            'redis_compression',
            __('Enable Compression', 'WPS-Cache'),
            $settings['redis_compression'] ?? true,
            __('Compress cache data to save memory', 'WPS-Cache')
        );
    }

    /**
     * Renders advanced settings fields
     */
    private function renderAdvancedSettings(array $settings): void {
        $advanced = $settings['advanced_settings'] ?? [];
        
        // Cache Lifetime
        $this->renderSettingField(
            'cache_lifetime',
            [
                'label'       => __('Cache Lifetime', 'WPS-Cache'),
                'type'        => 'number',
                'min'         => 60,
                'max'         => 2592000,
                'description' => __('Cache lifetime in seconds (default: 3600)', 'WPS-Cache')
            ],
            $settings['cache_lifetime'] ?? 3600
        );

        // Object Cache Options Limit
        $this->renderSettingField(
            'object_cache_alloptions_limit',
            [
                'label'       => __('Alloptions Limit', 'WPS-Cache'),
                'type'        => 'number',
                'min'         => 100,
                'max'         => 5000,
                'description' => __('Maximum number of options to store in alloptions cache', 'WPS-Cache')
            ],
            $advanced['object_cache_alloptions_limit'] ?? 1000
        );

        // Excluded URLs
        $this->renderTextareaField(
            'excluded_urls', 
            __('Excluded URLs', 'WPS-Cache'),
            $settings['excluded_urls'] ?? [],
            __('Enter one URL per line', 'WPS-Cache')
        );

        // Cache Groups
        $this->renderTextareaField(
            'cache_groups',
            __('Cache Groups', 'WPS-Cache'),
            $advanced['cache_groups'] ?? [],
            __('Enter one cache group per line', 'WPS-Cache')
        );
    }

    /**
     * Renders a generic setting field
     */
    private function renderSettingField(string $key, array $field, mixed $value): void {
        ?>
        <tr>
            <th scope="row">
                <label for="wpsc_<?php echo esc_attr($key); ?>">
                    <?php echo esc_html($field['label']); ?>
                </label>
            </th>
            <td>
                <input type="<?php echo esc_attr($field['type']); ?>"
                       id="wpsc_<?php echo esc_attr($key); ?>"
                       name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                       value="<?php echo esc_attr($value); ?>"
                       class="regular-text"
                       <?php if (isset($field['min'])) echo 'min="' . esc_attr($field['min']) . '"'; ?>
                       <?php if (isset($field['max'])) echo 'max="' . esc_attr($field['max']) . '"'; ?>>
                <p class="description">
                    <?php echo esc_html($field['description']); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * Renders a checkbox field
     */
    private function renderCheckboxField(string $key, string $label, bool $checked, string $description): void {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <label>
                    <input type="checkbox"
                           name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                           value="1"
                           <?php checked($checked); ?>>
                    <?php echo esc_html($description); ?>
                </label>
            </td>
        </tr>
        <?php
    }

    /**
     * Renders a textarea field
     */
    private function renderTextareaField(string $key, string $label, array $values, string $description): void {
        ?>
        <tr>
            <th scope="row">
                <label for="wpsc_<?php echo esc_attr($key); ?>">
                    <?php echo esc_html($label); ?>
                </label>
            </th>
            <td>
                <textarea id="wpsc_<?php echo esc_attr($key); ?>"
                          name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                          rows="5"
                          class="large-text code"><?php echo esc_textarea(implode("\n", $values)); ?></textarea>
                <p class="description">
                    <?php echo esc_html($description); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * Renders settings section information
     */
    public function renderCacheSettingsInfo(): void {
        ?>
        <p>
            <?php esc_html_e('Configure which types of caching you want to enable and their basic settings.', 'WPS-Cache'); ?>
        </p>
        <?php
    }

    /**
     * Renders Redis settings section information
     */
    public function renderRedisSettingsInfo(): void {
        ?>
        <p>
            <?php esc_html_e('Configure your Redis server connection settings. Redis provides powerful object caching capabilities.', 'WPS-Cache'); ?>
        </p>
        <?php
    }

    /**
     * Renders Varnish settings section information
     */
    public function renderVarnishSettingsInfo(): void {
        ?>
        <p>
            <?php esc_html_e('Configure Varnish cache settings if you are using Varnish as a reverse proxy cache.', 'WPS-Cache'); ?>
        </p>
        <?php
    }

    /**
     * Renders advanced settings section information
     */
    public function renderAdvancedSettingsInfo(): void {
        ?>
        <p>
            <?php esc_html_e('Advanced settings for fine-tuning cache behavior. Change these only if you know what you\'re doing.', 'WPS-Cache'); ?>
        </p>
        <?php
    }
}