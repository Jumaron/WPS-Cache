<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Config;

use WPSCache\Config\Settings;
use WPSCache\Tests\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaultsAndOverridesAreNormalized(): void
    {
        $settings = new Settings([
            'html_cache' => false,
            'cache_lifetime' => 7200,
            'excluded_urls' => ['/cart', 42, '/account'],
            'unknown' => 'retained-for-forward-compatibility',
        ]);

        $this->assertFalse($settings->enabled('html_cache'));
        $this->assertSame(7200, $settings->integer('cache_lifetime'));
        $this->assertSame(['/cart', '/account'], $settings->strings('excluded_urls'));
        $this->assertTrue($settings->enabled('media_lazy_load'));
        $this->assertSame('retained-for-forward-compatibility', $settings->get('unknown'));
    }

    public function testDefaultsReturnAnIndependentArray(): void
    {
        $first = Settings::defaults();
        $first['cache_lifetime'] = 99;

        $this->assertSame(3600, Settings::defaults()['cache_lifetime']);
    }
}
