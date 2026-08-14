<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Infrastructure\WordPress\WpConfigManager;
use WPSCache\Tests\Framework\TestCase;

final class WpConfigManagerTest extends TestCase
{
    public function testEnablesUpdatesAndDisablesWpCacheWithoutDuplicates(): void
    {
        $directory = $this->temporaryDirectory('wp-config');
        $file = $directory . '/wp-config.php';
        file_put_contents($file, "<?php\ndefine('DB_NAME', 'test');\n");
        $manager = new WpConfigManager($file);

        $this->assertTrue($manager->enableCache());
        $this->assertTrue($manager->enableCache());
        $enabled = file_get_contents($file);
        $this->assertSame(1, substr_count((string) $enabled, "define('WP_CACHE', true);"));

        $this->assertTrue($manager->disableCache());
        $this->assertFalse(str_contains((string) file_get_contents($file), 'WP_CACHE'));
        $this->removeDirectory($directory);
    }
}
