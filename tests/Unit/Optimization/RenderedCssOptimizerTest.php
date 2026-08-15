<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Optimization;

use WPSCache\Optimization\Html\RenderedCssOptimizer;
use WPSCache\Tests\Framework\TestCase;

final class RenderedCssOptimizerTest extends TestCase
{
    public function testStoresAndAppliesBrowserDerivedCriticalAndUsedCss(): void
    {
        $directory = $this->temporaryDirectory('rendered-css');
        $processor = new RenderedCssOptimizer([
            'css_rendered_profiles' => true,
            'css_critical_rendered' => true,
            'css_linked_prune' => true,
            'css_profile_retention' => 30,
        ], $directory, 'https://example.test/wp-content/cache/rendered-css');

        $this->assertTrue($processor->storeProfile([
            'url' => '/sample/',
            'device' => 'desktop',
            'critical' => '.hero{display:grid}',
            'used' => '.hero{display:grid}.footer{color:#222}',
            'sources' => ['https://example.test/wp-content/themes/site/style.css'],
        ]));

        $_SERVER['REQUEST_URI'] = '/sample/';
        $_SERVER['HTTP_USER_AGENT'] = 'Desktop Browser';
        $html = $processor->process('<html><head><link rel="stylesheet" href="https://example.test/wp-content/themes/site/style.css"></head><body></body></html>');
        $this->assertContains('id="wpsc-critical-css"', $html);
        $this->assertContains('.hero{display:grid}', $html);
        $this->assertContains('rendered-css/', $html);
        $this->assertFalse(str_contains($html, 'themes/site/style.css'));
        $this->removeDirectory($directory);
    }

    public function testCaptureScriptIsRestrictedToAdministrators(): void
    {
        $directory = $this->temporaryDirectory('rendered-css-auth');
        $processor = new RenderedCssOptimizer([
            'css_rendered_profiles' => true,
            'css_critical_rendered' => true,
            'css_linked_prune' => true,
            'css_profile_retention' => 30,
        ], $directory, 'https://example.test/wp-content/cache/rendered-css');
        $html = '<html><head></head><body></body></html>';

        $this->assertFalse(str_contains($processor->process($html), 'wpsc-rendered-css-audit'));
        \WPTestState::$admin = true;
        $result = $processor->process($html);
        $this->assertContains('wpsc-rendered-css-audit', $result);
        $this->assertContains('X-WP-Nonce', $result);
        $this->removeDirectory($directory);
    }
}
