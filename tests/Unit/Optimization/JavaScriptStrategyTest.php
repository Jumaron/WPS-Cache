<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Optimization;

use WPSCache\Optimization\Html\JavaScriptOptimizer;
use WPSCache\Tests\Framework\TestCase;

final class JavaScriptStrategyTest extends TestCase
{
    public function testConsentStrategyWaitsForNamedEventAndHasNoTimeoutWhenDisabled(): void
    {
        $optimizer = new JavaScriptOptimizer([
            'js_delay' => true,
            'js_delay_strategy' => 'consent',
            'js_delay_timeout' => 0,
            'excluded_js_execution' => [],
        ]);
        $html = '<html><body><script src="https://cdn.example.test/app.js"></script></body></html>';
        $result = $optimizer->process($html);

        $this->assertContains('data-wpsc-src="https://cdn.example.test/app.js"', $result);
        $this->assertContains("var strategy = 'consent';", $result);
        $this->assertContains("window.addEventListener('wpsc:consent'", $result);
        $this->assertFalse(str_contains($result, 'setTimeout(boot, 8000)'));
    }
}
