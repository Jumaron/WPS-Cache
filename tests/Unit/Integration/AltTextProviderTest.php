<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Integration;

use WPSCache\Config\Settings;
use WPSCache\Integration\Media\AltTextProvider;
use WPSCache\Tests\Framework\TestCase;

final class AltTextProviderTest extends TestCase
{
    public function testExtractsTextFromRawResponsesApiPayload(): void
    {
        $text = AltTextProvider::extractResponseText([
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'A red bicycle beside a brick wall.',
                ]],
            ]],
        ]);

        $this->assertSame('A red bicycle beside a brick wall.', $text);
    }

    public function testIgnoresNonMessageOutputItems(): void
    {
        $this->assertSame('', AltTextProvider::extractResponseText([
            'output' => [['type' => 'reasoning', 'content' => []]],
        ]));
    }

    public function testCustomProviderWritesOnlyMissingNormalizedAltText(): void
    {
        add_filter('wpsc_generate_image_alt_text', static fn(): string => '  "' . str_repeat('A', 140) . '"  ');
        $provider = new AltTextProvider(new Settings(['image_ai_alt_provider' => true]));
        $provider->generate('/not-required-for-custom-provider.jpg', 0, [], 73);

        $alt = (string) get_post_meta(73, '_wp_attachment_image_alt', true);
        $this->assertSame(125, strlen($alt));
        \WPTestState::$postMeta[73]['_wp_attachment_image_alt'] = 'Existing text';
        $provider->generate('/ignored.jpg', 0, [], 73);
        $this->assertSame('Existing text', get_post_meta(73, '_wp_attachment_image_alt', true));
    }
}
