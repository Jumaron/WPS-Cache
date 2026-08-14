<?php

declare(strict_types=1);

namespace WPSCache\Tests\Integration;

use WPSCache\Tests\Framework\TestCase;

final class BuildTest extends TestCase
{
    public function testBuildCreatesAValidDeterministicVersionedArchive(): void
    {
        $root = dirname(__DIR__, 2);
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build.php');
        exec($command, $firstOutput, $firstStatus);
        $this->assertSame(0, $firstStatus, implode("\n", $firstOutput));

        $zip = $root . '/dist/wps-cache-0.1.0.zip';
        $checksum = $root . '/dist/wps-cache-0.1.0.sha256';
        $this->assertFileExists($zip);
        $this->assertFileExists($checksum);
        $firstHash = hash_file('sha256', $zip);
        $this->assertContains((string) $firstHash, (string) file_get_contents($checksum));
        $this->assertContains('WPS-Cache/wps-cache.php', (string) file_get_contents($zip));

        exec($command, $secondOutput, $secondStatus);
        $this->assertSame(0, $secondStatus, implode("\n", $secondOutput));
        $this->assertSame($firstHash, hash_file('sha256', $zip));
    }
}
