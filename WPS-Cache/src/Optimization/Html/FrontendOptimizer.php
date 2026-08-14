<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use Throwable;
use WPSCache\Contracts\HtmlProcessor;
use WPSCache\Contracts\Module;

/**
 * Runs frontend HTML transformations independently from page caching.
 *
 * The buffer starts during template_redirect, inside the page-cache buffer. This
 * means transformed HTML is cached when page caching is enabled and is still
 * delivered when page caching is disabled.
 */
final class FrontendOptimizer implements Module
{
    /** @param list<HtmlProcessor> $processors */
    public function __construct(private readonly array $processors)
    {
    }

    public function id(): string
    {
        return 'frontend-html';
    }

    public function boot(): void
    {
        add_action('template_redirect', [$this, 'startBuffer'], PHP_INT_MIN);
    }

    public function startBuffer(): void
    {
        if (
            is_admin() ||
            is_user_logged_in() ||
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        ) {
            return;
        }

        ob_start([$this, 'process']);
    }

    public function process(string $html): string
    {
        if ($html === '' || stripos($html, '</html>') === false) {
            return $html;
        }

        foreach ($this->processors as $processor) {
            try {
                $html = $processor->process($html);
            } catch (Throwable $exception) {
                error_log(sprintf(
                    '[WPS-Cache] HTML processor %s failed: %s',
                    $processor::class,
                    $exception->getMessage(),
                ));
            }
        }

        return $html;
    }
}
