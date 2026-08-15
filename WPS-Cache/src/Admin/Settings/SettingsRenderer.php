<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

/** Small, reusable view components for the settings application. */
class SettingsRenderer
{
    public function renderCard(
        string $title,
        string $description,
        callable $contentCallback,
        ?string $icon = null,
    ): void {
        ?>
        <section class="wpsc-section">
            <div class="wpsc-section-header">
                <?php if ($icon): ?>
                    <span class="wpsc-section-icon dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                <?php endif; ?>
                <div class="wpsc-section-heading">
                    <h3 class="wpsc-section-title"><?php echo esc_html($title); ?></h3>
                    <?php if ($description): ?>
                        <p class="wpsc-section-desc"><?php echo esc_html($description); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wpsc-section-body">
                <?php call_user_func($contentCallback); ?>
            </div>
        </section>
        <?php
    }

    /**
     * Hide a group until its controlling setting has the expected value.
     * Hidden values remain submitted so temporarily disabling a feature does
     * not erase a carefully tuned configuration.
     *
     * @param array<string, list<string>> $conditions
     * @param array<string, mixed> $settings
     */
    public function renderConditionalGroup(
        array $conditions,
        array $settings,
        callable $contentCallback,
        string $operator = 'all',
        string $label = 'Feature settings',
    ): void {
        $operator = $operator === 'any' ? 'any' : 'all';
        $matches = [];
        foreach ($conditions as $key => $accepted) {
            $current = $settings[$key] ?? null;
            $current = is_bool($current) ? ($current ? '1' : '0') : (string) $current;
            $matches[] = in_array($current, array_map('strval', $accepted), true);
        }
        $visible = $operator === 'any'
            ? in_array(true, $matches, true)
            : !in_array(false, $matches, true);
        $json = wp_json_encode($conditions);
        ?>
        <div class="wpsc-conditional<?php echo $visible ? ' is-visible' : ''; ?>"
             data-wpsc-conditional="<?php echo esc_attr(is_string($json) ? $json : '{}'); ?>"
             data-wpsc-condition-operator="<?php echo esc_attr($operator); ?>"
             aria-label="<?php echo esc_attr($label); ?>"
             <?php if (!$visible): ?>hidden<?php endif; ?>>
            <?php call_user_func($contentCallback); ?>
        </div>
        <?php
    }

    public function renderToggle(
        string $key,
        string $label,
        string $description,
        array $settings,
    ): void {
        $checked = !empty($settings[$key]);
        $descId = 'wpsc_' . esc_attr($key) . '_desc';
        ?>
        <div class="wpsc-setting-row wpsc-setting-row--toggle<?php echo $checked ? ' is-enabled' : ''; ?>">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                <?php if ($description): ?>
                    <div class="wpsc-setting-sub" id="<?php echo $descId; ?>"><?php echo esc_html($description); ?></div>
                <?php endif; ?>
            </div>
            <div class="wpsc-setting-control wpsc-toggle-control">
                <span class="wpsc-toggle-state" aria-hidden="true"><?php echo $checked ? esc_html__('On', 'wps-cache') : esc_html__('Off', 'wps-cache'); ?></span>
                <input type="hidden" name="wpsc_settings[<?php echo esc_attr($key); ?>]" value="0">
                <label class="wpsc-switch">
                    <input type="checkbox" role="switch" id="wpsc_<?php echo esc_attr($key); ?>"
                        name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                        value="1"
                        aria-checked="<?php echo $checked ? 'true' : 'false'; ?>"
                        <?php if ($description): ?>aria-describedby="<?php echo $descId; ?>"<?php endif; ?>
                        <?php checked($checked); ?>>
                    <span class="wpsc-slider"></span>
                </label>
            </div>
        </div>
        <?php
    }

    public function renderRadioGroup(
        string $key,
        string $label,
        string $description,
        array $settings,
        array $options,
    ): void {
        $current = $settings[$key] ?? '';
        $descId = 'wpsc_' . esc_attr($key) . '_desc';
        ?>
        <fieldset class="wpsc-setting-row wpsc-setting-row--stacked">
            <legend class="wpsc-setting-info">
                <span class="wpsc-setting-label" id="wpsc_label_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></span>
                <?php if ($description): ?>
                    <span class="wpsc-setting-sub" id="<?php echo $descId; ?>"><?php echo esc_html($description); ?></span>
                <?php endif; ?>
            </legend>
            <div class="wpsc-radio-grid"<?php if ($description): ?> aria-describedby="<?php echo $descId; ?>"<?php endif; ?>>
                <?php foreach ($options as $optValue => $optLabel): ?>
                    <label class="wpsc-radio-card">
                        <input type="radio" name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                               value="<?php echo esc_attr($optValue); ?>" <?php checked($current, $optValue); ?>>
                        <span class="wpsc-radio-card-content">
                            <span class="wpsc-radio-dot" aria-hidden="true"></span>
                            <strong><?php echo esc_html($optLabel); ?></strong>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    public function renderInput(
        string $key,
        string $label,
        string $description,
        array $settings,
        string $type = 'text',
        array $attrs = [],
    ): void {
        $value = $settings[$key] ?? '';
        if ($type === 'password') {
            if ($value !== '') {
                $attrs['placeholder'] = '••••••••';
            }
            $value = '';
        }

        $attrStr = '';
        foreach ($attrs as $attrKey => $attrValue) {
            $attrStr .= esc_attr((string) $attrKey) . '="' . esc_attr((string) $attrValue) . '" ';
        }
        $descId = 'wpsc_' . esc_attr($key) . '_desc';
        ?>
        <div class="wpsc-setting-row wpsc-setting-row--input">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                <?php if ($description): ?>
                    <div class="wpsc-setting-sub" id="<?php echo $descId; ?>"><?php echo esc_html($description); ?></div>
                <?php endif; ?>
            </div>
            <div class="wpsc-setting-control wpsc-input-wrap<?php echo $type === 'password' ? ' wpsc-input-wrap--password' : ''; ?>">
                <input type="<?php echo esc_attr($type); ?>" class="wpsc-input"
                       id="wpsc_<?php echo esc_attr($key); ?>"
                       name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                       value="<?php echo esc_attr($value); ?>"
                       <?php if ($description): ?>aria-describedby="<?php echo $descId; ?>"<?php endif; ?>
                       <?php echo $attrStr; ?>>
                <?php if ($type === 'password'): ?>
                    <button type="button" class="wpsc-icon-btn wpsc-password-toggle"
                            aria-controls="wpsc_<?php echo esc_attr($key); ?>"
                            aria-label="<?php esc_attr_e('Show password', 'wps-cache'); ?>"
                            title="<?php esc_attr_e('Show password', 'wps-cache'); ?>">
                        <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function renderTextarea(
        string $key,
        string $label,
        string $description,
        array $settings,
        array $attrs = [],
    ): void {
        $value = $settings[$key] ?? '';
        if (is_array($value)) {
            $value = implode("\n", $value);
        }

        $attrStr = '';
        foreach ($attrs as $attrKey => $attrValue) {
            $attrStr .= esc_attr((string) $attrKey) . '="' . esc_attr((string) $attrValue) . '" ';
        }
        $descId = 'wpsc_' . esc_attr($key) . '_desc';
        ?>
        <div class="wpsc-setting-row wpsc-setting-row--stacked">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                <?php if ($description): ?>
                    <div class="wpsc-setting-sub" id="<?php echo $descId; ?>"><?php echo esc_html($description); ?></div>
                <?php endif; ?>
            </div>
            <div class="wpsc-setting-control">
                <textarea class="wpsc-textarea" id="wpsc_<?php echo esc_attr($key); ?>"
                          name="wpsc_settings[<?php echo esc_attr($key); ?>]" rows="4" spellcheck="false"
                          <?php if ($description): ?>aria-describedby="<?php echo $descId; ?>"<?php endif; ?>
                          <?php echo $attrStr; ?>><?php echo esc_textarea($value); ?></textarea>
            </div>
        </div>
        <?php
    }
}
