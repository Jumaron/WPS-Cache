<?php

declare(strict_types=1);

namespace WPSCache\Admin\UI;

class NoticeManager
{
    private const TRANSIENT_KEY = 'wpsc_admin_notices';

    public function __construct()
    {
        // We do NOT add_action('admin_notices') here anymore.
        // The AdminPanelManager manually calls renderNotices() to control placement.
    }

    public function add(string $message, string $type = 'success'): void
    {
        $notices = get_transient(self::TRANSIENT_KEY) ?: [];
        $notices[] = [
            'message' => $message,
            'type'    => $type
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
            // Map types to our CSS classes
            $typeClass = match ($notice['type']) {
                'error' => 'error',
                'warning' => 'warning',
                default => 'success'
            };

            $icon = match ($notice['type']) {
                'error' => 'dashicons-warning',
                'warning' => 'dashicons-flag',
                default => 'dashicons-yes-alt'
            };

?>
            <div class="wpsc-notice <?php echo esc_attr($typeClass); ?>">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                    <span><?php echo wp_kses_post($notice['message']); ?></span>
                </div>
                <button type="button" class="notice-dismiss" onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; color:var(--wpsc-text-muted);">
                    <span class="dashicons dashicons-dismiss"></span>
                </button>
            </div>
<?php
        }

        echo '</div>';
    }
}
