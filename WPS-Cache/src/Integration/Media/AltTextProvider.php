<?php

declare(strict_types=1);

namespace WPSCache\Integration\Media;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;

/** Missing-alt-text provider boundary with an explicit opt-in OpenAI adapter. */
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
        if ((!is_string($description) || trim($description) === '') && $this->settings->enabled('image_ai_alt_openai')) {
            $description = $this->generateWithOpenAI($file, $attachmentId);
        }
        if (is_string($description) && trim($description) !== '') {
            $description = $this->normalize($description);
            if ($description !== '') {
                update_post_meta($attachmentId, '_wp_attachment_image_alt', $description);
            }
        }
    }

    private function generateWithOpenAI(string $file, int $attachmentId): string
    {
        $apiKey = defined('WPSC_OPENAI_API_KEY') ? (string) WPSC_OPENAI_API_KEY : $this->settings->string('openai_api_key');
        if ($apiKey === '' || !is_file($file)) {
            return '';
        }
        $size = (int) filesize($file);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => '',
        };
        if ($mime === '' || $size <= 0 || $size > 10 * 1024 * 1024 || ($extension === 'gif' && $this->animatedGif($file))) {
            return '';
        }
        $bytes = file_get_contents($file);
        if (!is_string($bytes)) {
            return '';
        }
        $language = function_exists('get_bloginfo') ? sanitize_text_field((string) get_bloginfo('language')) : '';
        $prompt = 'Write objective, useful alt text for this image in 125 characters or fewer. Return only the alt text, without quotes. If the image is purely decorative, return an empty string.';
        if ($language !== '') {
            $prompt .= ' Use the website language ' . $language . '.';
        }
        $body = wp_json_encode([
            'model' => $this->settings->string('openai_vision_model') ?: 'gpt-5.6',
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                    ['type' => 'input_image', 'image_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'detail' => 'low'],
                ],
            ]],
            'max_output_tokens' => 80,
        ]);
        if (!is_string($body)) {
            return '';
        }
        $response = wp_safe_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 60,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ]);
        if (is_wp_error($response)) {
            do_action('wpsc_alt_text_error', $attachmentId, $response->get_error_message());
            return '';
        }
        $status = wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($payload)) {
            do_action('wpsc_alt_text_error', $attachmentId, 'OpenAI returned HTTP ' . $status . '.');
            return '';
        }
        return self::extractResponseText($payload);
    }

    /** @param array<string, mixed> $payload */
    public static function extractResponseText(array $payload): string
    {
        if (is_string($payload['output_text'] ?? null)) {
            return trim($payload['output_text']);
        }
        foreach ((array) ($payload['output'] ?? []) as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (is_array($content) && ($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                    return trim($content['text']);
                }
            }
        }
        return '';
    }

    private function normalize(string $description): string
    {
        $description = trim(sanitize_text_field(preg_replace('/\s+/u', ' ', $description) ?? $description), " \t\n\r\0\x0B\"'");
        if (function_exists('mb_substr')) {
            return mb_substr($description, 0, 125);
        }
        if (function_exists('iconv_substr')) {
            $short = iconv_substr($description, 0, 125, 'UTF-8');
            return is_string($short) ? $short : '';
        }
        return substr($description, 0, 125);
    }

    private function animatedGif(string $file): bool
    {
        $content = file_get_contents($file, false, null, 0, 2 * 1024 * 1024);
        return is_string($content) && preg_match_all('/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $content) > 1;
    }
}
