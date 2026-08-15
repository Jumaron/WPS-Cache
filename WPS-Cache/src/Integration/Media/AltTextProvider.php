<?php

declare(strict_types=1);

namespace WPSCache\Integration\Media;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;

/** Provider boundary for optional AI/local vision caption integrations. */
final class AltTextProvider implements Module
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'alt-text-provider';
    }

    public function boot(): void
    {
        if ($this->settings->enabled('image_ai_alt_provider')) {
            add_action('wpsc_image_optimized', [$this, 'generate'], 20, 4);
        }
    }

    /** @param list<string> $variants */
    public function generate(string $file, int $saved, array $variants, int $attachmentId): void
    {
        if ($attachmentId <= 0 || get_post_meta($attachmentId, '_wp_attachment_image_alt', true) !== '') {
            return;
        }
        $description = apply_filters('wpsc_generate_image_alt_text', '', $file, $attachmentId);
        if (is_string($description) && trim($description) !== '') {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', sanitize_text_field($description));
        }
    }
}
