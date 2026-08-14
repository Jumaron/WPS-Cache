<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Integration;

use WPSCache\Config\Settings;
use WPSCache\Integration\WooCommerce\CacheBypass;
use WPSCache\Tests\Framework\TestCase;

final class WooCommerceCacheBypassTest extends TestCase
{
    public function testBypassesSensitivePagesAndSessionCookies(): void
    {
        $bypass = new CacheBypass(new Settings(['woo_support' => true]));
        \WPTestState::$cart = true;
        $this->assertTrue($bypass->shouldBypass());

        \WPTestState::$cart = false;
        $_COOKIE['wp_woocommerce_session_abc'] = 'session';
        $this->assertTrue($bypass->shouldBypass());
    }

    public function testCanBeExplicitlyDisabled(): void
    {
        \WPTestState::$checkout = true;
        $bypass = new CacheBypass(new Settings(['woo_support' => false]));
        $this->assertFalse($bypass->shouldBypass());
    }
}
