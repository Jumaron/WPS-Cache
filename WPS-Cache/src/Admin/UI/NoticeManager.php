<?php

declare(strict_types=1);

namespace WPSCache\Admin\UI;

class NoticeManager
{
    private const TRANSIENT_KEY = "wpsc_admin_notices";

    public function __construct() {}

    public function add(string $message, string $type = "success"): void
    {
        $notices = get_transient(self::TRANSIENT_KEY) ?: [];
        $notices[] = [
            "message" => $message,
            "type" => $type,
        ];
        set_transient(self::TRANSIENT_KEY, $notices, 60);
    }

    public function renderNotices(): void
    {
        $notices = get_transient(self::TRANSIENT_KEY);

        if (empty($notices) || !is_array($notices)) {
            return;
        }

        delete_transient(self::TRANSIENT_KEY);

        // Padding is now applied here, only if notices exist
        echo '<div class="wpsc-notices-container" style="padding: 1.5rem 2.5rem 0; display: flex; flex-direction: column; gap: 10px;">';

        foreach ($notices as $notice) {

            $typeClass = match ($notice["type"]) {
                "error" => "error",
                "warning" => "warning",
                default => "success",
            };

            $role = match ($notice["type"]) {
                "error" => "alert",
                default => "status",
            };

            $icon = match ($notice["type"]) {
                "error" => "dashicons-warning",
                "warning" => "dashicons-flag",
                default => "dashicons-yes-alt",
            };
            ?>
            <div class="wpsc-notice <?php echo esc_attr(
                $typeClass,
            ); ?>" role="<?php echo esc_attr($role); ?>">
                <div class="wpsc-notice-content">
                    <span class="dashicons <?php echo esc_attr(
                        $icon,
                    ); ?>" aria-hidden="true"></span>
                    <span><?php echo wp_kses_post($notice["message"]); ?></span>
                </div>

                <button type="button" class="wpsc-dismiss-btn"
                    aria-label="<?php echo esc_attr__(
                        "Dismiss",
                        "wps-cache",
                    ); ?>">
                    <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                </button>
            </div>
            <?php
        }

        echo "</div>";
    }
}
