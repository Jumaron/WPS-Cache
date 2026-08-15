<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\WordPress;

use WPSCache\Config\Settings;

/** Writes the object-cache drop-in's early connection configuration. */
final class ObjectCacheConfig
{
    public function __construct(private readonly string $file)
    {
    }

    public function write(Settings $settings): bool
    {
        $configuration = [
            'host' => $settings->string('redis_host'),
            'port' => max(1, min(65535, $settings->integer('redis_port'))),
            'database' => max(0, $settings->integer('redis_db')),
            'password' => $settings->string('redis_password'),
            'prefix' => $settings->string('redis_prefix'),
            'scheme' => $settings->enabled('redis_tls') ? 'tls' : 'tcp',
        ];
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($configuration, true) . ";\n";
        if (is_file($this->file) && hash_equals(hash('sha256', $content), hash_file('sha256', $this->file) ?: '')) {
            return true;
        }
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }
        $temporary = tempnam($directory, 'wpsc_object_');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);
            return false;
        }
        return true;
    }

    public function remove(): void
    {
        @unlink($this->file);
    }
}
