<?php

declare(strict_types=1);

namespace WPSCache\Admin\Settings;

/**
 * View Component responsible for generating HTML output.
 * Uses the clean tech CSS classes.
 */
class SettingsRenderer
{
    /**
     * Renders a styled Card container.
     */
    public function renderCard(
        string $title,
        string $description,
        callable $contentCallback,
    ): void {
        ?>
        <div class="wpsc-card">
            <div class="wpsc-card-header">
                <div>
                    <h2><?php echo esc_html($title); ?></h2>
                    <?php if ($description): ?>
                        <p class="wpsc-setting-desc"><?php echo esc_html(
                            $description,
                        ); ?></p>
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
     * Renders a toggle switch.
     */
    public function renderToggle(
        string $key,
        string $label,
        string $description,
        array $settings,
    ): void {
        $checked = !empty($settings[$key]);
        $descId = $description ? "wpsc_" . esc_attr($key) . "_desc" : "";
        ?>
        <div class="wpsc-setting-row">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr(
                    $key,
                ); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <p class="wpsc-setting-desc" <?php echo $descId
                    ? 'id="' . $descId . '"'
                    : ""; ?>>
                    <?php echo esc_html($description); ?>
                </p>
            </div>
            <div class="wpsc-setting-control">
                <input type="hidden" name="wpsc_settings[<?php echo esc_attr(
                    $key,
                ); ?>]" value="0">

                <label class="wpsc-switch">
                    <input type="checkbox" role="switch" id="wpsc_<?php echo esc_attr(
                        $key,
                    ); ?>"
                        aria-checked="<?php echo $checked
                            ? "true"
                            : "false"; ?>"
                        name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                        value="1"
                        <?php echo $descId
                            ? 'aria-describedby="' . $descId . '"'
                            : ""; ?>
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
    public function renderInput(
        string $key,
        string $label,
        string $description,
        array $settings,
        string $type = "text",
        array $attrs = [],
    ): void {
        $value = $settings[$key] ?? "";
        $isPasswordSet = false;

        if ($type === "password") {
            $isPasswordSet = !empty($value);
            $value = "";
        }

        $class = "wpsc-input-text";
        if ($type === "number") {
            $class = "wpsc-input-number";
        }
        if ($type === "password") {
            $class .= " wpsc-input-password";
        }

        $descId = $description ? "wpsc_" . esc_attr($key) . "_desc" : "";
        $statusId = "wpsc_" . esc_attr($key) . "_status";

        $ariaDescribedBy = [];
        if ($description) {
            $ariaDescribedBy[] = $descId;
        }
        if ($isPasswordSet) {
            $ariaDescribedBy[] = $statusId;
        }
        $ariaDescribedByStr = implode(" ", $ariaDescribedBy);

        $attrStr = "";
        foreach ($attrs as $k => $v) {
            $attrStr .= esc_attr($k) . '="' . esc_attr($v) . '" ';
        }
        ?>
        <div class="wpsc-setting-row">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr(
                    $key,
                ); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <?php if ($description): ?>
                    <p class="wpsc-setting-desc" id="<?php echo $descId; ?>"><?php echo esc_html(
    $description,
); ?></p>
                <?php endif; ?>
            </div>
            <div class="wpsc-setting-control" style="width: 100%; max-width: 340px;">
                <div style="position: relative;">
                    <input type="<?php echo esc_attr(
                        $type,
                    ); ?>" class="<?php echo esc_attr($class); ?>"
                        id="wpsc_<?php echo esc_attr(
                            $key,
                        ); ?>" name="wpsc_settings[<?php echo esc_attr(
    $key,
); ?>]"
                        value="<?php echo esc_attr($value); ?>"
                        <?php echo !empty($ariaDescribedByStr)
                            ? 'aria-describedby="' .
                                esc_attr($ariaDescribedByStr) .
                                '"'
                            : ""; ?>
                        <?php echo $attrStr; ?>>

                    <?php if ($type === "password"): ?>
                        <button type="button" class="wpsc-password-toggle" title="<?php esc_attr_e(
                            "Show password",
                            "wps-cache",
                        ); ?>"
                            aria-label="<?php esc_attr_e(
                                "Show password",
                                "wps-cache",
                            ); ?>" aria-controls="wpsc_<?php echo esc_attr(
    $key,
); ?>"
                            style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background:none; border:none; cursor:pointer; color: var(--wpsc-text-sub);">
                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                        </button>
                    <?php endif; ?>
                </div>

                <?php if ($isPasswordSet): ?>
                    <div id="<?php echo esc_attr($statusId); ?>"
                        style="margin-top: 5px; font-size: 12px; color: var(--wpsc-success); display: flex; align-items: center; gap: 4px;">
                        <span class="dashicons dashicons-yes" aria-hidden="true" style="font-size: 16px; width: 16px; height: 16px;"></span>
                        <?php esc_html_e(
                            "Password is set. Leave blank to keep unchanged.",
                            "wps-cache",
                        ); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renders a Textarea.
     */
    public function renderTextarea(
        string $key,
        string $label,
        string $description,
        array $settings,
        array $attrs = [],
    ): void {
        $value = $settings[$key] ?? "";
        $descId = $description ? "wpsc_" . esc_attr($key) . "_desc" : "";
        if (is_array($value)) {
            $value = implode("\n", $value);
        }

        $attrStr = "";
        foreach ($attrs as $k => $v) {
            $attrStr .= esc_attr($k) . '="' . esc_attr($v) . '" ';
        }
        ?>
        <div class="wpsc-setting-row" style="align-items: flex-start;">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr(
                    $key,
                ); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <?php if ($description): ?>
                    <p class="wpsc-setting-desc" id="<?php echo $descId; ?>"><?php echo esc_html(
    $description,
); ?></p>
                <?php endif; ?>
            </div>
            <div class="wpsc-setting-control" style="width: 100%; max-width: 500px;">
                <textarea class="wpsc-textarea" id="wpsc_<?php echo esc_attr(
                    $key,
                ); ?>"
                    name="wpsc_settings[<?php echo esc_attr($key); ?>]" rows="5"
                    <?php echo $descId
                        ? 'aria-describedby="' . $descId . '"'
                        : ""; ?>
                    spellcheck="false" autocorrect="off" autocapitalize="none" style="max-width: 100%;"
                    <?php echo $attrStr; ?>><?php echo esc_textarea(
    $value,
); ?></textarea>
            </div>
        </div>
        <?php
    }

    /**
     * Renders a Select Dropdown.
     */
    public function renderSelect(
        string $key,
        string $label,
        string $description,
        array $settings,
        array $options,
    ): void {
        $current = $settings[$key] ?? "";
        $descId = $description ? "wpsc_" . esc_attr($key) . "_desc" : "";
        ?>
        <div class="wpsc-setting-row">
            <div class="wpsc-setting-info">
                <label class="wpsc-setting-label" for="wpsc_<?php echo esc_attr(
                    $key,
                ); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <p class="wpsc-setting-desc" <?php echo $descId
                    ? 'id="' . $descId . '"'
                    : ""; ?>>
                    <?php echo esc_html($description); ?>
                </p>
            </div>
            <div class="wpsc-setting-control" style="width: 100%; max-width: 340px;">
                <select class="wpsc-input-text" id="wpsc_<?php echo esc_attr(
                    $key,
                ); ?>"
                    name="wpsc_settings[<?php echo esc_attr($key); ?>]"
                    <?php echo $descId
                        ? 'aria-describedby="' . $descId . '"'
                        : ""; ?>>
                    <?php foreach ($options as $optValue => $optLabel): ?>
                        <option value="<?php echo esc_attr(
                            $optValue,
                        ); ?>" <?php selected($current, $optValue); ?>>
                            <?php echo esc_html($optLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php
    }

    /**
     * Renders a Radio Button Group.
     */
    public function renderRadioGroup(
        string $key,
        string $label,
        string $description,
        array $settings,
        array $options,
    ): void {
        $current = $settings[$key] ?? "";
        $descId = $description ? "wpsc_" . esc_attr($key) . "_desc" : "";
        $labelId = "wpsc_" . esc_attr($key) . "_label";
        ?>
        <div class="wpsc-setting-row">
            <div class="wpsc-setting-info">
                <span class="wpsc-setting-label" id="<?php echo $labelId; ?>">
                    <?php echo esc_html($label); ?>
                </span>
                <?php if ($description): ?>
                    <p class="wpsc-setting-desc" id="<?php echo $descId; ?>">
                        <?php echo esc_html($description); ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="wpsc-setting-control" role="radiogroup" aria-labelledby="<?php echo $labelId; ?>"
                <?php echo $descId
                    ? 'aria-describedby="' . $descId . '"'
                    : ""; ?> style="width: 100%; max-width: 340px;">
                <?php foreach ($options as $optValue => $optLabel): ?>
                    <label class="wpsc-radio-item">
                        <input type="radio"
                               name="wpsc_settings[<?php echo esc_attr(
                                   $key,
                               ); ?>]"
                               value="<?php echo esc_attr($optValue); ?>"
                               <?php checked($current, $optValue); ?>>
                        <span><?php echo esc_html($optLabel); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
