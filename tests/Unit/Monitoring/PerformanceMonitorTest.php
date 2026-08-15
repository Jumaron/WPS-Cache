<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Monitoring;

use WPSCache\Monitoring\PerformanceMonitor;
use WPSCache\Tests\Framework\TestCase;

final class PerformanceMonitorTest extends TestCase
{
    public function testParsesLighthouseMetricsAndSortsRealOpportunitySavings(): void
    {
        $result = PerformanceMonitor::parsePageSpeedResult([
            'lighthouseResult' => [
                'fetchTime' => '2026-08-15T00:00:00Z',
                'categories' => ['performance' => ['score' => 0.91]],
                'audits' => [
                    'first-contentful-paint' => ['numericValue' => 800],
                    'largest-contentful-paint' => ['numericValue' => 1200],
                    'speed-index' => ['numericValue' => 900],
                    'total-blocking-time' => ['numericValue' => 40],
                    'cumulative-layout-shift' => ['numericValue' => 0.02],
                    'unused-css-rules' => ['title' => 'Reduce unused CSS', 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 320]],
                    'render-blocking-resources' => ['title' => 'Eliminate render blocking', 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 540]],
                ],
            ],
        ], 'desktop');

        $this->assertSame(91, $result['performance_score']);
        $this->assertSame(1200, $result['metrics']['lcp_ms']);
        $this->assertSame('render-blocking-resources', $result['opportunities'][0]['id']);
        $this->assertSame(540, $result['opportunities'][0]['savings_ms']);
    }
}
