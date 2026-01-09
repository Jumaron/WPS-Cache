<?php

declare(strict_types=1);

namespace WPSCache\Admin\Analytics;

use WPSCache\Cache\CacheManager;

class AnalyticsManager
{
    private MetricsCollector $collector;

    public function __construct(CacheManager $cacheManager)
    {
        $this->collector = new MetricsCollector($cacheManager);
    }

    public function render(): void
    {
        $stats = $this->collector->getStats();
        $redis = $stats["redis"];
        $html = $stats["html"];
        ?>

        <!-- Wrap in Section for Padding -->
        <section class="wpsc-section">
            <div class="wpsc-section-header">
                <h3 class="wpsc-section-title">Performance Metrics</h3>
                <p class="wpsc-section-desc">Real-time statistics from your caching engines.</p>
            </div>

            <div class="wpsc-section-body">
                <div class="wpsc-stats-grid">

                    <!-- 1. Redis Card -->
                    <div class="wpsc-stat-card">
                        <div>
                            <div class="wpsc-stat-header">
                                <span class="dashicons dashicons-database"></span> Redis Object Cache
                            </div>
                            <?php if (!empty($redis["enabled"])): ?>
                                <?php if (!empty($redis["connected"])): ?>
                                    <div class="wpsc-stat-big-number"><?php echo esc_html(
                                        $redis["hit_ratio"],
                                    ); ?>%</div>
                                    <div style="color: var(--wpsc-text-muted);">Hit Ratio</div>
                                <?php else: ?>
                                    <div class="wpsc-status-pill error">Connection Failed</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="wpsc-status-pill warning">Disabled</div>
                            <?php endif; ?>
                        </div>

                        <?php if (
                            !empty($redis["enabled"]) &&
                            !empty($redis["connected"])
                        ): ?>
                        <div class="wpsc-stat-detail-row">
                            <span>Memory: <strong><?php echo esc_html(
                                $redis["memory_used"],
                            ); ?></strong></span>
                            <span>Uptime: <strong><?php echo esc_html(
                                $redis["uptime"],
                            ); ?>d</strong></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 2. HTML Cache Card -->
                    <div class="wpsc-stat-card">
                        <div>
                            <div class="wpsc-stat-header">
                                <span class="dashicons dashicons-html"></span> Page Cache
                            </div>
                            <?php if ($html["enabled"]): ?>
                                <div class="wpsc-stat-big-number"><?php echo esc_html(
                                    $html["files"],
                                ); ?></div>
                                <div style="color: var(--wpsc-text-muted);">Cached Pages</div>
                            <?php else: ?>
                                <div class="wpsc-status-pill warning">Disabled</div>
                            <?php endif; ?>
                        </div>

                        <?php if ($html["enabled"]): ?>
                        <div class="wpsc-stat-detail-row">
                            <span>Disk Usage</span>
                            <strong><?php echo esc_html(
                                $html["size"],
                            ); ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 3. Server Health Card -->
                    <div class="wpsc-stat-card">
                        <div class="wpsc-stat-header">
                            <span class="dashicons dashicons-desktop"></span> Server Health
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.9rem;">
                            <div style="display:flex; justify-content:space-between;">
                                <span style="color:var(--wpsc-text-muted)">PHP Version</span>
                                <strong><?php echo esc_html(
                                    $stats["system"]["php_version"],
                                ); ?></strong>
                            </div>
                            <div style="display:flex; justify-content:space-between;">
                                <span style="color:var(--wpsc-text-muted)">Memory Limit</span>
                                <strong><?php echo esc_html(
                                    $stats["system"]["memory_limit"],
                                ); ?></strong>
                            </div>
                            <div style="display:flex; justify-content:space-between;">
                                <span style="color:var(--wpsc-text-muted)">Max Exec</span>
                                <strong><?php echo esc_html(
                                    $stats["system"]["max_exec"],
                                ); ?>s</strong>
                            </div>
                            <div style="display:flex; justify-content:space-between;">
                                <span style="color:var(--wpsc-text-muted)">Web Server</span>
                                <strong><?php echo esc_html(
                                    $stats["system"]["server"],
                                ); ?></strong>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Refresh Button -->
                <div style="margin-top: 2rem; display:flex; justify-content:flex-end;">
                    <form method="post" class="wpsc-form">
                        <?php wp_nonce_field("wpsc_refresh_stats"); ?>
                        <input type="hidden" name="wpsc_action" value="refresh_stats">
                        <button type="submit" class="wpsc-btn-secondary" data-loading-text="Refreshing...">
                            <span class="dashicons dashicons-update"></span> Refresh Data
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <?php if (
            isset($_POST["wpsc_action"]) &&
            $_POST["wpsc_action"] === "refresh_stats"
        ) {
            check_admin_referer("wpsc_refresh_stats");
            if (!current_user_can("manage_options")) {
                wp_die("Unauthorized");
            }
            delete_transient("wpsc_stats_cache");
            echo "<script>window.location.reload();</script>";
        }
    }
}
