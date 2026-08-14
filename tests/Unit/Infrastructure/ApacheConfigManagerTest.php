<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Infrastructure\Server\ApacheConfigManager;
use WPSCache\Tests\Framework\TestCase;

final class ApacheConfigManagerTest extends TestCase
{
    public function testApplyRemovesOnlyTheLegacyOwnedBlock(): void
    {
        $directory = $this->temporaryDirectory('apache-config');
        $htaccess = $directory . '/.htaccess';
        file_put_contents($htaccess, "# BEGIN WPS Cache\nInvalidDirective on\n# END WPS Cache\n# BEGIN WordPress\n");

        (new ApacheConfigManager($htaccess))->applyConfiguration();

        $this->assertSame("# BEGIN WordPress\n", (string) file_get_contents($htaccess));
        $this->removeDirectory($directory);
    }

    public function testApplyPreservesHtaccessWithoutOwnedMarkers(): void
    {
        $directory = $this->temporaryDirectory('apache-config-preserve');
        $htaccess = $directory . '/.htaccess';
        $content = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
        file_put_contents($htaccess, $content);

        (new ApacheConfigManager($htaccess))->applyConfiguration();

        $this->assertSame($content, (string) file_get_contents($htaccess));
        $this->removeDirectory($directory);
    }
}
