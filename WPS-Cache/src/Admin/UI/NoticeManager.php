<?php

declare(strict_types=1);

namespace WPSCache\Admin\UI;

/**
 * Manages Admin Notices using the PRG pattern (Post/Redirect/Get).
 * Ensures messages persist through redirects.
 */
class NoticeManager
{
    private const TRANSIENT_KEY = 'wpsc_admin_notices';

    public function __construct()
    {
        add_action('admin_notices', [$this, 'renderNotices']);
    }

    /**
     * Add a flash message to be displayed on the next screen load.
     * 
     * @param string $message The message text.
     * @param string $type    'success', 'error', 'warning', 'info'.
     */
    public function add(string $message, string $type = 'success'): void
    {
        $notices = get_transient(self::TRANSIENT_KEY) ?: [];
        $notices[] = [
            'message' => $message,
            'type'    => $type
        ];
        set_transient(self::TRANSIENT_KEY, $notices, 60);
    }

    /**
     * Display and clear messages.
     */
    public function renderNotices(): void
    {
        $notices = get_transient(self::TRANSIENT_KEY);

        if (empty($notices) || !is_array($notices)) {
            return;
        }

        // Clear immediately so they don't persist on refresh
        delete_transient(self::TRANSIENT_KEY);

        foreach ($notices as $notice) {
            $class = 'wpsc-notice ' . esc_attr($notice['type']);
            // If it's a standard WP class, map it
            if (!in_array($notice['type'], ['wpsc-notice', 'success', 'error', 'warning'])) {
                $class = 'notice notice-' . esc_attr($notice['type']);
            }

            // Render
            echo sprintf(
                '<div class="%s is-dismissible"><p>%s</p></div>',
                esc_attr($class),
                wp_kses_post($notice['message'])
            );
        }
    }
}
