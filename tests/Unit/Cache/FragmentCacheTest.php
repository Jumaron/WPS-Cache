<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Cache;

use WPSCache\Cache\Fragment\FragmentCache;
use WPSCache\Tests\Framework\TestCase;

final class FragmentCacheTest extends TestCase
{
    public function testBuildsSignedHolePunchPlaceholder(): void
    {
        $first = FragmentCache::placeholder('cart_count', '0');
        $second = FragmentCache::placeholder('cart_count', '0');

        $this->assertSame($first, $second);
        $this->assertContains('data-wpsc-fragment=', $first);
        $this->assertContains('/wp-json/wps-cache/v1/fragment/cart_count', $first);
        $this->assertContains('token=', $first);
    }
}
