<?php

declare(strict_types=1);

namespace WPSCache\Bootstrap;

use WPSCache\Admin\AdminPanelManager;
use WPSCache\Admin\CacheActionController;
use WPSCache\Admin\DatabaseCleanupController;
use WPSCache\Admin\ImageActionController;
use WPSCache\Admin\SettingsTransferController;
use WPSCache\Admin\NetworkController;
use WPSCache\Admin\Settings\SettingsController;
use WPSCache\Admin\Settings\SettingsValidator;
use WPSCache\Admin\UI\NoticeManager;
use WPSCache\Cache\CacheManager;
use WPSCache\Cache\Object\RedisObjectCache;
use WPSCache\Cache\Object\MemcachedObjectCache;
use WPSCache\Cache\Page\PageCache;
use WPSCache\Cache\ReverseProxy\VarnishCache;
use WPSCache\Cache\ReverseProxy\NginxCache;
use WPSCache\Cache\Rest\RestResponseCache;
use WPSCache\Cache\Fragment\FragmentEndpoint;
use WPSCache\Cli\Commands;
use WPSCache\Config\Settings;
use WPSCache\Config\SettingsRepository;
use WPSCache\Infrastructure\Filesystem\CacheDirectory;
use WPSCache\Infrastructure\Http\SameOriginUrlGuard;
use WPSCache\Infrastructure\Server\ApacheConfigManager;
use WPSCache\Infrastructure\WordPress\DropInManager;
use WPSCache\Infrastructure\WordPress\EarlyCacheConfig;
use WPSCache\Infrastructure\WordPress\WpConfigManager;
use WPSCache\Infrastructure\WordPress\ObjectCacheConfig;
use WPSCache\Integration\Cloudflare\CloudflarePurger;
use WPSCache\Integration\WooCommerce\CacheBypass;
use WPSCache\Integration\Media\AltTextProvider;
use WPSCache\Integration\Media\MediaOffloadProvider;
use WPSCache\Lifecycle\LifecycleManager;
use WPSCache\Maintenance\DatabaseOptimizer;
use WPSCache\Monitoring\PerformanceMonitor;
use WPSCache\Optimization\Assets\CssMinifier;
use WPSCache\Optimization\Assets\AssetCombiner;
use WPSCache\Optimization\Assets\ScriptManager;
use WPSCache\Optimization\Assets\JsMinifier;
use WPSCache\Optimization\Html\CdnRewriter;
use WPSCache\Optimization\Html\CssDeliveryOptimizer;
use WPSCache\Optimization\Html\FontOptimizer;
use WPSCache\Optimization\Html\ExternalAssetLocalizer;
use WPSCache\Optimization\Html\FrontendOptimizer;
use WPSCache\Optimization\Html\HtmlMinifier;
use WPSCache\Optimization\Html\InlineCssPruner;
use WPSCache\Optimization\Html\JavaScriptOptimizer;
use WPSCache\Optimization\Html\LazyRenderOptimizer;
use WPSCache\Optimization\Html\MediaOptimizer;
use WPSCache\Optimization\Html\NextGenImageDelivery;
use WPSCache\Optimization\Html\ResourceHintOptimizer;
use WPSCache\Optimization\Html\RenderedCssOptimizer;
use WPSCache\Optimization\Media\ImageOptimizer;
use WPSCache\Optimization\Media\AdaptiveImageService;
use WPSCache\Optimization\Media\GravatarCache;
use WPSCache\Optimization\Navigation\SpeculativeLoader;
use WPSCache\Optimization\WordPress\BloatOptimizer;
use WPSCache\Scheduling\Intervals;
use WPSCache\Scheduling\MaintenanceScheduler;
use WPSCache\Scheduling\PreloadScheduler;
use WPSCache\Scheduling\PreloadUrlProvider;

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
        $urlGuard = new SameOriginUrlGuard(home_url('/'));
        $preloadScheduler = new PreloadScheduler($urlGuard, $settings);
        $imageOptimizer = new ImageOptimizer($settings);
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
            new ObjectCacheConfig(WPSC_CACHE_DIR . 'object-runtime.php'),
        );

        $application = new self($cacheManager, $lifecycle);
        self::$instance = $application;

        $application->registerCoreHooks($apache);
        self::bootFrontendModules($settings, $preloadScheduler, $maintenanceScheduler, $imageOptimizer);
        (new Commands(
            $cacheManager,
            new PreloadUrlProvider($settings, $urlGuard),
            $imageOptimizer,
            $databaseOptimizer,
            $settings,
        ))->boot();
        (new PerformanceMonitor($settings))->boot();
        (new FragmentEndpoint($settings))->boot();

        if (is_admin()) {
            $notices = new NoticeManager();
            new AdminPanelManager($cacheManager, $databaseOptimizer, $notices);
            (new SettingsController(new SettingsValidator()))->boot();
            (new CacheActionController(
                $cacheManager,
                $dropIns,
                $notices,
                $urlGuard,
                new PreloadUrlProvider($settings, $urlGuard),
            ))->boot();
            (new DatabaseCleanupController($databaseOptimizer))->boot();
            (new ImageActionController($imageOptimizer))->boot();
            (new SettingsTransferController(new SettingsValidator()))->boot();
            (new NetworkController())->boot();
        }

        return $application;
    }

    private static function registerCacheModules(CacheManager $manager, Settings $settings): void
    {
        $commerce = new CacheBypass($settings);

        if ($settings->enabled('html_cache')) {
            $manager->register(new PageCache($settings, $commerce));
        }
        if ($settings->enabled('memcached_cache')) {
            $manager->register(new MemcachedObjectCache($settings));
        } elseif ($settings->enabled('redis_cache')) {
            $manager->register(new RedisObjectCache(
                $settings,
                defined('WP_REDIS_HOST') ? (string) WP_REDIS_HOST : ($settings->enabled('redis_tls') ? 'tls://' : '') . $settings->string('redis_host'),
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
                $settings->integer('cache_lifetime'),
            ));
        }
        if ($settings->enabled('nginx_cache')) {
            $manager->register(new NginxCache($settings));
        }
        if ($settings->enabled('rest_cache')) {
            $manager->register(new RestResponseCache($settings));
        }
        if ($settings->enabled('css_minify')) {
            $manager->register(new CssMinifier($settings));
        }
        if ($settings->enabled('js_minify')) {
            $manager->register(new JsMinifier($settings));
        }
        if ($settings->enabled('css_combine') || $settings->enabled('js_combine')) {
            $manager->register(new AssetCombiner($settings));
        }
    }

    private static function bootFrontendModules(
        Settings $settings,
        PreloadScheduler $preloadScheduler,
        MaintenanceScheduler $maintenanceScheduler,
        ImageOptimizer $imageOptimizer,
    ): void {
        $cdn = new CdnRewriter($settings);
        $renderedCss = new RenderedCssOptimizer($settings);
        $renderedCss->boot();
        $adaptiveImages = new AdaptiveImageService($settings);
        $adaptiveImages->boot();
        (new CloudflarePurger($settings))->boot();

        (new FrontendOptimizer([
            new InlineCssPruner($settings->all()),
            $renderedCss,
            new CssDeliveryOptimizer($settings->all()),
            new JavaScriptOptimizer($settings->all()),
            new ExternalAssetLocalizer($settings->all()),
            new FontOptimizer($settings->all()),
            new MediaOptimizer($settings->all()),
            new NextGenImageDelivery($settings->all()),
            $adaptiveImages,
            $cdn,
            new LazyRenderOptimizer($settings->all()),
            new ResourceHintOptimizer($settings->all()),
            new HtmlMinifier($settings->all()),
        ], $settings))->boot();

        (new SpeculativeLoader($settings->all()))->boot();
        $imageOptimizer->boot();
        (new GravatarCache($settings))->boot();
        (new ScriptManager($settings))->boot();
        (new AltTextProvider($settings))->boot();
        (new MediaOffloadProvider($settings))->boot();
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
        add_action('save_post', [$this->cacheManager, 'clearPost']);
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
