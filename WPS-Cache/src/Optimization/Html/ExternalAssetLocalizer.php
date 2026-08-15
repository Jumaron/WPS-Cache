<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use WPSCache\Contracts\HtmlProcessor;

/** Self-hosts administrator-approved external CSS/JS URLs in the plugin cache. */
final class ExternalAssetLocalizer implements HtmlProcessor
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function process(string $html): string
    {
        $urls = $this->settings['self_host_asset_urls'] ?? [];
        if (!is_array($urls)) {
            return $html;
        }
        foreach ($urls as $url) {
            if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)), ['css', 'js'], true)) {
                continue;
            }
            $local = $this->localize($url);
            if ($local !== null) {
                $html = str_replace([$url, esc_url($url)], $local, $html);
            }
        }
        return $html;
    }

    private function localize(string $url): ?string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $directory = WPSC_CACHE_DIR . 'external-assets/';
        $name = hash('sha256', $url) . '.' . $extension;
        $file = $directory . $name;
        if (is_file($file) && filemtime($file) > time() - 7 * DAY_IN_SECONDS) {
            return content_url('/cache/wps-cache/external-assets/' . $name);
        }
        $response = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 2, 'limit_response_size' => 2 * 1024 * 1024]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            return null;
        }
        if ($extension === 'css') {
            $body = $this->absolutizeCss($body, $url);
        }
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            return null;
        }
        $temporary = wp_tempnam($file);
        if (!is_string($temporary) || file_put_contents($temporary, $body, LOCK_EX) === false || !@rename($temporary, $file)) {
            @unlink((string) $temporary);
            return null;
        }
        return content_url('/cache/wps-cache/external-assets/' . $name);
    }

    private function absolutizeCss(string $css, string $stylesheet): string
    {
        $origin = (string) parse_url($stylesheet, PHP_URL_SCHEME) . '://' . (string) parse_url($stylesheet, PHP_URL_HOST);
        $directory = rtrim(str_replace('\\', '/', dirname((string) parse_url($stylesheet, PHP_URL_PATH))), '/');
        return preg_replace_callback('~url\((["\']?)(?!data:|https?:|//|/)([^)"\']+)\1\)~i', static fn(array $match): string => 'url(' . $match[1] . $origin . $directory . '/' . ltrim($match[2], '/') . $match[1] . ')', $css) ?? $css;
    }
}
