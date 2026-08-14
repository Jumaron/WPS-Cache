<?php

declare(strict_types=1);

namespace WPSCache\Tests\Framework;

use RuntimeException;

abstract class TestCase
{
    protected function setUp(): void
    {
        \WPTestState::reset();
    }

    protected function tearDown(): void
    {
    }

    final public function run(string $method): void
    {
        $this->setUp();
        try {
            $this->{$method}();
        } finally {
            $this->tearDown();
        }
    }

    protected function assertTrue(bool $condition, string $message = 'Expected true.'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Expected false.'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message !== '' ? $message : sprintf(
                "Expected %s, got %s.",
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    protected function assertContains(string $needle, string $haystack): void
    {
        $this->assertTrue(str_contains($haystack, $needle), 'Missing expected text: ' . $needle);
    }

    protected function assertFileExists(string $file): void
    {
        $this->assertTrue(is_file($file), 'Expected file does not exist: ' . $file);
    }

    protected function temporaryDirectory(string $name): string
    {
        $directory = dirname(__DIR__) . '/.tmp/' . preg_replace('/[^a-z0-9_-]/i', '-', $name) . '-' . bin2hex(random_bytes(4));
        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create test directory: ' . $directory);
        }

        return $directory;
    }

    protected function removeDirectory(string $directory): void
    {
        $root = realpath(dirname(__DIR__) . '/.tmp');
        $target = realpath($directory);
        if ($root === false || $target === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($target);
    }
}
