<?php

declare(strict_types=1);

namespace WPSCache\Admin;

use Throwable;
use WPSCache\Optimization\Media\ImageOptimizer;

final class ImageActionController
{
    public function __construct(private readonly ImageOptimizer $optimizer)
    {
    }

    public function boot(): void
    {
        add_action('wp_ajax_wpsc_image_batch', [$this, 'batch']);
        add_action('wp_ajax_wpsc_image_optimize', [$this, 'optimize']);
        add_action('wp_ajax_wpsc_image_restore', [$this, 'restore']);
    }

    public function batch(): void
    {
        $this->authorize();
        $page = max(1, absint($_POST['page'] ?? 1));
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/pdf'],
            'posts_per_page' => 100,
            'paged' => $page,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        wp_send_json_success([
            'ids' => array_map('intval', $query->posts),
            'page' => $page,
            'pages' => max(1, (int) $query->max_num_pages),
            'total' => (int) $query->found_posts,
        ]);
    }

    public function optimize(): void
    {
        $this->authorize();
        $id = absint($_POST['id'] ?? 0);
        $file = $id > 0 ? get_attached_file($id) : false;
        if (!is_string($file)) {
            wp_send_json_error('Attachment file not found.', 404);
        }
        try {
            $result = $this->optimizer->optimizeFile($file, $id);
        } catch (Throwable $exception) {
            error_log('[WPS-Cache] Image optimization action failed: ' . $exception->getMessage());
            wp_send_json_error('Image optimization failed safely; the request did not alter plugin configuration.', 500);
        }
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result, 422);
    }

    public function restore(): void
    {
        $this->authorize();
        $id = absint($_POST['id'] ?? 0);
        $file = $id > 0 ? get_attached_file($id) : false;
        try {
            if (!is_string($file) || !$this->optimizer->restore($file)) {
                wp_send_json_error('No WPS Cache backup is available.', 404);
            }
        } catch (Throwable $exception) {
            error_log('[WPS-Cache] Image restore action failed: ' . $exception->getMessage());
            wp_send_json_error('Image restore failed safely.', 500);
        }
        $metadata = wp_get_attachment_metadata($id);
        $dimensions = @getimagesize($file);
        if (is_array($metadata) && is_array($dimensions)) {
            $metadata['width'] = (int) ($dimensions[0] ?? 0);
            $metadata['height'] = (int) ($dimensions[1] ?? 0);
            wp_update_attachment_metadata($id, $metadata);
        }
        wp_send_json_success('Original restored.');
    }

    private function authorize(): void
    {
        check_ajax_referer('wpsc_ajax_nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Unauthorized', 403);
        }
    }
}
