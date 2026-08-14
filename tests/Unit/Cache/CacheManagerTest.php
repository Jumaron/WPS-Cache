<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Cache;

use LogicException;
use WPSCache\Cache\CacheManager;
use WPSCache\Contracts\Module;
use WPSCache\Contracts\Purgeable;
use WPSCache\Tests\Framework\TestCase;

final class CacheManagerTest extends TestCase
{
    public function testBootsAndPurgesRegisteredModules(): void
    {
        $module = new class implements Module, Purgeable {
            public int $boots = 0;
            public int $purges = 0;
            public function id(): string { return 'fake'; }
            public function boot(): void { $this->boots++; }
            public function purge(): void { $this->purges++; }
        };
        $manager = new CacheManager();
        $manager->register($module);

        $manager->boot();
        $manager->boot();
        $this->assertSame(1, $module->boots);
        $this->assertTrue($manager->clearContentCaches());
        $this->assertSame(1, $module->purges);
        $this->assertSame($module, $manager->get('fake'));
    }

    public function testRejectsDuplicateModuleIdentifiers(): void
    {
        $module = static fn() => new class implements Module, Purgeable {
            public function id(): string { return 'duplicate'; }
            public function boot(): void {}
            public function purge(): void {}
        };
        $manager = new CacheManager();
        $manager->register($module());

        try {
            $manager->register($module());
        } catch (LogicException) {
            $this->assertTrue(true);
            return;
        }

        $this->assertTrue(false, 'Expected duplicate registration to throw.');
    }
}
