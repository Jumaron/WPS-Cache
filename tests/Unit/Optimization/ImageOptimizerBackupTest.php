<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Optimization;

use WPSCache\Config\Settings;
use WPSCache\Optimization\Media\ImageOptimizer;
use WPSCache\Tests\Framework\TestCase;

final class ImageOptimizerBackupTest extends TestCase
{
    public function testBackupIsPhpGuardedAndRestoresExactBytes(): void
    {
        $directory = WP_CONTENT_DIR . '/uploads/wpsc-test-' . bin2hex(random_bytes(4));
        mkdir($directory, 0755, true);
        $file = $directory . '/photo.jpg';
        file_put_contents($file, "original-image-bytes\0\1");
        $optimizer = new ImageOptimizer(new Settings(['image_backup_originals' => true]));

        $optimizer->optimizeFile($file);
        $backup = $file . '.wps-original.php';
        $this->assertFileExists($backup);
        $this->assertContains('<?php exit; __halt_compiler(); ?>', (string) file_get_contents($backup));

        file_put_contents($file, 'changed');
        $this->assertTrue($optimizer->restore($file));
        $this->assertSame("original-image-bytes\0\1", file_get_contents($file));
        $this->removeDirectory($directory);
    }
}
