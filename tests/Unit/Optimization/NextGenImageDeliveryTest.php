<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Optimization;

use WPSCache\Optimization\Html\NextGenImageDelivery;
use WPSCache\Tests\Framework\TestCase;

final class NextGenImageDeliveryTest extends TestCase
{
    public function testRewritesInlineBackgroundToBrowserNativeImageSet(): void
    {
        $uploads = wp_get_upload_dir();
        $source = $uploads['basedir'] . '/background.jpg';
        $webp = $uploads['basedir'] . '/background.webp';
        file_put_contents($source, 'jpg');
        file_put_contents($webp, 'webp');
        $optimizer = new NextGenImageDelivery([
            'image_generate_webp' => true,
            'image_generate_avif' => false,
        ]);

        $result = $optimizer->process('<div style="background:url(https://example.test/wp-content/uploads/background.jpg) center/cover no-repeat"></div>');

        $this->assertContains('background:image-set(', $result);
        $this->assertContains('background.webp', $result);
        $this->assertContains('image/webp', $result);
        $this->assertContains('center/cover no-repeat', $result);

        $cdnOptimizer = new NextGenImageDelivery([
            'image_generate_webp' => true,
            'image_generate_avif' => false,
            'cdn_enable' => true,
            'cdn_url' => 'https://cdn.example.test',
            'cdn_media_url' => 'https://media.example.test',
        ]);
        $cdnResult = $cdnOptimizer->process('<div style="background-image:url(https://example.test/wp-content/uploads/background.jpg)"></div>');
        $this->assertContains('https://media.example.test/background.webp', $cdnResult);
        $this->assertContains('https://media.example.test/background.jpg', $cdnResult);
        @unlink($source);
        @unlink($webp);
    }
}
