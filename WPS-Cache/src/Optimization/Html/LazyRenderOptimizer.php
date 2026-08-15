<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use WPSCache\Contracts\HtmlProcessor;

/** Applies content-visibility to administrator-selected below-fold selectors. */
final class LazyRenderOptimizer implements HtmlProcessor
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function process(string $html): string
    {
        $selectors = $this->settings['lazy_render_selectors'] ?? [];
        if (!is_array($selectors) || $selectors === []) {
            return $html;
        }
        $safe = array_values(array_filter($selectors, static fn(mixed $selector): bool => is_string($selector) && preg_match('/^[.#]?[a-zA-Z][a-zA-Z0-9_-]*$/', $selector) === 1));
        if ($safe === []) {
            return $html;
        }
        $style = '<style id="wpsc-lazy-render">' . implode(',', $safe) . '{content-visibility:auto;contain-intrinsic-size:auto 800px}</style>';
        return str_replace('</head>', $style . '</head>', $html);
    }
}
