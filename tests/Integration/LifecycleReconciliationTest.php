<?php

declare(strict_types=1);

namespace WPSCache\Tests\Integration;

use WPSCache\Cache\CacheManager;
use WPSCache\Config\Settings;
use WPSCache\Config\SettingsRepository;
use WPSCache\Infrastructure\Filesystem\CacheDirectory;
use WPSCache\Infrastructure\Http\SameOriginUrlGuard;
use WPSCache\Infrastructure\Server\ApacheConfigManager;
use WPSCache\Infrastructure\WordPress\DropInManager;
use WPSCache\Infrastructure\WordPress\EarlyCacheConfig;
use WPSCache\Infrastructure\WordPress\WpConfigManager;
use WPSCache\Lifecycle\LifecycleManager;
use WPSCache\Maintenance\DatabaseOptimizer;
use WPSCache\Scheduling\MaintenanceScheduler;
use WPSCache\Scheduling\PreloadScheduler;
use WPSCache\Tests\Framework\TestCase;

final class LifecycleReconciliationTest extends TestCase
{
    public function testSettingsReconcileTheEarlyCacheDropInAndWpConfig(): void
    {
        $directory = $this->temporaryDirectory('lifecycle');
        $contentDirectory = $directory . '/wp-content';
        mkdir($contentDirectory);
        $wpConfig = $directory . '/wp-config.php';
        file_put_contents($wpConfig, "<?php\ndefine('WP_CACHE', true);\n");
        $htaccess = $directory . '/.htaccess';
        file_put_contents($htaccess, "# WordPress\n");

        $settings = new Settings();
        $cacheManager = new CacheManager();
        $databaseOptimizer = new DatabaseOptimizer($settings->all());
        $dropIns = new DropInManager(WPSC_PLUGIN_DIR . 'includes', $contentDirectory);
        $this->assertTrue($dropIns->installAdvancedCache());

        $lifecycle = new LifecycleManager(
            new SettingsRepository(),
            new CacheDirectory($directory . '/cache/'),
            new WpConfigManager($wpConfig),
            $dropIns,
            new EarlyCacheConfig($directory . '/cache/runtime.php'),
            new ApacheConfigManager($settings, $htaccess),
            $cacheManager,
            new PreloadScheduler(new SameOriginUrlGuard('https://example.test/')),
            new MaintenanceScheduler($cacheManager, $databaseOptimizer),
        );

        $lifecycle->settingsUpdated(['html_cache' => false]);
        $this->assertFalse(is_file($contentDirectory . '/advanced-cache.php'));
        $this->assertFalse(str_contains((string) file_get_contents($wpConfig), 'WP_CACHE'));

        $lifecycle->settingsUpdated(['html_cache' => true]);
        $this->assertFileExists($contentDirectory . '/advanced-cache.php');
        $this->assertContains("define('WP_CACHE', true);", (string) file_get_contents($wpConfig));
        $this->assertFileExists($directory . '/cache/runtime.php');

        $this->removeDirectory($directory);
    }
}
