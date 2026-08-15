<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Optimization;

use WPSCache\Optimization\Html\CssDeliveryOptimizer;
use WPSCache\Tests\Framework\TestCase;

final class CssDeliveryOptimizerTest extends TestCase
{
    public function testCreatesValidAsyncAndNoscriptPairForBothQuoteStyles(): void
    {
        $processor = new CssDeliveryOptimizer(['css_async' => true]);
        $double = $processor->process('<link rel="stylesheet" href="a.css">');
        $single = $processor->process("<link rel='stylesheet' href='a.css'>");

        $this->assertContains('rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"', $double);
        $this->assertContains('rel=\'preload\' as=\'style\' onload=\'this.onload=null;this.rel="stylesheet"\'', $single);
        $this->assertContains('<noscript>', $double);
    }
}
