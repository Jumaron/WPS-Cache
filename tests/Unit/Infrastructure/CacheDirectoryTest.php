<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Infrastructure\Filesystem\CacheDirectory;
use WPSCache\Tests\Framework\TestCase;

final class CacheDirectoryTest extends TestCase
{
    public function testPreparesRuntimeDirectoriesAndAccessRules(): void
    {
        $directory = $this->temporaryDirectory('cache-directory') . '/cache/';
        $this->assertTrue((new CacheDirectory($directory))->prepare());

        foreach (['html', 'css', 'js', 'fonts'] as $child) {
            $this->assertFileExists($directory . $child . '/index.php');
        }
        $this->assertFileExists($directory . '.htaccess');
        $this->assertContains('Require all denied', (string) file_get_contents($directory . '.htaccess'));
        $this->removeDirectory(dirname(rtrim($directory, '/')));
    }
}
