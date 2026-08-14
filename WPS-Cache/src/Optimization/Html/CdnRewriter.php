<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use WPSCache\Config\Settings;
use WPSCache\Contracts\HtmlProcessor;

/** Rewrites local static-asset URLs to a configured CDN origin. */
final class CdnRewriter implements HtmlProcessor
{
    /** @var list<string> */
    private const EXTENSIONS = [
        'webp',
        'avif',
        'png',
        'jpg',
        'jpeg',
        'gif',
        'svg',
        'css',
        'js',
        'mp4',
        'webm',
        'woff',
        'woff2',
        'ttf',
    ];

    private readonly string $siteUrl;
    private readonly string $cdnUrl;

    public function __construct(private readonly Settings $settings, ?string $siteUrl = null)
    {
        $this->siteUrl = $siteUrl ?? site_url();
        $this->cdnUrl = rtrim($settings->string('cdn_url'), '/');
    }

    public function process(string $html): string
    {
        if (!$this->settings->enabled('cdn_enable') || $this->cdnUrl === '') {
            return $html;
        }

        $siteHost = parse_url($this->siteUrl, PHP_URL_HOST);
        $cdnHost = parse_url($this->cdnUrl, PHP_URL_HOST);
        if (!is_string($siteHost) || !is_string($cdnHost) || strcasecmp($siteHost, $cdnHost) === 0) {
            return $html;
        }

        $extensions = implode('|', self::EXTENSIONS);
        $pattern = '~\b(src|href|srcset|data-src|data-srcset)=([\'"])'
            . '(?:https?:\/\/' . preg_quote($siteHost, '~') . ')?\/'
            . '([^"\']+\.(' . $extensions . '))([?#][^"\']*)?\2~i';

        $rewritten = preg_replace_callback(
            $pattern,
            function (array $matches): string {
                $path = $matches[3];
                if (str_contains($path, 'wp-admin') || str_contains($path, 'preview=true')) {
                    return $matches[0];
                }

                return sprintf(
                    '%s=%s%s/%s%s%s',
                    $matches[1],
                    $matches[2],
                    $this->cdnUrl,
                    $path,
                    $matches[5] ?? '',
                    $matches[2],
                );
            },
            $html,
        );

        return is_string($rewritten) ? $rewritten : $html;
    }
}
