<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Config;

use WPSCache\Admin\Settings\SettingsValidator;
use WPSCache\Config\Settings;
use WPSCache\Infrastructure\WordPress\DropInManager;
use WPSCache\Infrastructure\WordPress\ObjectCacheCompatibility;
use WPSCache\Tests\Framework\TestCase;

final class SettingsValidatorTest extends TestCase
{
    public function testRejectsNonArrayPayloadWithoutThrowing(): void
    {
        $clean = (new SettingsValidator())->sanitizeSettings('invalid');

        $this->assertSame(Settings::defaults(), $clean);
    }

    public function testSanitizesRangesEnumsHostsAndSecrets(): void
    {
        \WPTestState::$options[Settings::OPTION] = array_replace(Settings::defaults(), [
            'redis_password' => 'keep-me',
            'cf_api_token' => 'keep-token',
        ]);

        $clean = (new SettingsValidator())->sanitizeSettings([
            'cache_lifetime' => 5,
            'redis_port' => 99999,
            'redis_host' => ' redis.example.test<script> ',
            'preload_interval' => 'sometimes',
            'redis_password' => '',
            'cf_api_token' => '',
            'excluded_urls' => "/cart\n/account\n",
        ]);

        $this->assertSame(60, $clean['cache_lifetime']);
        $this->assertSame(65535, $clean['redis_port']);
        $this->assertSame('redis.example.test', $clean['redis_host']);
        $this->assertSame('daily', $clean['preload_interval']);
        $this->assertSame('keep-me', $clean['redis_password']);
        $this->assertSame('keep-token', $clean['cf_api_token']);
        $this->assertSame(['/cart', '/account'], $clean['excluded_urls']);
    }

    public function testMemcachedSelectionIsExclusiveAndNewRangesAreBounded(): void
    {
        $clean = (new SettingsValidator())->sanitizeSettings([
            'redis_cache' => '1',
            'memcached_cache' => '1',
            'memcached_host' => ' cache.internal<script> ',
            'memcached_port' => 70000,
            'image_adaptive_quality' => 200,
            'image_adaptive_max_width' => 0,
            'css_profile_retention' => 0,
            'pagespeed_strategy' => 'television',
        ]);

        $this->assertTrue($clean['memcached_cache']);
        $this->assertFalse($clean['redis_cache']);
        $this->assertSame('cache.internal', $clean['memcached_host']);
        $this->assertSame(65535, $clean['memcached_port']);
        $this->assertSame(100, $clean['image_adaptive_quality']);
        $this->assertSame(1, $clean['image_adaptive_max_width']);
        $this->assertSame(1, $clean['css_profile_retention']);
        $this->assertSame('mobile', $clean['pagespeed_strategy']);
    }

    public function testProtectsAndSanitizesExternalServiceCredentials(): void
    {
        \WPTestState::$options[Settings::OPTION] = array_replace(Settings::defaults(), [
            'openai_api_key' => 'sk-existing.secret',
            'media_offload_secret_key' => 'existing/s3+secret',
        ]);

        $clean = (new SettingsValidator())->sanitizeSettings([
            'openai_api_key' => '',
            'openai_vision_model' => '../../not a model',
            'media_offload_secret_key' => '',
            'media_offload_bucket' => 'Valid-Bucket!',
        ]);

        $this->assertSame('sk-existing.secret', $clean['openai_api_key']);
        $this->assertSame('gpt-5.6', $clean['openai_vision_model']);
        $this->assertSame('existing/s3+secret', $clean['media_offload_secret_key']);
        $this->assertSame('', $clean['media_offload_bucket']);
    }

    public function testRejectsAnUnhealthyNewObjectCacheAndPreservesKnownSettingsOnARejectedChange(): void
    {
        $directory = $this->temporaryDirectory('settings-object-cache');
        mkdir($directory . '/templates');
        mkdir($directory . '/content');
        mkdir($directory . '/cache');
        file_put_contents($directory . '/templates/object-cache.php', '<?php // WPS Cache Redis object template');
        file_put_contents($directory . '/templates/object-cache-memcached.php', '<?php // WPS-Cache Memcached Object Cache Backend');
        $dropIns = new DropInManager($directory . '/templates', $directory . '/content');
        $compatibility = new ObjectCacheCompatibility(
            $dropIns,
            $directory . '/cache/object-runtime.php',
            static fn(string $backend, Settings $settings): ?string => 'Backend unavailable.',
        );
        $validator = new SettingsValidator($compatibility, $dropIns);

        $clean = $validator->sanitizeSettings(['redis_cache' => '1']);
        $this->assertFalse($clean['redis_cache']);

        \WPTestState::$options[Settings::OPTION] = array_replace(Settings::defaults(), [
            'redis_cache' => true,
            'redis_host' => 'known-good.internal',
        ]);
        $clean = $validator->sanitizeSettings([
            'redis_cache' => '1',
            'redis_host' => 'bad-change.internal',
        ]);
        $this->assertTrue($clean['redis_cache']);
        $this->assertSame('known-good.internal', $clean['redis_host']);
        $this->removeDirectory($directory);
    }

    public function testRejectsFeatureFlagsWhoseRequiredParentOrConfigurationIsMissing(): void
    {
        $clean = (new SettingsValidator())->sanitizeSettings([
            'css_rendered_profiles' => '0',
            'css_critical_rendered' => '1',
            'css_linked_prune' => '1',
            'cf_enable' => '1',
            'cf_edge_cache' => '1',
            'cdn_enable' => '1',
            'image_ai_alt_provider' => '0',
            'image_ai_alt_openai' => '1',
            'media_offload_provider' => '0',
            'media_offload_delete_local' => '1',
            'media_offload_s3' => '1',
            'cache_logged_in_roles' => ['subscriber'],
        ]);

        foreach (['css_critical_rendered', 'css_linked_prune', 'cf_enable', 'cf_edge_cache', 'cdn_enable', 'image_ai_alt_openai', 'media_offload_delete_local', 'media_offload_s3'] as $key) {
            $this->assertFalse($clean[$key], 'Expected dependency guard to reject ' . $key);
        }
        $this->assertSame([], $clean['cache_logged_in_roles']);
    }
}
