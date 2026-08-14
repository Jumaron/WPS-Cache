<?php

declare(strict_types=1);

namespace WPSCache\Bootstrap;

use WPSCache\Admin\AdminPanelManager;
use WPSCache\Admin\CacheActionController;
use WPSCache\Admin\DatabaseCleanupController;
use WPSCache\Admin\Settings\SettingsController;
use WPSCache\Admin\Settings\SettingsValidator;
use WPSCache\Admin\UI\NoticeManager;
use WPSCache\Cache\CacheManager;
use WPSCache\Cache\Object\RedisObjectCache;
use WPSCache\Cache\Page\PageCache;
use WPSCache\Cache\ReverseProxy\VarnishCache;
use WPSCache\Config\Settings;
use WPSCache\Config\SettingsRepository;
use WPSCache\Infrastructure\Filesystem\CacheDirectory;
use WPSCache\Infrastructure\Http\SameOriginUrlGuard;
use WPSCache\Infrastructure\Server\ApacheConfigManager;
use WPSCache\Infrastructure\WordPress\DropInManager;
use WPSCache\Infrastructure\WordPress\EarlyCacheConfig;
use WPSCache\Infrastructure\WordPress\WpConfigManager;
use WPSCache\Integration\Cloudflare\CloudflarePurger;
use WPSCache\Integration\WooCommerce\CacheBypass;
use WPSCache\Lifecycle\LifecycleManager;
use WPSCache\Maintenance\DatabaseOptimizer;
use WPSCache\Optimization\Assets\CssMinifier;
use WPSCache\Optimization\Assets\JsMinifier;
use WPSCache\Optimization\Html\CdnRewriter;
use WPSCache\Optimization\Html\FontOptimizer;
use WPSCache\Optimization\Html\FrontendOptimizer;
use WPSCache\Optimization\Html\InlineCssPruner;
use WPSCache\Optimization\Html\JavaScriptOptimizer;
use WPSCache\Optimization\Html\MediaOptimizer;
use WPSCache\Optimization\Navigation\SpeculativeLoader;
use WPSCache\Optimization\WordPress\BloatOptimizer;
use WPSCache\Scheduling\Intervals;
use WPSCache\Scheduling\MaintenanceScheduler;
use WPSCache\Scheduling\PreloadScheduler;

/** Composition root for the plugin. */
final class Application
{
    private static ?self $instance = null;

    private function __construct(
        private readonly CacheManager $cacheManager,
        private readonly LifecycleManager $lifecycle,
    ) {
    }

    public static function boot(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $settingsRepository = new SettingsRepository();
        $settings = $settingsRepository->load();
        $cacheManager = new CacheManager();

        self::registerCacheModules($cacheManager, $settings);

        $databaseOptimizer = new DatabaseOptimizer($settings->all());
        $preloadScheduler = new PreloadScheduler(new SameOriginUrlGuard(home_url('/')));
        $maintenanceScheduler = new MaintenanceScheduler($cacheManager, $databaseOptimizer);
        $apache = new ApacheConfigManager();
        $dropIns = new DropInManager(WPSC_PLUGIN_DIR . 'includes', WP_CONTENT_DIR);
        $earlyCacheConfig = new EarlyCacheConfig(WPSC_CACHE_DIR . 'runtime.php');
        if (!is_file(WPSC_CACHE_DIR . 'runtime.php')) {
            $earlyCacheConfig->write($settings);
        }

        $lifecycle = new LifecycleManager(
            $settingsRepository,
            new CacheDirectory(WPSC_CACHE_DIR),
            new WpConfigManager(self::wpConfigPath()),
            $dropIns,
            $earlyCacheConfig,
            $apache,
            $cacheManager,
            $preloadScheduler,
            $maintenanceScheduler,
        );

        $application = new self($cacheManager, $lifecycle);
        self::$instance = $application;

        $application->registerCoreHooks($apache);
        self::bootFrontendModules($settings, $preloadScheduler, $maintenanceScheduler);

        if (is_admin()) {
            $notices = new NoticeManager();
            new AdminPanelManager($cacheManager, $databaseOptimizer, $notices);
            (new SettingsController(new SettingsValidator()))->boot();
            (new CacheActionController(
                $cacheManager,
                $dropIns,
                $notices,
                new SameOriginUrlGuard(home_url('/')),
            ))->boot();
            (new DatabaseCleanupController($databaseOptimizer))->boot();
        }

        return $application;
    }

    private static function registerCacheModules(CacheManager $manager, Settings $settings): void
    {
        $commerce = new CacheBypass($settings);

        if ($settings->enabled('html_cache')) {
            $manager->register(new PageCache($settings, $commerce));
        }
        if ($settings->enabled('redis_cache')) {
            $manager->register(new RedisObjectCache(
                $settings,
                defined('WP_REDIS_HOST') ? (string) WP_REDIS_HOST : $settings->string('redis_host'),
                defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : $settings->integer('redis_port'),
                defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : $settings->integer('redis_db'),
                1.0,
                1.0,
                defined('WP_REDIS_PASSWORD') ? (string) WP_REDIS_PASSWORD : $settings->string('redis_password'),
                defined('WP_REDIS_PREFIX') ? (string) WP_REDIS_PREFIX : $settings->string('redis_prefix'),
            ));
        }
        if ($settings->enabled('varnish_cache')) {
            $manager->register(new VarnishCache(
                $settings,
                $settings->string('varnish_host'),
                $settings->integer('varnish_port'),
            ));
        }
        if ($settings->enabled('css_minify')) {
            $manager->register(new CssMinifier($settings));
        }
        if ($settings->enabled('js_minify')) {
            $manager->register(new JsMinifier($settings));
        }
    }

    private static function bootFrontendModules(
        Settings $settings,
        PreloadScheduler $preloadScheduler,
        MaintenanceScheduler $maintenanceScheduler,
    ): void {
        $cdn = new CdnRewriter($settings);
        (new CloudflarePurger($settings))->boot();

        (new FrontendOptimizer([
            new InlineCssPruner($settings->all()),
            new JavaScriptOptimizer($settings->all()),
            $cdn,
            new FontOptimizer($settings->all()),
            new MediaOptimizer($settings->all()),
        ]))->boot();

        (new SpeculativeLoader($settings->all()))->boot();
        (new BloatOptimizer($settings->all()))->boot();
        (new Intervals())->boot();
        $preloadScheduler->boot();
        $maintenanceScheduler->boot();
    }

    private function registerCoreHooks(ApacheConfigManager $apache): void
    {
        add_action('plugins_loaded', [$this->lifecycle, 'maybeUpgrade'], 1);
        add_action('plugins_loaded', [$this->cacheManager, 'boot'], 5);
        add_action('send_headers', [$apache, 'sendSecurityHeaders']);
        add_action('save_post', [$this->cacheManager, 'clearContentCaches']);
        add_action('comment_post', [$this->cacheManager, 'clearContentCaches']);

        foreach ([
            'wpsc_clear_cache',
            'switch_theme',
            'customize_save',
            'activated_plugin',
            'deactivated_plugin',
            'upgrader_process_complete',
        ] as $hook) {
            add_action($hook, [$this->cacheManager, 'clearAllCaches']);
        }

        add_action('wpscac_settings_updated', [$this->lifecycle, 'settingsUpdated']);
        register_activation_hook(WPSC_PLUGIN_FILE, [$this->lifecycle, 'activate']);
        register_deactivation_hook(WPSC_PLUGIN_FILE, [$this->lifecycle, 'deactivate']);
    }

    private static function wpConfigPath(): string
    {
        $insideRoot = ABSPATH . 'wp-config.php';
        if (is_file($insideRoot)) {
            return $insideRoot;
        }

        return dirname(rtrim(ABSPATH, '/\\')) . '/wp-config.php';
    }
}
