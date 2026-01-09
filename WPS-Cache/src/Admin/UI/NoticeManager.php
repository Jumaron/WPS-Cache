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

        echo '<div class="wpsc-notices-container" style="margin-bottom: 20px;">';

        foreach ($notices as $notice) {

            $typeClass = match ($notice["type"]) {
                "error" => "error",
                "warning" => "warning",
                default => "success",
            };

            // Palette UX: Ensure screen readers announce notices immediately (Alert for errors, Status for success/warning)
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
            <div class="wpsc-notice <?php echo esc_attr($typeClass); ?>" role="<?php echo esc_attr(
    $role,
); ?>">
                <div class="wpsc-notice-content">

                    <span class="dashicons <?php echo esc_attr(
                        $icon,
                    ); ?>" aria-hidden="true"></span>
                    <span><?php echo wp_kses_post($notice["message"]); ?></span>
                </div>
                <!-- Custom Dismiss Button (No WP Core classes) -->
                <button type="button" class="wpsc-dismiss-btn"
                    aria-label="<?php echo esc_attr__(
                        "Dismiss this notice",
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
?>
