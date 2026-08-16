<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Config\Settings;
use WPSCache\Infrastructure\WordPress\DropInManager;
use WPSCache\Infrastructure\WordPress\ObjectCacheCompatibility;
use WPSCache\Tests\Framework\TestCase;

final class ObjectCacheCompatibilityTest extends TestCase
{
    public function testRequiresAConfiguredBackendAndAcceptsAHealthyVerifiedBackend(): void
    {
        [$directory, $manager, $runtime] = $this->environment();
        $compatibility = new ObjectCacheCompatibility($manager, $runtime, static fn(string $backend, Settings $settings): ?string => null);

        $unconfigured = $compatibility->inspect(new Settings(), true);
        $this->assertFalse($unconfigured->compatible());
        $this->assertContains('Enable and save', $unconfigured->message());

        $healthy = $compatibility->inspect(new Settings(['redis_cache' => true]), true);
        $this->assertTrue($healthy->compatible());
        $this->assertSame('redis', $healthy->backend);
        $this->removeDirectory($directory);
    }

    public function testRejectsFailedHealthChecksAndForeignDropIns(): void
    {
        [$directory, $manager, $runtime] = $this->environment();
        $failed = new ObjectCacheCompatibility($manager, $runtime, static fn(string $backend, Settings $settings): ?string => 'Service health check failed.');
        $result = $failed->inspect(new Settings(['memcached_cache' => true]), true);
        $this->assertFalse($result->compatible());
        $this->assertContains('health check failed', $result->message());

        file_put_contents($directory . '/content/object-cache.php', '<?php // foreign object cache');
        $foreign = new ObjectCacheCompatibility($manager, $runtime, static fn(string $backend, Settings $settings): ?string => null);
        $result = $foreign->inspect(new Settings(['redis_cache' => true]), true);
        $this->assertFalse($result->compatible());
        $this->assertContains('Another plugin owns', $result->message());
        $this->removeDirectory($directory);
    }

    /** @return array{string, DropInManager, string} */
    private function environment(): array
    {
        $directory = $this->temporaryDirectory('object-compatibility');
        mkdir($directory . '/templates');
        mkdir($directory . '/content');
        mkdir($directory . '/cache');
        file_put_contents($directory . '/templates/object-cache.php', '<?php // WPS Cache Redis object template');
        file_put_contents($directory . '/templates/object-cache-memcached.php', '<?php // WPS-Cache Memcached Object Cache Backend');
        return [
            $directory,
            new DropInManager($directory . '/templates', $directory . '/content'),
            $directory . '/cache/object-runtime.php',
        ];
    }
}
