<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Framework/TestCase.php';

$before = get_declared_classes();
$testFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $testFiles[] = $file->getPathname();
    }
}
sort($testFiles, SORT_STRING);
foreach ($testFiles as $file) {
    require_once $file;
}

$classes = array_values(array_diff(get_declared_classes(), $before));
$tests = 0;
$failures = [];

foreach ($classes as $class) {
    if (!is_subclass_of($class, \WPSCache\Tests\Framework\TestCase::class)) {
        continue;
    }

    $instance = new $class();
    $methods = array_filter(get_class_methods($instance), static fn(string $method): bool => str_starts_with($method, 'test'));
    sort($methods, SORT_STRING);

    foreach ($methods as $method) {
        $tests++;
        try {
            $instance->run($method);
            fwrite(STDOUT, '.');
        } catch (Throwable $exception) {
            fwrite(STDOUT, 'F');
            $failures[] = $class . '::' . $method . "\n  " . $exception->getMessage();
        }
    }
}

fwrite(STDOUT, PHP_EOL);
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL . PHP_EOL, $failures) . PHP_EOL);
    fwrite(STDERR, sprintf("%d tests, %d failures.\n", $tests, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("%d tests passed.\n", $tests));
