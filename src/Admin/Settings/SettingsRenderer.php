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
                <h2><?php _e('Cache Types', 'wps-cache'); ?></h2>
                <div class="wpsc-cache-types-grid">
                    <?php $this->renderCacheTypeCards($settings); ?>
                </div>
            </div>
    
            <!-- Redis Settings -->
            <div id="redis-settings" class="wpsc-section" 
                 style="<?php echo (!($settings['redis_cache'] ?? false)) ? 'display: none;' : ''; ?>">
                <h2><?php _e('Redis Configuration', 'wps-cache'); ?></h2>
                <table class="form-table">
                    <?php $this->renderRedisSettings($settings); ?>
                </table>
            </div>
    
            <!-- Advanced Settings -->
            <div class="wpsc-section">
                <h2><?php _e('Advanced Settings', 'wps-cache'); ?></h2>
                <table class="form-table">
                    <?php $this->renderAdvancedSettings($settings); ?>
                </table>
            </div>
    
            <?php submit_button(__('Save Settings', 'wps-cache')); ?>
        </form>
        <?php
    }

    /**
     * Renders cache type selection cards
     */
    private function renderCacheTypeCards(array $settings): void {
        $cache_types = [
            'html_cache' => [
                'label' => __('Static HTML Cache', 'wps-cache'),
                'description' => __('Cache static HTML pages for faster delivery', 'wps-cache')
            ],
            'redis_cache' => [
                'label' => __('Redis Object Cache', 'wps-cache'),
                'description' => __('Cache database queries using Redis', 'wps-cache')
            ],
            'varnish_cache' => [
                'label' => __('Varnish Cache', 'wps-cache'),
                'description' => __('HTTP cache acceleration using Varnish', 'wps-cache')
            ],
            'css_minify' => [
                'label' => __('CSS Minification', 'wps-cache'),
                'description' => __('Minify and combine CSS files', 'wps-cache')
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
                'label' => __('Redis Host', 'wps-cache'),
                'type' => 'text',
                'description' => __('Redis server hostname or IP address', 'wps-cache')
            ],
            'redis_port' => [
                'label' => __('Redis Port', 'wps-cache'),
                'type' => 'number',
                'min' => 1,
                'max' => 65535,
                'description' => __('Redis server port number', 'wps-cache')
            ],
            'redis_password' => [
                'label' => __('Redis Password', 'wps-cache'),
                'type' => 'password',
                'description' => __('Redis server password (leave empty if no authentication required)', 'wps-cache')
            ],
            'redis_db' => [
                'label' => __('Redis Database', 'wps-cache'),
                'type' => 'number',
                'min' => 0,
                'max' => 15,
                'description' => __('Redis database index (0-15)', 'wps-cache')
            ],
            'redis_prefix' => [
                'label' => __('Redis Key Prefix', 'wps-cache'),
                'type' => 'text',
                'description' => __('Prefix for Redis keys (default: wpsc:)', 'wps-cache')
            ]
        ];

        foreach ($redis_fields as $key => $field) {
            $this->renderSettingField($key, $field, $settings[$key] ?? '');
        }

        // Render Redis options
        $this->renderCheckboxField('redis_persistent', __('Persistent Connections', 'wps-cache'),
            $settings['redis_persistent'] ?? false,
            __('Maintains persistent connections to Redis between requests', 'wps-cache'));
        
        $this->renderCheckboxField('redis_compression', __('Enable Compression', 'wps-cache'),
            $settings['redis_compression'] ?? true,
            __('Compress cache data to save memory', 'wps-cache'));
    }

    /**
     * Renders advanced settings fields
     */
    private function renderAdvancedSettings(array $settings): void {
        $advanced = $settings['advanced_settings'] ?? [];
        
        // Cache Lifetime
        $this->renderSettingField('cache_lifetime', [
            'label' => __('Cache Lifetime', 'wps-cache'),
            'type' => 'number',
            'min' => 60,
            'max' => 2592000,
            'description' => __('Cache lifetime in seconds (default: 3600)', 'wps-cache')
        ], $settings['cache_lifetime'] ?? 3600);

        // Object Cache Options Limit
        $this->renderSettingField('object_cache_alloptions_limit', [
            'label' => __('Alloptions Limit', 'wps-cache'),
            'type' => 'number',
            'min' => 100,
            'max' => 5000,
            'description' => __('Maximum number of options to store in alloptions cache', 'wps-cache')
        ], $advanced['object_cache_alloptions_limit'] ?? 1000);

        // Excluded URLs
        $this->renderTextareaField('excluded_urls', 
            __('Excluded URLs', 'wps-cache'),
            $settings['excluded_urls'] ?? [],
            __('Enter one URL per line', 'wps-cache'));

        // Cache Groups
        $this->renderTextareaField('cache_groups',
            __('Cache Groups', 'wps-cache'),
            $advanced['cache_groups'] ?? [],
            __('Enter one cache group per line', 'wps-cache'));
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
            <?php _e('Configure which types of caching you want to enable and their basic settings.', 'wps-cache'); ?>
        </p>
        <?php
    }

    /**
     * Renders Redis settings section information
     */
    public function renderRedisSettingsInfo(): void {
        ?>
        <p>
            <?php _e('Configure your Redis server connection settings. Redis provides powerful object caching capabilities.', 'wps-cache'); ?>
        </p>
        <?php
    }

    /**
     * Renders Varnish settings section information
     */
    public function renderVarnishSettingsInfo(): void {
        ?>
        <p>
            <?php _e('Configure Varnish cache settings if you are using Varnish as a reverse proxy cache.', 'wps-cache'); ?>
        </p>
        <?php
    }

    /**
     * Renders advanced settings section information
     */
    public function renderAdvancedSettingsInfo(): void {
        ?>
        <p>
            <?php _e('Advanced settings for fine-tuning cache behavior. Change these only if you know what you\'re doing.', 'wps-cache'); ?>
        </p>
        <?php
    }
}