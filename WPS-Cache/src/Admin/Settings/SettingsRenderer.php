<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

/**
 * Renders the modern UI components (Cards, Toggles, Inputs)
 */
class SettingsRenderer
{
    /**
     * Renders the opening form tag and security fields
     */
    public function renderSettingsFormStart(): void
    {
        echo '<form method="post" action="options.php" class="wpsc-settings-form">';
        settings_fields('wpsc_settings');
    }

    /**
     * Renders the submit button and closing form tag
     */
    public function renderSettingsFormEnd(): void
    {
?>
        <div class="wpsc-submit-section" style="margin-top: 2rem; border-top: 1px solid var(--wpsc-border); padding-top: 1.5rem;">
            <?php submit_button(__('Save Changes', 'wps-cache'), 'primary wpsc-btn-primary large'); ?>
        </div>
        </form>
    <?php
    }

    /**
     * Renders a styled modern card wrapper
     */
    public function renderCard(string $title, string $description, callable $content_callback): void
    {
    ?>
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <div>
                    <h2><?php echo esc_html($title); ?></h2>
                    <?php if (!empty($description)): ?>
                        <p class="wpsc-setting-desc"><?php echo esc_html($description); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wpsc-card-body">
                <?php call_user_func($content_callback); ?>
            </div>
        </div>
    <?php
    }

    /**
     * Renders an iOS-style Toggle Switch Row
     * FIX: Added hidden input to handle unchecked state vs missing state
     */
    public function renderToggleRow(string $key, string $label, string $description, array $settings): void
    {
        $checked = isset($settings[$key]) && (bool)$settings[$key];
    ?>
        <div class="wpsc-setting-row">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr($key); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <p class="wpsc-setting-desc"><?php echo esc_html($description); ?></p>
            </div>
            <div class="wpsc-setting-control">
                <input type="hidden" name="wpsc_settings[<?php echo esc_attr($key); ?>]" value="0">

                <label class="wpsc-switch">
                    <input type="checkbox"
                        id="wpsc_<?php echo esc_attr($key); ?>"
                        name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                        value="1"
                        <?php checked($checked); ?>>
                    <span class="wpsc-slider"></span>
                </label>
            </div>
        </div>
    <?php
    }

    /**
     * Renders a standard Input Row (Text, Number, Password, Textarea, Select)
     */
    public function renderInputRow(string $key, string $label, string $description, array $settings, string $type = 'text', array $attrs = []): void
    {
        $value = $settings[$key] ?? '';

        // Convert array to string for textareas (e.g., excluded URLs)
        if (is_array($value) && $type === 'textarea') {
            $value = implode("\n", $value);
        }

        // Build extra attributes string
        $attr_str = '';
        foreach ($attrs as $k => $v) {
            if ($k !== 'options') { // Skip options array used for selects
                $attr_str .= esc_attr($k) . '="' . esc_attr($v) . '" ';
            }
        }
    ?>
        <div class="wpsc-setting-row" style="flex-direction: column; align-items: stretch;">
            <div class="wpsc-setting-info" style="margin-bottom: 0.75rem;">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr($key); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <?php if (!empty($description)): ?>
                    <p class="wpsc-setting-desc"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
            </div>

            <div class="wpsc-setting-control" style="width: 100%;">
                <?php if ($type === 'textarea'): ?>

                    <textarea class="wpsc-textarea"
                        id="wpsc_<?php echo esc_attr($key); ?>"
                        name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                        rows="4"
                        <?php echo $attr_str; ?>><?php echo esc_textarea($value); ?></textarea>

                <?php elseif ($type === 'select'): ?>

                    <select class="wpsc-input-text"
                        id="wpsc_<?php echo esc_attr($key); ?>"
                        name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                        <?php echo $attr_str; ?>>
                        <?php if (isset($attrs['options']) && is_array($attrs['options'])): ?>
                            <?php foreach ($attrs['options'] as $opt_val => $opt_label): ?>
                                <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($value, $opt_val); ?>>
                                    <?php echo esc_html($opt_label); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>

                <?php else: ?>

                    <input type="<?php echo esc_attr($type); ?>"
                        class="wpsc-input-<?php echo esc_attr($type === 'password' ? 'password' : 'text'); ?>"
                        id="wpsc_<?php echo esc_attr($key); ?>"
                        name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                        value="<?php echo esc_attr($value); ?>"
                        <?php echo $attr_str; ?>>

                <?php endif; ?>
            </div>
        </div>
<?php
    }
}
