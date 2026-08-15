<?php

declare(strict_types=1);

namespace WPSCache\Tests\Integration;

use WPSCache\Tests\Framework\TestCase;

final class AdvancedCacheTemplateTest extends TestCase
{
    public function testServesFreshCacheAndBypassesWooCommerceState(): void
    {
        $directory = $this->temporaryDirectory('advanced-cache');
        $content = $directory . '/wp-content';
        $cache = $content . '/cache/wps-cache/html/example.test';
        mkdir($cache, 0755, true);
        file_put_contents($cache . '/index.html', '<html>cached</html>');
        $runtime = $content . '/cache/wps-cache/runtime.php';
        file_put_contents($runtime, "<?php return ['ttl' => 3600, 'bypass_cookies' => ['wp_woocommerce_session_'], 'excluded_urls' => []];");

        $served = $this->runDropIn($directory, '');
        $this->assertSame('<html>cached</html>', $served);

        $bypassed = $this->runDropIn($directory, 'wp_woocommerce_session_hash=value');
        $this->assertSame('FALLTHROUGH', $bypassed);
        $this->removeDirectory($directory);
    }

    public function testUsesGeneratedTtlAndUrlExclusions(): void
    {
        $directory = $this->temporaryDirectory('advanced-cache-ttl');
        $content = $directory . '/wp-content';
        $cache = $content . '/cache/wps-cache/html/example.test';
        mkdir($cache, 0755, true);
        file_put_contents($cache . '/index.html', '<html>expired</html>');
        touch($cache . '/index.html', time() - 120);
        file_put_contents(
            $content . '/cache/wps-cache/runtime.php',
            "<?php return ['ttl' => 60, 'bypass_cookies' => [], 'excluded_urls' => ['/private']];",
        );

        $this->assertSame('FALLTHROUGH', $this->runDropIn($directory, ''));
        touch($cache . '/index.html');
        $this->assertSame('FALLTHROUGH', $this->runDropIn($directory, '', '/private'));
        $this->removeDirectory($directory);
    }

    public function testCanonicalizesTrackingQueriesAndRejectsDeniedQueries(): void
    {
        $directory = $this->temporaryDirectory('advanced-cache-query');
        $content = $directory . '/wp-content';
        $cache = $content . '/cache/wps-cache/html/example.test';
        mkdir($cache, 0755, true);
        file_put_contents($cache . '/index.html', '<html>canonical</html>');
        file_put_contents(
            $content . '/cache/wps-cache/runtime.php',
            "<?php return ['ttl' => 3600, 'query_mode' => 'variants', 'query_denylist' => ['preview'], 'ignored_query_params' => ['utm_*'], 'bypass_cookies' => []];",
        );

        $this->assertSame('<html>canonical</html>', $this->runDropIn($directory, '', '/?utm_source=newsletter'));
        $this->assertSame('FALLTHROUGH', $this->runDropIn($directory, '', '/?preview=1'));
        $this->removeDirectory($directory);
    }

    private function runDropIn(string $directory, string $cookie, string $uri = '/'): string
    {
        $wrapper = $directory . '/run.php';
        $template = dirname(__DIR__, 2) . '/WPS-Cache/includes/advanced-cache-template.php';
        $content = $directory . '/wp-content';
        $script = sprintf(
            <<<'PHP'
<?php
define('ABSPATH', %s);
define('WP_CONTENT_DIR', %s);
$_SERVER = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => %s,
    'HTTP_HOST' => 'example.test',
    'HTTP_COOKIE' => %s,
    'HTTP_ACCEPT_ENCODING' => '',
];
include %s;
echo 'FALLTHROUGH';
PHP,
            var_export($directory . '/', true),
            var_export($content, true),
            var_export($uri, true),
            var_export($cookie, true),
            var_export($template, true),
        );
        file_put_contents($wrapper, $script);

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapper), $output, $status);
        $this->assertSame(0, $status, 'Drop-in subprocess failed.');

        return implode("\n", $output);
    }
}
