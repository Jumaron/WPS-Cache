<?php

declare(strict_types=1);

namespace WPSCache\Admin;

use WPSCache\Admin\UI\NoticeManager;
use WPSCache\Cache\CacheManager;
use WPSCache\Infrastructure\Http\SameOriginUrlGuard;
use WPSCache\Infrastructure\WordPress\DropInManager;
use WPSCache\Scheduling\PreloadUrlProvider;

final class CacheActionController
{
    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly DropInManager $dropIns,
        private readonly NoticeManager $notices,
        private readonly SameOriginUrlGuard $urlGuard,
        private readonly ?PreloadUrlProvider $preloadUrls = null,
    ) {
    }

    public function boot(): void
    {
        add_action('admin_post_wpsc_clear_cache', [$this, 'clear']);
        add_action('admin_post_wpsc_install_object_cache', [$this, 'installObjectCache']);
        add_action('admin_post_wpsc_remove_object_cache', [$this, 'removeObjectCache']);
        add_action('admin_post_wpsc_purge_url', [$this, 'purgeUrl']);
        add_action('wp_ajax_wpsc_get_preload_urls', [$this, 'getPreloadUrls']);
        add_action('wp_ajax_wpsc_process_preload_url', [$this, 'preloadUrl']);
    }

    public function clear(): void
    {
        $this->authorize('wpsc_clear_cache');
        $success = $this->cacheManager->clearAllCaches();
        $this->notices->add($success ? 'Cache cleared.' : 'Some cache layers could not be cleared.', $success ? 'success' : 'error');
        $this->redirectBack();
    }

    public function installObjectCache(): void
    {
        $this->authorize('wpsc_install_object_cache');
        $destination = WP_CONTENT_DIR . '/object-cache.php';
        if (is_file($destination) && !$this->dropIns->owns('object-cache.php')) {
            $this->notices->add('Another plugin owns object-cache.php. It was not replaced.', 'error');
            $this->redirectBack();
        }

        $success = $this->dropIns->installObjectCache();
        $this->notices->add(
            $success ? 'Object-cache drop-in installed or refreshed.' : 'Object-cache drop-in could not be installed.',
            $success ? 'success' : 'error',
        );
        $this->redirectBack();
    }

    public function removeObjectCache(): void
    {
        $this->authorize('wpsc_remove_object_cache');
        if (is_file(WP_CONTENT_DIR . '/object-cache.php') && !$this->dropIns->owns('object-cache.php')) {
            $this->notices->add('The object-cache drop-in belongs to another plugin.', 'error');
            $this->redirectBack();
        }

        $success = $this->dropIns->removeObjectCache();
        if ($success) {
            wp_cache_flush();
        }
        $this->notices->add($success ? 'Object-cache drop-in removed.' : 'Object-cache drop-in could not be removed.', $success ? 'success' : 'error');
        $this->redirectBack();
    }

    public function purgeUrl(): void
    {
        $this->authorize('wpsc_purge_url');
        $submitted = isset($_POST['url']) && is_string($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if (!$this->urlGuard->allows($submitted)) {
            $this->notices->add('Only URLs on this WordPress site can be purged.', 'error');
            $this->redirectBack();
        }
        $success = $this->cacheManager->clearUrl($submitted);
        $this->notices->add($success ? 'URL cache purged.' : 'The page-cache layer is unavailable.', $success ? 'success' : 'error');
        $this->redirectBack();
    }

    public function getPreloadUrls(): void
    {
        $this->authorizeAjax();
        if ($this->preloadUrls instanceof PreloadUrlProvider) {
            wp_send_json_success($this->preloadUrls->discover(10000));
        }
        $postTypes = ['page', 'post'];
        if (class_exists('WooCommerce')) {
            $postTypes[] = 'product';
        }

        $query = new \WP_Query([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'fields' => 'ids',
        ]);
        $urls = [home_url('/')];
        foreach ($query->posts as $id) {
            $urls[] = get_permalink($id);
        }

        wp_send_json_success(array_values(array_unique($urls)));
    }

    public function preloadUrl(): void
    {
        $this->authorizeAjax();
        $submittedUrl = $_POST['url'] ?? '';
        $url = is_string($submittedUrl) ? esc_url_raw(wp_unslash($submittedUrl)) : '';
        if (!$this->urlGuard->allows($url)) {
            wp_send_json_error('Invalid URL', 400);
        }

        $common = [
            'timeout' => 10,
            'blocking' => true,
            'sslverify' => apply_filters('https_local_ssl_verify', true),
        ];
        $desktop = wp_safe_remote_get($url, $common + [
            'headers' => ['User-Agent' => 'WPS-Cache-Preloader/' . WPSC_VERSION],
        ]);
        $mobile = wp_safe_remote_get($url, $common + [
            'headers' => ['User-Agent' => 'Mozilla/5.0 Mobile WPS-Cache-Preloader/' . WPSC_VERSION],
        ]);
        $stored = get_option('wpsc_settings', []);
        $tablet = null;
        if (is_array($stored) && ($stored['cache_device_mode'] ?? 'mobile') === 'tablet') {
            $tablet = wp_safe_remote_get($url, $common + [
                'headers' => ['User-Agent' => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) WPS-Cache-Preloader/' . WPSC_VERSION],
            ]);
        }
        $desktopCode = is_wp_error($desktop) ? 0 : wp_remote_retrieve_response_code($desktop);
        $mobileCode = is_wp_error($mobile) ? 0 : wp_remote_retrieve_response_code($mobile);
        $tabletCode = $tablet === null || is_wp_error($tablet) ? 0 : wp_remote_retrieve_response_code($tablet);

        if (($desktopCode >= 200 && $desktopCode < 300) || ($mobileCode >= 200 && $mobileCode < 300)) {
            wp_send_json_success("Cached (D:{$desktopCode}, M:{$mobileCode}, T:{$tabletCode})");
        }
        wp_send_json_error("Error D:{$desktopCode} M:{$mobileCode}");
    }

    private function authorize(string $nonce): void
    {
        check_admin_referer($nonce);
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
        }
    }

    private function authorizeAjax(): void
    {
        check_ajax_referer('wpsc_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }
    }

    private function redirectBack(): never
    {
        $fallback = admin_url('admin.php?page=wps-cache');
        wp_safe_redirect(wp_get_referer() ?: $fallback);
        exit;
    }
}
