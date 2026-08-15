<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Config\Settings;
use WPSCache\Infrastructure\WordPress\EarlyCacheConfig;
use WPSCache\Tests\Framework\TestCase;

final class EarlyCacheConfigTest extends TestCase
{
    public function testWritesValidatedEarlyServingConfiguration(): void
    {
        $directory = $this->temporaryDirectory('early-config');
        $file = $directory . '/runtime.php';
        $writer = new EarlyCacheConfig($file);
        $settings = new Settings([
            'cache_lifetime' => 7200,
            'woo_support' => true,
            'excluded_urls' => ['/checkout', '/private'],
            'cache_bypass_cookies' => ['member_session'],
            'cache_device_mode' => 'tablet',
            'cache_search' => true,
        ]);

        $this->assertTrue($writer->write($settings));
        $configuration = require $file;
        $this->assertSame(7200, $configuration['ttl']);
        $this->assertSame(['/checkout', '/private'], $configuration['excluded_urls']);
        $this->assertTrue(in_array('wp_woocommerce_session_', $configuration['bypass_cookies'], true));
        $this->assertTrue(in_array('member_session', $configuration['bypass_cookies'], true));
        $this->assertFalse(in_array('s', $configuration['query_denylist'], true));
        $this->assertSame('tablet', $configuration['device_mode']);

        $mtime = filemtime($file);
        $this->assertTrue($writer->write($settings));
        $this->assertSame($mtime, filemtime($file), 'Unchanged configuration should not be rewritten.');
        $this->removeDirectory($directory);
    }
}
