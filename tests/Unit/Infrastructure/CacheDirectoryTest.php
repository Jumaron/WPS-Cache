<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Infrastructure\Filesystem\CacheDirectory;
use WPSCache\Tests\Framework\TestCase;

final class CacheDirectoryTest extends TestCase
{
    public function testPreparesRuntimeDirectoriesWithoutServerConfiguration(): void
    {
        $directory = $this->temporaryDirectory('cache-directory') . '/cache/';
        $this->assertTrue((new CacheDirectory($directory))->prepare());

        foreach (['html', 'css', 'js', 'fonts'] as $child) {
            $this->assertFileExists($directory . $child . '/index.php');
        }
        $this->assertFalse(is_file($directory . '.htaccess'));
        $this->removeDirectory(dirname(rtrim($directory, '/')));
    }

    public function testRemovesOnlyTheLegacyOwnedAccessRules(): void
    {
        $directory = $this->temporaryDirectory('cache-directory-upgrade') . '/cache/';
        mkdir($directory);
        file_put_contents($directory . '.htaccess', <<<'HTACCESS'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order Deny,Allow
    Deny from all
</IfModule>
<FilesMatch "\.(css|js|html|xml|txt|map|woff|woff2|ttf|otf|eot|svg|webp|png|jpg|jpeg|gif|avif)(\.gz|\.br)?$">
    <IfModule mod_authz_core.c>
        Require all granted
    </IfModule>
    <IfModule !mod_authz_core.c>
        Allow from all
    </IfModule>
</FilesMatch>
HTACCESS);

        $this->assertTrue((new CacheDirectory($directory))->prepare());
        $this->assertFalse(is_file($directory . '.htaccess'));
        $this->removeDirectory(dirname(rtrim($directory, '/')));
    }
}
