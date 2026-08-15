<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Config\Settings;
use WPSCache\Infrastructure\WordPress\ObjectCacheConfig;
use WPSCache\Tests\Framework\TestCase;

final class ObjectCacheConfigTest extends TestCase
{
    public function testWritesTlsAndCredentialsForTheEarlyDropIn(): void
    {
        $directory = $this->temporaryDirectory('object-config');
        $file = $directory . '/object-runtime.php';
        $writer = new ObjectCacheConfig($file);
        $this->assertTrue($writer->write(new Settings([
            'redis_host' => 'redis.internal',
            'redis_port' => 6380,
            'redis_password' => 'a-special<>password',
            'redis_tls' => true,
        ])));
        $configuration = require $file;
        $this->assertSame('redis.internal', $configuration['host']);
        $this->assertSame('tls', $configuration['scheme']);
        $this->assertSame('a-special<>password', $configuration['password']);
        $writer->remove();
        $this->assertFalse(is_file($file));
        $this->removeDirectory($directory);
    }

    public function testWritesSelectableMemcachedBackend(): void
    {
        $directory = $this->temporaryDirectory('memcached-config');
        $file = $directory . '/object-runtime.php';
        $writer = new ObjectCacheConfig($file);
        $this->assertTrue($writer->write(new Settings([
            'memcached_cache' => true,
            'memcached_host' => 'cache.internal',
            'memcached_port' => 11212,
            'memcached_prefix' => 'site:',
            'memcached_persistent_id' => 'site-cache',
        ])));
        $configuration = require $file;
        $this->assertSame('memcached', $configuration['backend']);
        $this->assertSame('cache.internal', $configuration['memcached_host']);
        $this->assertSame(11212, $configuration['memcached_port']);
        $this->assertSame('site:', $configuration['memcached_prefix']);
        $this->removeDirectory($directory);
    }
}
