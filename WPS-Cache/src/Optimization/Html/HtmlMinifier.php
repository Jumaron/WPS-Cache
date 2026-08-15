<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use WPSCache\Contracts\HtmlProcessor;

/** Conservative HTML minification that preserves raw-text and preformatted nodes. */
final class HtmlMinifier implements HtmlProcessor
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function process(string $html): string
    {
        if (empty($this->settings['html_minify'])) {
            return $html;
        }
        $preserved = [];
        $html = preg_replace_callback(
            '~<(pre|textarea|script|style|template)\b[^>]*>.*?</\1>~is',
            static function (array $match) use (&$preserved): string {
                $key = '___WPSC_PRESERVE_' . count($preserved) . '___';
                $preserved[$key] = $match[0];
                return $key;
            },
            $html,
        ) ?? $html;
        $html = preg_replace('/<!--(?!\[if|\s*WPS Cache:).*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;
        $html = preg_replace('/[ \t]{2,}/', ' ', $html) ?? $html;
        return strtr(trim($html), $preserved);
    }
}
