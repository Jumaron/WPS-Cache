<?php

declare(strict_types=1);

namespace WPSCache\Admin;

use Throwable;
use WPSCache\Maintenance\DatabaseOptimizer;

final class DatabaseCleanupController
{
    public function __construct(private readonly DatabaseOptimizer $optimizer)
    {
    }

    public function boot(): void
    {
        add_action('wp_ajax_wpsc_manual_db_cleanup', [$this, 'handle']);
    }

    public function handle(): void
    {
        try {
            check_ajax_referer('wpsc_ajax_nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized', 403);
            }

            $items = $_POST['items'] ?? [];
            if (!is_array($items)) {
                wp_send_json_error('Invalid input', 400);
            }

            $items = array_values(array_map('sanitize_key', array_filter($items, 'is_string')));
            if ($items === []) {
                wp_send_json_error('No items selected', 400);
            }

            $count = $this->optimizer->processCleanup($items);
            wp_send_json_success(sprintf('Cleaned %d categories of items.', $count));
        } catch (Throwable $exception) {
            error_log('[WPS-Cache] Database cleanup failed: ' . $exception->getMessage());
            wp_send_json_error('Cleanup failed.', 500);
        }
    }
}
