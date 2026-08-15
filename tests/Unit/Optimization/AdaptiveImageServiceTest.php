<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Optimization;

use WPSCache\Optimization\Media\AdaptiveImageService;
use WPSCache\Tests\Framework\TestCase;

final class AdaptiveImageServiceTest extends TestCase
{
    public function testBuildsDeterministicSignedSameOriginTransformUrls(): void
    {
        $service = new AdaptiveImageService([
            'image_adaptive_delivery' => true,
            'image_adaptive_quality' => 78,
            'cdn_enable' => false,
        ]);
        $first = $service->transformUrl('https://example.test/wp-content/uploads/2026/hero.jpg', 640);
        $second = $service->transformUrl('https://example.test/wp-content/uploads/2026/hero.jpg', 640);

        $this->assertSame($first, $second);
        $this->assertContains('action=wpsc_adaptive_image', $first);
        $this->assertContains('width=640', $first);
        $this->assertContains('quality=78', $first);
        $this->assertContains('signature=', $first);
    }
}
