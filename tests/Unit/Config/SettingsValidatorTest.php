<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Config;

use WPSCache\Admin\Settings\SettingsValidator;
use WPSCache\Config\Settings;
use WPSCache\Tests\Framework\TestCase;

final class SettingsValidatorTest extends TestCase
{
    public function testRejectsNonArrayPayloadWithoutThrowing(): void
    {
        $clean = (new SettingsValidator())->sanitizeSettings('invalid');

        $this->assertSame(Settings::defaults(), $clean);
    }

    public function testSanitizesRangesEnumsHostsAndSecrets(): void
    {
        \WPTestState::$options[Settings::OPTION] = array_replace(Settings::defaults(), [
            'redis_password' => 'keep-me',
            'cf_api_token' => 'keep-token',
        ]);

        $clean = (new SettingsValidator())->sanitizeSettings([
            'cache_lifetime' => 5,
            'redis_port' => 99999,
            'redis_host' => ' redis.example.test<script> ',
            'preload_interval' => 'sometimes',
            'redis_password' => '',
            'cf_api_token' => '',
            'excluded_urls' => "/cart\n/account\n",
        ]);

        $this->assertSame(60, $clean['cache_lifetime']);
        $this->assertSame(65535, $clean['redis_port']);
        $this->assertSame('redis.example.test', $clean['redis_host']);
        $this->assertSame('daily', $clean['preload_interval']);
        $this->assertSame('keep-me', $clean['redis_password']);
        $this->assertSame('keep-token', $clean['cf_api_token']);
        $this->assertSame(['/cart', '/account'], $clean['excluded_urls']);
    }
}
