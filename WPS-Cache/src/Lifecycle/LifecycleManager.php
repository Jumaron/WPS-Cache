<?php

declare(strict_types=1);

namespace WPSCache\Lifecycle;

use WPSCache\Cache\CacheManager;
use WPSCache\Config\Settings;
use WPSCache\Config\SettingsRepository;
use WPSCache\Infrastructure\Filesystem\CacheDirectory;
use WPSCache\Infrastructure\Server\ApacheConfigManager;
use WPSCache\Infrastructure\WordPress\DropInManager;
use WPSCache\Infrastructure\WordPress\EarlyCacheConfig;
use WPSCache\Infrastructure\WordPress\WpConfigManager;
use WPSCache\Infrastructure\WordPress\ObjectCacheConfig;
use WPSCache\Scheduling\MaintenanceScheduler;
use WPSCache\Scheduling\PreloadScheduler;

final class LifecycleManager
{
    public const VERSION_OPTION = 'wpsc_version';

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly CacheDirectory $cacheDirectory,
        private readonly WpConfigManager $wpConfig,
        private readonly DropInManager $dropIns,
        private readonly EarlyCacheConfig $earlyCacheConfig,
        private readonly ApacheConfigManager $apache,
        private readonly CacheManager $cacheManager,
        private readonly PreloadScheduler $preloadScheduler,
        private readonly MaintenanceScheduler $maintenanceScheduler,
        private readonly ?ObjectCacheConfig $objectCacheConfig = null,
    ) {
    }

    public function activate(): void
    {
        $this->settingsRepository->installDefaults();
        $settings = $this->settingsRepository->load();
        $this->applyRuntimeConfiguration($settings);
        if ($this->dropIns->owns('object-cache.php') && !$this->dropIns->installObjectCache($this->objectBackend($settings))) {
            error_log('[WPS-Cache] Could not refresh the owned object-cache.php drop-in.');
        }

        $this->maintenanceScheduler->scheduleCacheCleanup();
        $this->maintenanceScheduler->updateDatabaseSchedule($settings->all());
        $this->preloadScheduler->updateSchedule($settings->all());
        update_option(self::VERSION_OPTION, WPSC_VERSION);
        flush_rewrite_rules();
    }

    public function maybeUpgrade(): void
    {
        if (get_option(self::VERSION_OPTION, '') === WPSC_VERSION) {
            return;
        }

        $this->settingsRepository->installDefaults();
        $settings = $this->settingsRepository->load();
        $this->applyRuntimeConfiguration($settings);

        if ($this->dropIns->owns('object-cache.php')) {
            $this->dropIns->installObjectCache($this->objectBackend($settings));
        }

        $this->cacheManager->clearAllCaches();
        update_option(self::VERSION_OPTION, WPSC_VERSION);
    }

    public function deactivate(): void
    {
        if ($this->dropIns->owns('advanced-cache.php')) {
            $this->wpConfig->disableCache();
        }
        $this->cacheManager->clearAllCaches();
        $this->dropIns->removeAllOwned();
        $this->objectCacheConfig?->remove();
        $this->preloadScheduler->unschedule();
        $this->maintenanceScheduler->unschedule();
        wp_clear_scheduled_hook('wpsc_uptime_check');
        wp_clear_scheduled_hook('wpsc_image_background_optimize');
        $this->apache->removeConfiguration();
        flush_rewrite_rules();
    }

    /** @param array<string, mixed> $values */
    public function settingsUpdated(array $values): void
    {
        $settings = new Settings($values);
        $this->applyRuntimeConfiguration($settings);
        $this->preloadScheduler->updateSchedule($settings->all());
        $this->maintenanceScheduler->updateDatabaseSchedule($settings->all());
    }

    private function applyRuntimeConfiguration(Settings $settings): void
    {
        if (!$this->cacheDirectory->prepare()) {
            error_log('[WPS-Cache] Failed to prepare the cache directory.');
        }
        if (!$this->earlyCacheConfig->write($settings)) {
            error_log('[WPS-Cache] Failed to write the early-cache runtime configuration.');
        }
        if ($this->objectCacheConfig !== null) {
            if (($settings->enabled('redis_cache') || $settings->enabled('memcached_cache')) && !$this->objectCacheConfig->write($settings)) {
                error_log('[WPS-Cache] Failed to write object-cache runtime configuration.');
            } elseif (!$settings->enabled('redis_cache') && !$settings->enabled('memcached_cache')) {
                $this->objectCacheConfig->remove();
            }
        }
        if ($settings->enabled('redis_cache') || $settings->enabled('memcached_cache')) {
            if (!is_file(WP_CONTENT_DIR . '/object-cache.php') || $this->dropIns->owns('object-cache.php')) {
                $this->dropIns->installObjectCache($this->objectBackend($settings));
            }
        } elseif ($this->dropIns->owns('object-cache.php')) {
            $this->dropIns->removeObjectCache();
        }

        if ($settings->enabled('html_cache')) {
            $this->apache->applyConfiguration();
            if (!$this->dropIns->installAdvancedCache()) {
                error_log('[WPS-Cache] Could not install advanced-cache.php; another drop-in may own it.');
            } elseif (!$this->wpConfig->enableCache()) {
                error_log('[WPS-Cache] Could not enable WP_CACHE automatically.');
            }
            return;
        }

        $this->apache->removeConfiguration();
        if ($this->dropIns->owns('advanced-cache.php') && $this->dropIns->removeAdvancedCache()) {
            $this->wpConfig->disableCache();
        }
    }

    private function objectBackend(Settings $settings): string
    {
        return $settings->enabled('memcached_cache') ? 'memcached' : 'redis';
    }
}
