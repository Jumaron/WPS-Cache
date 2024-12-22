<?php
declare(strict_types=1);

namespace WPSCache\Admin\Analytics;

/**
 * Renders charts and visualizations for cache analytics
 */
class ChartRenderer {
    /**
     * Chart color configuration
     */
    private const CHART_COLORS = [
        'hits' => '#28a745',       // Green
        'misses' => '#dc3545',     // Red
        'memory' => '#17a2b8',     // Blue
        'operations' => '#ffc107', // Yellow
        'background' => '#f8f9fa'  // Light gray
    ];

    /**
     * Renders the cache performance chart
     */
    public function renderCachePerformanceChart(): void {
        ?>
        <div class="wpsc-chart-container">
            <canvas id="cache-performance-chart"></canvas>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const hexToRGBA = (hex, alpha = 1) => {
                let r = parseInt(hex.slice(1, 3), 16),
                    g = parseInt(hex.slice(3, 5), 16),
                    b = parseInt(hex.slice(5, 7), 16);
                return `rgba(${r}, ${g}, ${b}, ${alpha})`;
            };

            const ctx = document.getElementById('cache-performance-chart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [], // Populated via AJAX
                    datasets: [
                        {
                            label: '<?php echo esc_js(__('Hit Ratio (%)', 'wps-cache')); ?>',
                            borderColor: '<?php echo self::CHART_COLORS['hits']; ?>',
                            backgroundColor: hexToRGBA('<?php echo self::CHART_COLORS['hits']; ?>', 0.1),
                            data: [],
                            fill: true
                        },
                        {
                            label: '<?php echo esc_js(__('Miss Rate (%)', 'wps-cache')); ?>',
                            borderColor: '<?php echo self::CHART_COLORS['misses']; ?>',
                            backgroundColor: hexToRGBA('<?php echo self::CHART_COLORS['misses']; ?>', 0.1),
                            data: [],
                            fill: true
                        }
                    ]
                },
                options: <?php echo $this->getPerformanceChartOptions(); ?>
            });
        });
        </script>
        <?php
    }

    /**
     * Renders the memory usage chart
     */
    public function renderMemoryUsageChart(): void {
        ?>
        <div class="wpsc-chart-container">
            <canvas id="memory-usage-chart"></canvas>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const hexToRGBA = (hex, alpha = 1) => {
                let r = parseInt(hex.slice(1, 3), 16),
                    g = parseInt(hex.slice(3, 5), 16),
                    b = parseInt(hex.slice(5, 7), 16);
                return `rgba(${r}, ${g}, ${b}, ${alpha})`;
            };

            const ctx = document.getElementById('memory-usage-chart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [], // Populated via AJAX
                    datasets: [
                        {
                            label: '<?php echo esc_js(__('Memory Usage (MB)', 'wps-cache')); ?>',
                            borderColor: '<?php echo self::CHART_COLORS['memory']; ?>',
                            backgroundColor: hexToRGBA('<?php echo self::CHART_COLORS['memory']; ?>', 0.1),
                            data: [],
                            fill: true
                        }
                    ]
                },
                options: <?php echo $this->getMemoryChartOptions(); ?>
            });
        });
        </script>
        <?php
    }

    /**
     * Renders the operations chart
     */
    public function renderOperationsChart(): void {
        ?>
        <div class="wpsc-chart-container">
            <canvas id="operations-chart"></canvas>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('operations-chart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: [], // Populated via AJAX
                    datasets: [
                        {
                            label: '<?php echo esc_js(__('Cache Operations', 'wps-cache')); ?>',
                            backgroundColor: '<?php echo self::CHART_COLORS['operations']; ?>',
                            data: [],
                            barThickness: 'flex',
                            maxBarThickness: 30
                        }
                    ]
                },
                options: <?php echo $this->getOperationsChartOptions(); ?>
            });
        });
        </script>
        <?php
    }

    /**
     * Gets performance chart options
     */
    private function getPerformanceChartOptions(): string {
        return json_encode([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'max' => 100,
                    'title' => [
                        'display' => true,
                        'text' => __('Percentage', 'wps-cache')
                    ]
                ],
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => __('Time', 'wps-cache')
                    ]
                ]
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'top'
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false
                ]
            ],
            'interaction' => [
                'mode' => 'nearest',
                'axis' => 'x',
                'intersect' => false
            ]
        ]);
    }

    /**
     * Gets memory chart options
     */
    private function getMemoryChartOptions(): string {
        return json_encode([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'title' => [
                        'display' => true,
                        'text' => __('Memory (MB)', 'wps-cache')
                    ]
                ],
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => __('Time', 'wps-cache')
                    ]
                ]
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'top'
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                    'callbacks' => [
                        'label' => 'function(context) {
                            return context.dataset.label + ": " + 
                                context.parsed.y.toFixed(2) + " MB";
                        }'
                    ]
                ]
            ],
            'interaction' => [
                'mode' => 'nearest',
                'axis' => 'x',
                'intersect' => false
            ]
        ]);
    }

    /**
     * Gets operations chart options
     */
    private function getOperationsChartOptions(): string {
        return json_encode([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'title' => [
                        'display' => true,
                        'text' => __('Number of Operations', 'wps-cache')
                    ]
                ],
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => __('Time Period', 'wps-cache')
                    ]
                ]
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'top'
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false
                ]
            ]
        ]);
    }

    /**
     * Converts hex color to RGBA
     */
    private function hexToRGBA(string $hex, float $alpha = 1): string {
        // Remove # if present
        $hex = ltrim($hex, '#');
        
        // Convert shorthand to full form
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        
        // Convert hex to RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        return "rgba($r, $g, $b, $alpha)";
    }

    /**
     * Formats timestamp for chart labels
     */
    public function formatChartTimestamp(int $timestamp): string {
        return wp_date(
            get_option('date_format') . ' ' . get_option('time_format'), 
            $timestamp
        );
    }

    /**
     * Updates chart data via AJAX
     */
    public function updateChartData(array $data): array {
        $formatted = [];
        
        foreach ($data as $timestamp => $metrics) {
            $formatted['labels'][] = $this->formatChartTimestamp($timestamp);
            $formatted['performance'][] = [
                'hit_ratio' => $metrics['hit_ratio'] ?? 0,
                'miss_rate' => 100 - ($metrics['hit_ratio'] ?? 0)
            ];
            $formatted['memory'][] = [
                'used' => ($metrics['memory_used'] ?? 0) / 1024 / 1024 // Convert to MB
            ];
            $formatted['operations'][] = $metrics['total_ops'] ?? 0;
        }

        return $formatted;
    }

    /**
     * Gets chart color palette
     */
    public function getChartColors(): array {
        return self::CHART_COLORS;
    }

    /**
     * Gets chart background style
     */
    public function getChartBackground(): string {
        return self::CHART_COLORS['background'];
    }
}