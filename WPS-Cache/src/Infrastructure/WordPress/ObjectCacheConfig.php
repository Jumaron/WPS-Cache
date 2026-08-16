<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\WordPress;

use WPSCache\Config\Settings;
use WPSCache\Infrastructure\Filesystem\AtomicFileWriter;

/** Writes the object-cache drop-in's early connection configuration. */
final class ObjectCacheConfig
{
    public function __construct(private readonly string $file)
    {
    }

    public function write(Settings $settings): bool
    {
        $content = $this->content($settings);
        if ($this->matchesContent($content)) {
            return true;
        }
        return AtomicFileWriter::replace($this->file, $content, 0600);
    }

    public function matches(Settings $settings): bool
    {
        return $this->matchesContent($this->content($settings));
    }

    private function content(Settings $settings): string
    {
        $configuration = [
            'backend' => $settings->enabled('memcached_cache') ? 'memcached' : 'redis',
            'host' => $settings->string('redis_host'),
            'port' => max(1, min(65535, $settings->integer('redis_port'))),
            'database' => max(0, $settings->integer('redis_db')),
            'password' => $settings->string('redis_password'),
            'prefix' => $settings->string('redis_prefix'),
            'scheme' => $settings->enabled('redis_tls') ? 'tls' : 'tcp',
            'memcached_host' => $settings->string('memcached_host'),
            'memcached_port' => max(1, min(65535, $settings->integer('memcached_port'))),
            'memcached_prefix' => $settings->string('memcached_prefix'),
            'memcached_persistent_id' => $settings->string('memcached_persistent_id'),
        ];
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($configuration, true) . ";\n";
    }

    private function matchesContent(string $content): bool
    {
        return is_file($this->file) && hash_equals(hash('sha256', $content), hash_file('sha256', $this->file) ?: '');
    }

    public function remove(): void
    {
        @unlink($this->file);
    }
}
