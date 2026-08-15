<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use WPSCache\Contracts\HtmlProcessor;

final class ResourceHintOptimizer implements HtmlProcessor
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function process(string $html): string
    {
        $links = [];
        foreach ($this->lines('dns_prefetch_urls') as $url) {
            $links[] = '<link rel="dns-prefetch" href="' . esc_url($url) . '">';
        }
        foreach ($this->lines('preconnect_urls') as $url) {
            $links[] = '<link rel="preconnect" href="' . esc_url($url) . '" crossorigin>';
        }
        foreach (array_merge($this->lines('resource_preload_urls'), $this->lines('font_preload_urls')) as $url) {
            $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $as = in_array($extension, ['woff', 'woff2', 'ttf', 'otf'], true) ? 'font'
                : (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true) ? 'image'
                : ($extension === 'css' ? 'style' : ($extension === 'js' ? 'script' : 'fetch')));
            $crossorigin = $as === 'font' ? ' crossorigin' : '';
            $links[] = '<link rel="preload" href="' . esc_url($url) . '" as="' . $as . '"' . $crossorigin . '>';
        }
        if ($links === []) {
            return $html;
        }
        return preg_replace('/<head(\s[^>]*)?>/i', '$0' . implode('', array_unique($links)), $html, 1) ?? $html;
    }

    /** @return list<string> */
    private function lines(string $key): array
    {
        $value = $this->settings[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, static fn(mixed $item): bool => is_string($item) && filter_var($item, FILTER_VALIDATE_URL) !== false));
    }
}
