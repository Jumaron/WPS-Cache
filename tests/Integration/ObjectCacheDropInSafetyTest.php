<?php

declare(strict_types=1);

namespace WPSCache\Tests\Integration;

use RuntimeException;
use WPSCache\Tests\Framework\TestCase;

final class ObjectCacheDropInSafetyTest extends TestCase
{
    public function testRedisDropInBootsWithoutAClientAndWhenExplicitlyDisabled(): void
    {
        $this->assertSafeBoot(false);
        $this->assertSafeBoot(true);
    }

    private function assertSafeBoot(bool $disabled): void
    {
        $directory = $this->temporaryDirectory('redis-dropin-safety');
        $dropIn = var_export(WPSC_PLUGIN_DIR . 'includes/object-cache.php', true);
        $content = var_export($directory, true);
        $script = "<?php\n"
            . "declare(strict_types=1);\n"
            . "define('ABSPATH', {$content} . '/');\n"
            . "define('WP_CONTENT_DIR', {$content});\n"
            . ($disabled ? "define('WP_REDIS_DISABLED', true);\n" : '')
            . "\$table_prefix = 'wp_'; \$blog_id = 1;\n"
            . "function is_multisite(): bool { return false; }\n"
            . "function wp_suspend_cache_addition(): bool { return false; }\n"
            . "function do_action(string \$hook, mixed ...\$args): void {}\n"
            . "require {$dropIn};\n"
            . "wp_cache_init();\n"
            . "\$stored = wp_cache_set('probe', 'value');\n"
            . "\$value = wp_cache_get('probe', 'default', false, \$found);\n"
            . "echo function_exists('wp_cache_init') && isset(\$GLOBALS['wp_object_cache']) && \$stored && \$found && \$value === 'value' ? 'safe' : 'unsafe';\n";
        $scriptFile = $directory . '/boot.php';
        file_put_contents($scriptFile, $script);

        $pipes = [];
        $process = proc_open([PHP_BINARY, $scriptFile], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start isolated PHP process.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, (string) $error);
        $this->assertSame('safe', $output);
        $this->removeDirectory($directory);
    }
}
