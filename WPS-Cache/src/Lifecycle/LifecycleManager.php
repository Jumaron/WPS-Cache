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
use WPSCache\Infrastructure\WordPress\ObjectCacheCompatibility;
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
        private readonly ?ObjectCacheCompatibility $objectCacheCompatibility = null,
    ) {
    }

    public function activate(): void
    {
        $this->settingsRepository->installDefaults();
        $settings = $this->settingsRepository->load();
        $this->applyRuntimeConfiguration($settings, true);
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
        $this->applyRuntimeConfiguration($settings, true);

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

    private function applyRuntimeConfiguration(Settings $settings, bool $refreshDropIns = false): void
    {
        $cacheDirectoryReady = $this->cacheDirectory->prepare();
        if (!$cacheDirectoryReady) {
            error_log('[WPS-Cache] Failed to prepare the cache directory.');
        }
        $earlyConfigurationReady = $cacheDirectoryReady && $this->earlyCacheConfig->write($settings);
        if (!$earlyConfigurationReady) {
            error_log('[WPS-Cache] Failed to write the early-cache runtime configuration.');
        }
        if ($settings->enabled('redis_cache') || $settings->enabled('memcached_cache')) {
            if ($this->objectCacheConfig === null || $this->objectCacheCompatibility === null) {
                error_log('[WPS-Cache] Object-cache preflight is unavailable; the drop-in was not changed.');
            } else {
                $backend = $settings->enabled('memcached_cache') ? 'memcached' : 'redis';
                $dropInMatches = $this->dropIns->installedObjectCacheBackend() === $backend;
                $configurationMatches = $this->objectCacheConfig->matches($settings);
                if ($refreshDropIns || !$dropInMatches || !$configurationMatches) {
                    $check = $this->objectCacheCompatibility->inspect($settings, true);
                    if (!$check->compatible() || $check->backend === null) {
                        error_log('[WPS-Cache] Object-cache configuration rejected: ' . $check->message());
                    } elseif (!$this->objectCacheConfig->write($settings)) {
                        error_log('[WPS-Cache] Failed to write verified object-cache runtime configuration.');
                    } elseif (!$this->dropIns->installObjectCache($check->backend)) {
                        error_log('[WPS-Cache] Could not install the verified ' . $check->backend . ' object-cache.php drop-in.');
                    }
                }
            }
        } elseif ($this->dropIns->owns('object-cache.php')) {
            $this->dropIns->removeObjectCache();
            $this->objectCacheConfig?->remove();
        } else {
            $this->objectCacheConfig?->remove();
        }

        if ($settings->enabled('html_cache')) {
            $this->apache->applyConfiguration();
            $dropInIssue = $this->dropIns->advancedCacheInstallationIssue();
            if (!$cacheDirectoryReady || !$earlyConfigurationReady) {
                error_log('[WPS-Cache] Page-cache drop-in was not changed because its runtime directory is unavailable.');
            } elseif ($dropInIssue !== null) {
                error_log('[WPS-Cache] ' . $dropInIssue);
            } elseif (!$this->wpConfig->isWritable()) {
                error_log('[WPS-Cache] Page-cache drop-in was not changed because wp-config.php is not writable.');
            } elseif (!$this->dropIns->installAdvancedCache()) {
                error_log('[WPS-Cache] Could not install advanced-cache.php after its preflight passed.');
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

}
