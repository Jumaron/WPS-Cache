<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Optimization;

use WPSCache\Config\Settings;
use WPSCache\Optimization\Html\CdnRewriter;
use WPSCache\Tests\Framework\TestCase;

final class CdnRewriterTest extends TestCase
{
    public function testRewritesOnlyMatchingStaticAssets(): void
    {
        $rewriter = new CdnRewriter(new Settings([
            'cdn_enable' => true,
            'cdn_url' => 'https://cdn.example.test/',
        ]), 'https://www.example.test');

        $html = '<link href="https://www.example.test/wp-content/app.css?ver=1">'
            . '<img src="/wp-content/image.webp">'
            . '<a href="https://www.example.test/page/">Page</a>';

        $this->assertSame(
            '<link href="https://cdn.example.test/wp-content/app.css?ver=1">'
                . '<img src="https://cdn.example.test/wp-content/image.webp">'
                . '<a href="https://www.example.test/page/">Page</a>',
            $rewriter->process($html),
        );
    }

    public function testLeavesMarkupAloneWhenDisabled(): void
    {
        $html = '<script src="https://www.example.test/wp-includes/app.js"></script>';
        $rewriter = new CdnRewriter(new Settings(), 'https://www.example.test');

        $this->assertSame($html, $rewriter->process($html));
    }
}
