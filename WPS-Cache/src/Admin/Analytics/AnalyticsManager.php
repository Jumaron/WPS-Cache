<?php

declare(strict_types=1);

namespace WPSCache\Admin\Analytics;

use WPSCache\Cache\CacheManager;

/**
 * Controller for the Analytics UI.
 */
class AnalyticsManager
{
    private MetricsCollector $collector;

    public function __construct(CacheManager $cacheManager)
    {
        $this->collector = new MetricsCollector($cacheManager);
    }

    /**
     * Renders the Analytics Tab content.
     */
    public function render(): void
    {
        $stats = $this->collector->getStats();
        $redis = $stats['redis'];
        $html = $stats['html'];

        ?>
        <div class="wpsc-stats-grid">
            <!-- Redis Card -->
            <div class="wpsc-stat-card">
                <h3>Redis Object Cache</h3>
                <?php if (!empty($redis['enabled'])): ?>
                    <?php if (!empty($redis['connected'])): ?>
                        <div class="wpsc-stat-value"><?php echo esc_html($redis['hit_ratio']); ?>%</div>
                        <div style="color: var(--wpsc-text-muted); font-size: 0.9em; margin-top: 5px;">Hit Ratio</div>
                        <hr style="border: 0; border-top: 1px solid #eee; margin: 15px 0;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.85em;">
                            <span>Mem: <strong><?php echo esc_html($redis['memory_used']); ?></strong></span>
                            <span>Uptime: <strong><?php echo esc_html($redis['uptime']); ?>d</strong></span>
                        </div>
                    <?php else: ?>
                        <div style="color: var(--wpsc-danger); font-weight: bold;">Connection Failed</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="color: var(--wpsc-text-muted);">Disabled</div>
                <?php endif; ?>
            </div>

            <!-- HTML Cache Card -->
            <div class="wpsc-stat-card">
                <h3>Page Cache (Disk)</h3>
                <?php if ($html['enabled']): ?>
                    <div class="wpsc-stat-value"><?php echo esc_html($html['files']); ?></div>
                    <div style="color: var(--wpsc-text-muted); font-size: 0.9em; margin-top: 5px;">Cached Pages</div>
                    <hr style="border: 0; border-top: 1px solid #eee; margin: 15px 0;">
                    <div style="font-size: 0.85em;">
                        Total Size: <strong><?php echo esc_html($html['size']); ?></strong>
                    </div>
                <?php else: ?>
                    <div style="color: var(--wpsc-text-muted);">Disabled</div>
                <?php endif; ?>
            </div>

            <!-- System Card -->
            <div class="wpsc-stat-card">
                <h3>Server Health</h3>
                <div style="font-size: 0.9rem; line-height: 1.8;">
                    <div>PHP Version: <strong><?php echo esc_html($stats['system']['php_version']); ?></strong></div>
                    <div>Memory Limit: <strong><?php echo esc_html($stats['system']['memory_limit']); ?></strong></div>
                    <div>Max Exec: <strong><?php echo esc_html($stats['system']['max_exec']); ?>s</strong></div>
                    <div>Web Server: <strong><?php echo esc_html($stats['system']['server']); ?></strong></div>
                </div>
            </div>
        </div>

        <div style="text-align: right; margin-top: 1rem;">
            <form method="post">
                <?php wp_nonce_field('wpsc_refresh_stats'); ?>
                <input type="hidden" name="wpsc_action" value="refresh_stats">
                <button type="submit" class="button wpsc-btn-secondary">Refresh Statistics</button>
            </form>
        </div>
        <?php

        // Handle manual refresh
        if (isset($_POST['wpsc_action']) && $_POST['wpsc_action'] === 'refresh_stats') {
            check_admin_referer('wpsc_refresh_stats');
            delete_transient('wpsc_stats_cache');
            echo "<script>window.location.reload();</script>";
        }
    }
}
