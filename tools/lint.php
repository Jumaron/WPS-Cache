<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = [$root . '/WPS-Cache', $root . '/tests', $root . '/tools'];
$files = [];

foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        $normalized = str_replace('\\', '/', $file->getPathname());
        if (
            $file->isFile() &&
            $file->getExtension() === 'php' &&
            !str_contains($normalized, '/tests/.tmp/')
        ) {
            $files[] = $file->getPathname();
        }
    }
}

sort($files, SORT_STRING);
$failures = [];

foreach ($files as $file) {
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
    exec($command, $output, $status);
    if ($status !== 0) {
        $failures[] = implode(PHP_EOL, $output);
    }
    $output = [];
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf("Linted %d PHP files successfully.\n", count($files)));
