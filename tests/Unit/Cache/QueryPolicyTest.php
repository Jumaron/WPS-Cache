<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Cache;

use WPSCache\Cache\Page\QueryPolicy;
use WPSCache\Tests\Framework\TestCase;

final class QueryPolicyTest extends TestCase
{
    public function testCanonicalizesVariantsAndRemovesTrackingParameters(): void
    {
        $policy = new QueryPolicy([
            'cache_query_mode' => 'variants',
            'cache_query_denylist' => ['preview', 'nonce_*'],
            'cache_ignored_query_params' => ['utm_*', 'fbclid'],
        ]);

        $this->assertFalse($policy->shouldBypass(['page' => '2', 'utm_source' => 'mail']));
        $this->assertSame('a=1&page=2', $policy->canonical(['page' => '2', 'utm_source' => 'mail', 'a' => '1']));
        $this->assertTrue($policy->shouldBypass(['nonce_checkout' => 'secret']));
    }

    public function testAllowlistRejectsUnknownParameters(): void
    {
        $policy = new QueryPolicy([
            'cache_query_mode' => 'allowlist',
            'cache_query_allowlist' => ['page', 'filter_*'],
            'cache_query_denylist' => [],
            'cache_ignored_query_params' => ['utm_*'],
        ]);

        $this->assertFalse($policy->shouldBypass(['filter_color' => 'blue', 'utm_medium' => 'email']));
        $this->assertTrue($policy->shouldBypass(['private' => '1']));
    }
}
