<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

/**
 * View Component responsible for generating HTML output.
 * Updated to use "Sections" instead of Cards, and "Premium Radio Grids".
 */
class SettingsRenderer
{
    /**
     * Renders a Section (Unified Panel Style).
     * No more card borders, just a nice heading and spacing.
     */
    public function renderCard(
        string $title,
        string $description,
        callable $contentCallback,
    ): void {
        ?>
        <section class="wpsc-section">
            <div class="wpsc-section-header">
                <h3 class="wpsc-section-title">
                    <!-- Decorative icon based on title could go here -->
                    <?php echo esc_html($title); ?>
                </h3>
                <?php if ($description): ?>
                    <p class="wpsc-section-desc"><?php echo esc_html(
                        $description,
                    ); ?></p>
                <?php endif; ?>
            </div>
            <div class="wpsc-section-body">
                <?php call_user_func($contentCallback); ?>
            </div>
        </section>
        <?php
    }

    /**
     * Renders a toggle switch.
     */
    public function renderToggle(
        string $key,
        string $label,
        string $description,
        array $settings,
    ): void {
        $checked = !empty($settings[$key]);
        $descId = "wpsc_" . esc_attr($key) . "_desc";
        ?>
        <div class="wpsc-setting-row">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr(
                    $key,
                ); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <div class="wpsc-setting-sub" id="<?php echo $descId; ?>"><?php echo esc_html(
                    $description,
                ); ?></div>
            </div>
            <div class="wpsc-setting-control">
                <input type="hidden" name="wpsc_settings[<?php echo esc_attr(
                    $key,
                ); ?>]" value="0">
                <label class="wpsc-switch">
                    <input type="checkbox" role="switch" id="wpsc_<?php echo esc_attr(
                        $key,
                    ); ?>"
                        name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                        value="1"
                        aria-describedby="<?php echo $descId; ?>"
                        <?php checked($checked); ?>>
                    <span class="wpsc-slider"></span>
                </label>
            </div>
        </div>
        <?php
    }

    /**
     * Renders a "Premium Radio Grid".
     * Instead of small circles, these are selectable boxes.
     */
    public function renderRadioGroup(
        string $key,
        string $label,
        string $description,
        array $settings,
        array $options,
    ): void {
        $current = $settings[$key] ?? "";
        $descId = "wpsc_" . esc_attr($key) . "_desc";
        ?>
        <div class="wpsc-setting-row" style="flex-direction: column; align-items: flex-start;">
            <div class="wpsc-setting-info" style="margin-bottom: 10px;">
                <span class="wpsc-setting-label" id="wpsc_label_<?php echo esc_attr(
                    $key,
                ); ?>"><?php echo esc_html($label); ?></span>
                <?php if ($description): ?>
                    <div class="wpsc-setting-sub" id="<?php echo $descId; ?>"><?php echo esc_html(
                        $description,
                    ); ?></div>
                <?php endif; ?>
            </div>

            <div class="wpsc-radio-grid" role="radiogroup"
                 aria-labelledby="wpsc_label_<?php echo esc_attr($key); ?>"
                 <?php if ($description): ?>aria-describedby="<?php echo $descId; ?>"<?php endif; ?>>
                <?php foreach ($options as $optValue => $optLabel): ?>
                    <label class="wpsc-radio-card">
                        <input type="radio"
                               name="wpsc_settings[<?php echo esc_attr(
                                   $key,
                               ); ?>]"
                               value="<?php echo esc_attr($optValue); ?>"
                               <?php checked($current, $optValue); ?>>
                        <div class="wpsc-radio-card-content">
                            <strong><?php echo esc_html($optLabel); ?></strong>
                            <!-- Optional: You could add descriptions per option here later -->
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renders Input (Text/Password/Number).
     */
    public function renderInput(
        string $key,
        string $label,
        string $description,
        array $settings,
        string $type = "text",
        array $attrs = [],
    ): void {
        $value = $settings[$key] ?? "";
        if ($type === "password") {
            $value = "";
        } // Mask

        $attrStr = "";
        foreach ($attrs as $k => $v) {
            $attrStr .= esc_attr($k) . '="' . esc_attr($v) . '" ';
        }
        $descId = "wpsc_" . esc_attr($key) . "_desc";
        ?>
        <div class="wpsc-setting-row">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr(
                    $key,
                ); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <div class="wpsc-setting-sub" id="<?php echo $descId; ?>"><?php echo esc_html(
                    $description,
                ); ?></div>
            </div>
            <div class="wpsc-setting-control" style="width: 100%; max-width: 300px; <?php echo $type ===
            "password"
                ? "display:flex; gap:8px; align-items:center;"
                : ""; ?>">
                <input type="<?php echo esc_attr($type); ?>"
                       class="wpsc-input"
                       id="wpsc_<?php echo esc_attr($key); ?>"
                       name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                       value="<?php echo esc_attr($value); ?>"
                       aria-describedby="<?php echo $descId; ?>"
                       <?php echo $attrStr; ?>>
                <?php if ($type === "password"): ?>
                    <button type="button"
                            class="wpsc-dismiss-btn wpsc-password-toggle"
                            aria-controls="wpsc_<?php echo esc_attr($key); ?>"
                            aria-label="<?php esc_attr_e(
                                "Show password",
                                "wps-cache",
                            ); ?>"
                            title="<?php esc_attr_e(
                                "Show password",
                                "wps-cache",
                            ); ?>"
                            style="margin:0;">
                        <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renders Textarea.
     */
    public function renderTextarea(
        string $key,
        string $label,
        string $description,
        array $settings,
        array $attrs = [],
    ): void {
        $value = $settings[$key] ?? "";
        if (is_array($value)) {
            $value = implode("\n", $value);
        }

        $attrStr = "";
        foreach ($attrs as $k => $v) {
            $attrStr .= esc_attr($k) . '="' . esc_attr($v) . '" ';
        }
        $descId = "wpsc_" . esc_attr($key) . "_desc";
        ?>
        <div class="wpsc-setting-row" style="flex-direction: column; align-items: stretch;">
            <div class="wpsc-setting-info" style="margin-bottom: 10px;">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr(
                    $key,
                ); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <div class="wpsc-setting-sub" id="<?php echo $descId; ?>"><?php echo esc_html(
                    $description,
                ); ?></div>
            </div>
            <div class="wpsc-setting-control">
                <textarea class="wpsc-textarea"
                          id="wpsc_<?php echo esc_attr($key); ?>"
                          name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                          rows="5"
                          spellcheck="false"
                          aria-describedby="<?php echo $descId; ?>"
                          <?php echo $attrStr; ?>><?php echo esc_textarea(
                              $value,
                          ); ?></textarea>
            </div>
        </div>
        <?php
    }
}
