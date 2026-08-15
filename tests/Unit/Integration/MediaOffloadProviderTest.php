<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Integration;

use WPSCache\Config\Settings;
use WPSCache\Integration\Media\MediaOffloadProvider;
use WPSCache\Tests\Framework\TestCase;

final class MediaOffloadProviderTest extends TestCase
{
    public function testBuiltInS3AdapterSignsUploadsAndRewritesUrls(): void
    {
        $uploads = wp_get_upload_dir();
        $file = $uploads['basedir'] . '/2026/hero.jpg';
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, 'test-image');
        \WPTestState::$attachmentFiles[42] = $file;
        $provider = new MediaOffloadProvider(new Settings([
            'media_offload_provider' => true,
            'media_offload_s3' => true,
            'media_offload_endpoint' => 'https://objects.example.test',
            'media_offload_region' => 'eu-central-1',
            'media_offload_bucket' => 'wps-media',
            'media_offload_access_key' => 'access-key',
            'media_offload_secret_key' => 'secret-key',
            'media_offload_public_url' => 'https://media.example.test',
        ]));

        $provider->offloadAttachment([], 42);

        $this->assertSame(1, count(\WPTestState::$remoteRequests));
        $request = \WPTestState::$remoteRequests[0];
        $this->assertContains('/wps-media/2026/hero.jpg', $request['url']);
        $this->assertContains('AWS4-HMAC-SHA256 Credential=access-key/', (string) $request['args']['headers']['Authorization']);
        $rewritten = $provider->attachmentUrl('https://example.test/wp-content/uploads/2026/hero.jpg', 42);
        $this->assertSame('https://media.example.test/2026/hero.jpg', $rewritten);

        @unlink($file);
        @rmdir(dirname($file));
    }
}
