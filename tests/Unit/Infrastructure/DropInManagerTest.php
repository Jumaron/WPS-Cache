<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Infrastructure\WordPress\DropInManager;
use WPSCache\Tests\Framework\TestCase;

final class DropInManagerTest extends TestCase
{
    public function testInstallsRefreshesAndRemovesOnlyOwnedDropIns(): void
    {
        $directory = $this->temporaryDirectory('drop-ins');
        $templates = $directory . '/templates';
        $content = $directory . '/content';
        mkdir($templates);
        mkdir($content);
        file_put_contents($templates . '/advanced-cache-template.php', '<?php // WPS-Cache v1');
        file_put_contents($templates . '/object-cache.php', '<?php // WPS Cache object');
        file_put_contents($templates . '/object-cache-memcached.php', '<?php // WPS Cache memcached object');
        $manager = new DropInManager($templates, $content);

        $this->assertTrue($manager->installAdvancedCache());
        $this->assertTrue($manager->owns('advanced-cache.php'));
        file_put_contents($templates . '/advanced-cache-template.php', '<?php // WPS-Cache v2');
        $this->assertTrue($manager->installAdvancedCache());
        $this->assertContains('v2', (string) file_get_contents($content . '/advanced-cache.php'));

        file_put_contents($content . '/object-cache.php', '<?php // foreign cache');
        $this->assertFalse($manager->installObjectCache());
        $this->assertFalse($manager->removeObjectCache());
        $this->assertFileExists($content . '/object-cache.php');

        unlink($content . '/object-cache.php');
        $this->assertTrue($manager->installObjectCache('memcached'));
        $this->assertContains('memcached object', (string) file_get_contents($content . '/object-cache.php'));

        $this->assertTrue($manager->removeAdvancedCache());
        $this->assertFalse(is_file($content . '/advanced-cache.php'));
        $this->removeDirectory($directory);
    }
}
