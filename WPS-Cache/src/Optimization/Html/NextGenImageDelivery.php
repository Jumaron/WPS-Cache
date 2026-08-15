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
        $html = preg_replace_callback('~(?<!<picture>)<img\b([^>]*\bsrc=["\']([^"\']+\.(?:jpe?g|png))[^"\']*["\'][^>]*)>~i', function (array $match): string {
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
                    $sources[] = '<source type="image/' . $format . '" srcset="' . esc_url($this->mediaUrl($variantUrl)) . '">';
                }
            }
            return $sources === [] ? $match[0] : '<picture>' . implode('', $sources) . $match[0] . '</picture>';
        }, $html) ?? $html;

        return preg_replace_callback('~(background(?:-image)?)\s*:\s*url\(\s*(["\']?)([^)"\']+\.(?:jpe?g|png)(?:\?[^)"\']*)?)\2\s*\)~i', function (array $match): string {
            $url = html_entity_decode(trim($match[3]), ENT_QUOTES | ENT_HTML5);
            $path = $this->urlToPath($url);
            if ($path === null) {
                return $match[0];
            }
            $candidates = [];
            foreach (['avif' => 'image_generate_avif', 'webp' => 'image_generate_webp'] as $format => $setting) {
                $variantPath = preg_replace('/\.[^.]+$/', '.' . $format, strtok($path, '?') ?: $path);
                $variantUrl = preg_replace('/\.[^.]+(?=\?.*|$)/', '.' . $format, $url);
                if (!empty($this->settings[$setting]) && is_string($variantPath) && is_file($variantPath) && is_string($variantUrl)) {
                    $candidates[] = 'url(&quot;' . esc_url($this->mediaUrl($variantUrl)) . '&quot;) type(&quot;image/' . $format . '&quot;)';
                }
            }
            if ($candidates === []) {
                return $match[0];
            }
            $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';
            $candidates[] = 'url(&quot;' . esc_url($this->mediaUrl($url)) . '&quot;) type(&quot;' . $mime . '&quot;)';
            return strtolower($match[1]) . ':image-set(' . implode(',', $candidates) . ')';
        }, $html) ?? $html;
    }

    private function urlToPath(string $url): ?string
    {
        $uploads = wp_get_upload_dir();
        $baseUrl = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
        $baseDir = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        $cleanUrl = strtok($url, '?') ?: $url;
        if ($baseUrl === '' || $baseDir === '' || !str_starts_with($cleanUrl, $baseUrl . '/')) {
            return null;
        }
        $relative = rawurldecode(substr($cleanUrl, strlen($baseUrl) + 1));
        if (str_contains($relative, '..')) {
            return null;
        }
        return $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function mediaUrl(string $url): string
    {
        if (empty($this->settings['cdn_enable'])) {
            return $url;
        }
        $origin = rtrim((string) (($this->settings['cdn_media_url'] ?? '') ?: ($this->settings['cdn_url'] ?? '')), '/');
        $uploads = wp_get_upload_dir();
        $baseUrl = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
        return $origin !== '' && $baseUrl !== '' && str_starts_with($url, $baseUrl . '/')
            ? $origin . substr($url, strlen($baseUrl))
            : $url;
    }
}
