<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use WPSCache\Contracts\HtmlProcessor;

/** Wraps locally generated WebP/AVIF variants in browser-native picture fallback. */
final class NextGenImageDelivery implements HtmlProcessor
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function process(string $html): string
    {
        if (empty($this->settings['image_generate_webp']) && empty($this->settings['image_generate_avif'])) {
            return $html;
        }
        return preg_replace_callback('~(?<!<picture>)<img\b([^>]*\bsrc=["\']([^"\']+\.(?:jpe?g|png))[^"\']*["\'][^>]*)>~i', function (array $match): string {
            $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
            $path = $this->urlToPath($url);
            if ($path === null) {
                return $match[0];
            }
            $sources = [];
            foreach (['avif' => 'image_generate_avif', 'webp' => 'image_generate_webp'] as $format => $setting) {
                $variantPath = preg_replace('/\.[^.]+$/', '.' . $format, $path);
                $variantUrl = preg_replace('/\.[^.]+(?=\?.*|$)/', '.' . $format, $url);
                if (!empty($this->settings[$setting]) && is_string($variantPath) && is_file($variantPath) && is_string($variantUrl)) {
                    $sources[] = '<source type="image/' . $format . '" srcset="' . esc_url($variantUrl) . '">';
                }
            }
            return $sources === [] ? $match[0] : '<picture>' . implode('', $sources) . $match[0] . '</picture>';
        }, $html) ?? $html;
    }

    private function urlToPath(string $url): ?string
    {
        $uploads = wp_get_upload_dir();
        $baseUrl = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
        $baseDir = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        if ($baseUrl === '' || $baseDir === '' || !str_starts_with($url, $baseUrl . '/')) {
            return null;
        }
        $relative = rawurldecode(substr($url, strlen($baseUrl) + 1));
        if (str_contains($relative, '..')) {
            return null;
        }
        return $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
