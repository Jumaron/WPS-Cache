<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

/**
 * View Component responsible for generating HTML output.
 * Uses the CSS classes defined in Turn 6.
 */
class SettingsRenderer
{
    /**
     * Renders a styled Card container.
     */
    public function renderCard(string $title, string $description, callable $contentCallback): void
    {
?>
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <div>
                    <h2><?php echo esc_html($title); ?></h2>
                    <?php if ($description): ?>
                        <p class="wpsc-setting-desc"><?php echo esc_html($description); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wpsc-card-body">
                <?php call_user_func($contentCallback); ?>
            </div>
        </div>
    <?php
    }

    /**
     * Note the hidden input with value="0". 
     * This ensures the key exists in $_POST even if the checkbox is unchecked.
     * BUT it only exists if this specific renderToggle is called (i.e., we are on the right tab).
     */
    public function renderToggle(string $key, string $label, string $description, array $settings): void
    {
        $checked = !empty($settings[$key]);
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
     * Renders a standard Text/Number/Password Input.
     */
    public function renderInput(string $key, string $label, string $description, array $settings, string $type = 'text', array $attrs = []): void
    {
        $value = $settings[$key] ?? '';
        $class = ($type === 'number') ? 'wpsc-input-number' : 'wpsc-input-text';

        // Build attributes string
        $attrStr = '';
        foreach ($attrs as $k => $v) {
            $attrStr .= esc_attr($k) . '="' . esc_attr($v) . '" ';
        }
    ?>
        <div class="wpsc-setting-row">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr($key); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <?php if ($description): ?>
                    <p class="wpsc-setting-desc"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
            </div>
            <div class="wpsc-setting-control">
                <input type="<?php echo esc_attr($type); ?>"
                    class="<?php echo esc_attr($class); ?>"
                    id="wpsc_<?php echo esc_attr($key); ?>"
                    name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                    value="<?php echo esc_attr($value); ?>"
                    <?php echo $attrStr; ?>>
            </div>
        </div>
    <?php
    }

    /**
     * Renders a Textarea (handles array-to-string conversion).
     */
    public function renderTextarea(string $key, string $label, string $description, array $settings): void
    {
        $value = $settings[$key] ?? '';
        if (is_array($value)) {
            $value = implode("\n", $value);
        }
    ?>
        <div class="wpsc-setting-row" style="align-items: flex-start;">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr($key); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <?php if ($description): ?>
                    <p class="wpsc-setting-desc"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
            </div>
            <div class="wpsc-setting-control" style="width: 100%; max-width: 500px;">
                <textarea class="wpsc-textarea"
                    id="wpsc_<?php echo esc_attr($key); ?>"
                    name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                    rows="5"
                    spellcheck="false"><?php echo esc_textarea($value); ?></textarea>
            </div>
        </div>
    <?php
    }

    /**
     * Renders a Select Dropdown.
     */
    public function renderSelect(string $key, string $label, string $description, array $settings, array $options): void
    {
        $current = $settings[$key] ?? '';
    ?>
        <div class="wpsc-setting-row">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr($key); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <p class="wpsc-setting-desc"><?php echo esc_html($description); ?></p>
            </div>
            <div class="wpsc-setting-control">
                <select class="wpsc-input-text"
                    id="wpsc_<?php echo esc_attr($key); ?>"
                    name="wpsc_settings[<?php echo esc_attr($key); ?>]">
                    <?php foreach ($options as $optValue => $optLabel): ?>
                        <option value="<?php echo esc_attr($optValue); ?>" <?php selected($current, $optValue); ?>>
                            <?php echo esc_html($optLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
<?php
    }
}
