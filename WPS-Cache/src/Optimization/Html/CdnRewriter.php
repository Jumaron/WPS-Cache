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
    /** @var array<string, string> */
    private array $origins;

    public function __construct(private readonly Settings $settings, ?string $siteUrl = null)
    {
        $this->siteUrl = $siteUrl ?? site_url();
        $this->cdnUrl = rtrim($settings->string('cdn_url'), '/');
        $this->origins = [
            'css' => rtrim($settings->string('cdn_css_url') ?: $this->cdnUrl, '/'),
            'js' => rtrim($settings->string('cdn_js_url') ?: $this->cdnUrl, '/'),
            'media' => rtrim($settings->string('cdn_media_url') ?: $this->cdnUrl, '/'),
        ];
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
        $pattern = '~\b(src|href|data-src)=([\'"])'
            . '(?:(?:https?:)?\/\/' . preg_quote($siteHost, '~') . ')?\/'
            . '([^"\']+\.(' . $extensions . '))([?#][^"\']*)?\2~i';

        $rewritten = preg_replace_callback(
            $pattern,
            function (array $matches): string {
                $path = $matches[3];
                if (str_contains($path, 'wp-admin') || str_contains($path, 'preview=true')) {
                    return $matches[0];
                }

                $extension = strtolower((string) ($matches[4] ?? ''));
                $type = $extension === 'css' ? 'css' : ($extension === 'js' ? 'js' : 'media');
                $origin = $this->origins[$type] ?: $this->cdnUrl;
                return sprintf(
                    '%s=%s%s/%s%s%s',
                    $matches[1],
                    $matches[2],
                    $origin,
                    $path,
                    $matches[5] ?? '',
                    $matches[2],
                );
            },
            $html,
        );
        if (!is_string($rewritten)) {
            return $html;
        }

        $srcsetPattern = '~\b(srcset|data-srcset)=([\'"])(.*?)\2~is';
        $assetPattern = '~(?:(?:https?:)?\/\/' . preg_quote($siteHost, '~') . ')?\/([^,\s"\']+\.(' . $extensions . '))([?#][^,\s"\']*)?~i';
        return preg_replace_callback($srcsetPattern, function (array $attribute) use ($assetPattern): string {
            $value = preg_replace_callback($assetPattern, function (array $asset): string {
                $path = $asset[1];
                if (str_contains($path, 'wp-admin') || str_contains($path, 'preview=true')) {
                    return $asset[0];
                }
                $extension = strtolower((string) ($asset[2] ?? ''));
                $type = $extension === 'css' ? 'css' : ($extension === 'js' ? 'js' : 'media');
                $origin = $this->origins[$type] ?: $this->cdnUrl;
                return $origin . '/' . $path . ($asset[3] ?? '');
            }, $attribute[3]);
            return $attribute[1] . '=' . $attribute[2] . (is_string($value) ? $value : $attribute[3]) . $attribute[2];
        }, $rewritten) ?? $rewritten;
    }
}
