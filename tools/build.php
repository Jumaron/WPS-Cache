<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pluginDirectory = $root . '/WPS-Cache';
$entryFile = $pluginDirectory . '/wps-cache.php';
$readmeFile = $pluginDirectory . '/readme.txt';

$entry = file_get_contents($entryFile);
$readme = file_get_contents($readmeFile);
if (!is_string($entry) || !preg_match('/^\s*\* Version:\s*([^\s]+)/m', $entry, $headerMatch)) {
    fwrite(STDERR, "Could not read the plugin version.\n");
    exit(1);
}

$version = $headerMatch[1];
if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) {
    fwrite(STDERR, "Invalid semantic version: {$version}\n");
    exit(1);
}
if (!is_string($readme) || !preg_match('/^Stable tag:\s*([^\s]+)/mi', $readme, $readmeMatch)) {
    fwrite(STDERR, "Could not read Stable tag from readme.txt.\n");
    exit(1);
}
if ($readmeMatch[1] !== $version) {
    fwrite(STDERR, "Version mismatch: plugin={$version}, readme={$readmeMatch[1]}.\n");
    exit(1);
}
if (!str_contains($entry, "define('WPSC_VERSION', '{$version}')")) {
    fwrite(STDERR, "WPSC_VERSION does not match the plugin header.\n");
    exit(1);
}

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDirectory, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($pluginDirectory) + 1));
    if (
        str_starts_with($relative, 'cache/') ||
        str_starts_with($relative, 'vendor/') ||
        str_contains($relative, '/.DS_Store') ||
        str_ends_with($relative, '.log')
    ) {
        continue;
    }

    $files['WPS-Cache/' . $relative] = $file->getPathname();
}
ksort($files, SORT_STRING);

$archive = '';
$centralDirectory = '';
$offset = 0;
$entries = 0;
$dosTime = 0;
$dosDate = 33; // 1980-01-01, the earliest ZIP timestamp.
$utf8Flag = 0x0800;

foreach ($files as $name => $source) {
    $data = file_get_contents($source);
    if (!is_string($data)) {
        fwrite(STDERR, "Could not read {$source}.\n");
        exit(1);
    }

    $size = strlen($data);
    $deflated = gzdeflate($data, 9);
    $method = is_string($deflated) && strlen($deflated) < $size ? 8 : 0;
    $payload = $method === 8 ? $deflated : $data;
    $compressedSize = strlen($payload);
    $crc = hexdec(hash('crc32b', $data));
    $nameLength = strlen($name);
    $localHeader = pack(
        'VvvvvvVVVvv',
        0x04034b50,
        20,
        $utf8Flag,
        $method,
        $dosTime,
        $dosDate,
        $crc,
        $compressedSize,
        $size,
        $nameLength,
        0,
    );
    $archive .= $localHeader . $name . $payload;

    $centralDirectory .= pack(
        'VvvvvvvVVVvvvvvVV',
        0x02014b50,
        20,
        20,
        $utf8Flag,
        $method,
        $dosTime,
        $dosDate,
        $crc,
        $compressedSize,
        $size,
        $nameLength,
        0,
        0,
        0,
        0,
        0,
        $offset,
    ) . $name;

    $offset += strlen($localHeader) + $nameLength + $compressedSize;
    $entries++;
}

$centralOffset = strlen($archive);
$archive .= $centralDirectory;
$archive .= pack(
    'VvvvvVVv',
    0x06054b50,
    0,
    0,
    $entries,
    $entries,
    strlen($centralDirectory),
    $centralOffset,
    0,
);

$dist = $root . '/dist';
if (!is_dir($dist) && !mkdir($dist, 0755, true) && !is_dir($dist)) {
    fwrite(STDERR, "Could not create dist directory.\n");
    exit(1);
}

$zipFile = $dist . '/wps-cache-' . $version . '.zip';
if (file_put_contents($zipFile, $archive, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write {$zipFile}.\n");
    exit(1);
}

$checksum = hash_file('sha256', $zipFile);
$checksumFile = $dist . '/wps-cache-' . $version . '.sha256';
file_put_contents($checksumFile, $checksum . '  ' . basename($zipFile) . PHP_EOL, LOCK_EX);

fwrite(STDOUT, sprintf(
    "Built %s (%d files, %d bytes)\nSHA-256: %s\n",
    str_replace('\\', '/', $zipFile),
    $entries,
    filesize($zipFile),
    $checksum,
));
