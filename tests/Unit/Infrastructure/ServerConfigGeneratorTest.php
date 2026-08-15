<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Infrastructure;

use WPSCache\Config\Settings;
use WPSCache\Infrastructure\Server\ServerConfigGenerator;
use WPSCache\Tests\Framework\TestCase;

final class ServerConfigGeneratorTest extends TestCase
{
    public function testUsesTtlAndCustomCookiesInNginxRecipe(): void
    {
        $recipe = (new ServerConfigGenerator())->nginx(new Settings([
            'cache_lifetime' => 7200,
            'cache_bypass_cookies' => ['membership_session'],
        ]));

        $this->assertContains('inactive=7200s', $recipe);
        $this->assertContains('membership_session', $recipe);
        $this->assertContains('expires 1y', $recipe);
    }
}
