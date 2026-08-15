<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Optimization;

use ReflectionMethod;
use WPSCache\Config\Settings;
use WPSCache\Optimization\Assets\AssetCombiner;
use WPSCache\Tests\Framework\TestCase;

final class AssetCombinerTest extends TestCase
{
    public function testCombineExclusionsMatchHandlesFilenamesAndUrlFragments(): void
    {
        $combiner = new AssetCombiner(new Settings());
        $method = new ReflectionMethod($combiner, 'isExcluded');

        $this->assertTrue($method->invoke($combiner, 'checkout-style', '/assets/app.css', ['checkout-style']));
        $this->assertTrue($method->invoke($combiner, 'theme', '/assets/legacy.css?ver=2', ['legacy.css']));
        $this->assertTrue($method->invoke($combiner, 'theme', '/plugins/forms/assets/form.css', ['plugins/forms']));
        $this->assertFalse($method->invoke($combiner, 'theme', '/assets/app.css', ['checkout-style', 'legacy.css']));
    }
}
