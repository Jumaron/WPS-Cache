<?php

declare(strict_types=1);

namespace WPSCache\Admin\Tools;

use WPSCache\Cache\CacheManager;

/**
 * Manages cache tools and maintenance operations
 */
class ToolsManager
{
    private CacheManager $cache_manager;
    private CacheTools $cache_tools;
    private DiagnosticTools $diagnostic_tools;
    private ImportExportTools $import_export_tools;

    public function __construct(CacheManager $cache_manager)
    {
        $this->cache_manager = $cache_manager;
        $this->initializeTools();
        $this->initializeHooks();
    }

    /**
     * Initializes tool components
     */
    private function initializeTools(): void
    {
        $this->cache_tools = new CacheTools($this->cache_manager);
        $this->diagnostic_tools = new DiagnosticTools();
        $this->import_export_tools = new ImportExportTools();
    }

    /**
     * Sets up WordPress hooks
     */
    private function initializeHooks(): void
    {
        // Cache maintenance schedule
        if (!wp_next_scheduled('wpsc_cache_maintenance')) {
            wp_schedule_event(time(), 'daily', 'wpsc_cache_maintenance');
        }
        add_action('wpsc_cache_maintenance', [$this->cache_tools, 'performMaintenance']);
    }

    /**
     * Renders the tools tab content
     */
    public function renderTab(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
?>
        <div class="wpsc-tools-view">
            <!-- Cache Management (Grid Layout) -->
            <?php $this->cache_tools->renderCacheManagement(); ?>

            <!-- Preloading (Card Layout) -->
            <div class="wpsc-card">
                <div class="wpsc-card-header">
                    <h2><?php esc_html_e('Cache Preloading', 'wps-cache'); ?></h2>
                </div>
                <div class="wpsc-card-body">
                    <?php $this->cache_tools->renderPreloadingTools(); ?>
                </div>
            </div>

            <!-- Import/Export (Grid Layout) -->
            <?php $this->import_export_tools->renderImportExport(); ?>

            <!-- Diagnostics (Card Layout) -->
            <div class="wpsc-card">
                <div class="wpsc-card-header">
                    <h2><?php esc_html_e('System Diagnostics', 'wps-cache'); ?></h2>
                </div>
                <div class="wpsc-card-body">
                    <?php $this->diagnostic_tools->renderDiagnostics(); ?>
                </div>
            </div>
        </div>
<?php
    }

    /**
     * Handles cache clear request
     */
    public function handleCacheClear(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'wps-cache'));
        }

        check_admin_referer('wpsc_clear_cache');
        $this->cache_tools->clearAllCaches();

        wp_redirect(add_query_arg(
            [
                'page'         => 'wps-cache',
                'tab'          => 'tools',
                'cache_cleared' => 'success',
                '_wpnonce'     => wp_create_nonce('wpsc_cache_cleared')
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Handles object cache installation
     */
    public function handleInstallObjectCache(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'wps-cache'));
        }

        check_admin_referer('wpsc_install_object_cache');
        $result = $this->cache_tools->installObjectCache();

        wp_redirect(add_query_arg(
            [
                'page'                   => 'wps-cache',
                'tab'                    => 'tools',
                'object_cache_installed' => $result['status'],
                '_wpnonce'               => wp_create_nonce('wpsc_dropin_installed')
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Handles object cache removal
     */
    public function handleRemoveObjectCache(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'wps-cache'));
        }

        check_admin_referer('wpsc_remove_object_cache');
        $result = $this->cache_tools->removeObjectCache();

        wp_redirect(add_query_arg(
            [
                'page'                 => 'wps-cache',
                'tab'                  => 'tools',
                'object_cache_removed' => $result['status'],
                '_wpnonce'             => wp_create_nonce('wpsc_dropin_removed')
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Handles settings export
     */
    public function handleExportSettings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'wps-cache'));
        }

        check_admin_referer('wpsc_export_settings');
        $this->import_export_tools->exportSettings();
    }

    /**
     * Handles settings import
     */
    public function handleImportSettings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'wps-cache'));
        }

        check_admin_referer('wpsc_import_settings');
        $result = $this->import_export_tools->importSettings();

        wp_redirect(add_query_arg(
            $result['status'] === 'success'
                ? ['settings_imported' => 'success']
                : ['import_error' => $result['error']],
            wp_get_referer()
        ));
        exit;
    }

    /**
     * Handles AJAX cache preload request
     */
    public function handleAjaxPreloadCache(): void
    {
        check_ajax_referer('wpsc_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        try {
            $result = $this->cache_tools->preloadCache();
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'url'     => $result['current_url'] ?? ''
            ]);
        }
    }

    /**
     * Gets diagnostic information
     */
    public function getDiagnosticInfo(): array
    {
        return $this->diagnostic_tools->getDiagnosticInfo();
    }
}
