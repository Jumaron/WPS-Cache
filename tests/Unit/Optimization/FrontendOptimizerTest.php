<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Optimization;

use WPSCache\Contracts\HtmlProcessor;
use WPSCache\Optimization\Html\FrontendOptimizer;
use WPSCache\Tests\Framework\TestCase;

final class FrontendOptimizerTest extends TestCase
{
    public function testRunsProcessorsInRegistrationOrder(): void
    {
        $first = new class implements HtmlProcessor {
            public function process(string $html): string { return str_replace('</body>', '<p>first</p></body>', $html); }
        };
        $second = new class implements HtmlProcessor {
            public function process(string $html): string { return str_replace('first', 'first-second', $html); }
        };

        $optimizer = new FrontendOptimizer([$first, $second]);
        $result = $optimizer->process('<html><body></body></html>');

        $this->assertContains('<p>first-second</p>', $result);
    }

    public function testLeavesNonDocumentResponsesUntouched(): void
    {
        $processor = new class implements HtmlProcessor {
            public function process(string $html): string { return 'changed'; }
        };

        $this->assertSame('{"ok":true}', (new FrontendOptimizer([$processor]))->process('{"ok":true}'));
    }
}
