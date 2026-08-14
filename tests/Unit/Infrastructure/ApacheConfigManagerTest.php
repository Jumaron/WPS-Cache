<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Config\Settings;
use WPSCache\Infrastructure\Server\ApacheConfigManager;
use WPSCache\Tests\Framework\TestCase;

final class ApacheConfigManagerTest extends TestCase
{
    public function testRulesUseConfiguredTtlAndStateBypasses(): void
    {
        $manager = new ApacheConfigManager(new Settings([
            'cache_lifetime' => 86400,
            'woo_support' => true,
            'excluded_urls' => ['/private-area'],
        ]));

        $rules = $manager->renderRules();
        $this->assertContains('max-age=86400', $rules);
        $this->assertContains('wp_woocommerce_session_', $rules);
        $this->assertContains('woocommerce_items_in_cart', $rules);
        $this->assertContains('/private\-area', $rules);
        $this->assertContains('WPSC_BYPASS', $rules);
        $this->assertFalse(str_contains($rules, '[S='), 'Skip counts are brittle and must not be used.');
    }
}
