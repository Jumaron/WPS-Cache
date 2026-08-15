<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Media;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;

final class GravatarCache implements Module
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'gravatars';
    }

    public function boot(): void
    {
        if ($this->settings->enabled('gravatar_local_cache')) {
            add_filter('get_avatar_url', [$this, 'localize'], 20, 3);
        }
    }

    public function localize(string $url, mixed $idOrEmail = null, array $args = []): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['secure.gravatar.com', 'www.gravatar.com', 'gravatar.com'], true)) {
            return $url;
        }
        $directory = WPSC_CACHE_DIR . 'gravatars/';
        $file = hash('sha256', $url) . '.jpg';
        $path = $directory . $file;
        if (is_file($path) && filemtime($path) > time() - 7 * DAY_IN_SECONDS) {
            return content_url('/cache/wps-cache/gravatars/' . $file);
        }
        $response = wp_safe_remote_get($url, ['timeout' => 5, 'redirection' => 2, 'limit_response_size' => 2 * 1024 * 1024]);
        $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
        $type = is_wp_error($response) ? '' : (string) wp_remote_retrieve_header($response, 'content-type');
        if (!is_string($body) || $body === '' || !str_starts_with(strtolower($type), 'image/')) {
            return $url;
        }
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            return $url;
        }
        $temporary = wp_tempnam($path);
        if (!is_string($temporary) || file_put_contents($temporary, $body, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink((string) $temporary);
            return $url;
        }
        return content_url('/cache/wps-cache/gravatars/' . $file);
    }
}
