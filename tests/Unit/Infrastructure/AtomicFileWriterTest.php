<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Infrastructure\Filesystem\AtomicFileWriter;
use WPSCache\Tests\Framework\TestCase;

final class AtomicFileWriterTest extends TestCase
{
    public function testCreatesAndReplacesACompleteVerifiedFile(): void
    {
        $directory = $this->temporaryDirectory('atomic-writer');
        $file = $directory . '/runtime.php';
        $this->assertTrue(AtomicFileWriter::replace($file, '<?php return 1;', 0600));
        $this->assertSame('<?php return 1;', file_get_contents($file));
        $this->assertTrue(AtomicFileWriter::replace($file, '<?php return 2;', 0600));
        $this->assertSame('<?php return 2;', file_get_contents($file));
        $this->removeDirectory($directory);
    }
}
