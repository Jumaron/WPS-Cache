<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use WPSCache\Contracts\HtmlProcessor;

/** Opt-in non-blocking delivery for non-critical stylesheets, with noscript fallback. */
final class CssDeliveryOptimizer implements HtmlProcessor
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function process(string $html): string
    {
        if (empty($this->settings['css_async']) || !empty($this->settings['optimization_safe_mode'])) {
            return $html;
        }
        return preg_replace_callback(
            '~<link\b([^>]*\brel=["\']stylesheet["\'][^>]*)>~i',
            static function (array $match): string {
                if (stripos($match[1], 'data-wpsc-critical') !== false || stripos($match[1], 'media=') !== false) {
                    return $match[0];
                }
                $preload = preg_replace_callback('/\brel=(["\'])stylesheet\1/i', static function (array $relation): string {
                    $quote = $relation[1];
                    $scriptQuote = $quote === '"' ? "'" : '"';
                    return 'rel=' . $quote . 'preload' . $quote . ' as=' . $quote . 'style' . $quote
                        . ' onload=' . $quote . 'this.onload=null;this.rel=' . $scriptQuote . 'stylesheet' . $scriptQuote . $quote;
                }, $match[0], 1) ?? $match[0];
                return $preload . '<noscript>' . $match[0] . '</noscript>';
            },
            $html,
        ) ?? $html;
    }
}
