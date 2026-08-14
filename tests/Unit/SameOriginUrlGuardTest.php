<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit;

use WPSCache\Infrastructure\Http\SameOriginUrlGuard;
use WPSCache\Tests\Framework\TestCase;

final class SameOriginUrlGuardTest extends TestCase
{
    public function testAllowsOnlyHttpUrlsOnTheConfiguredOrigin(): void
    {
        $guard = new SameOriginUrlGuard('https://Example.test');

        $this->assertTrue($guard->allows('https://example.test/path?next=https://attacker.test'));
        $this->assertTrue($guard->allows('https://example.test:443/explicit-default-port'));
        $this->assertFalse($guard->allows('http://example.test/path'));
        $this->assertFalse($guard->allows('https://example.test:8443/path'));
        $this->assertFalse($guard->allows('https://example.test.attacker.test/path'));
        $this->assertFalse($guard->allows('javascript:alert(1)'));
        $this->assertFalse($guard->allows('/relative-path'));
    }
}
