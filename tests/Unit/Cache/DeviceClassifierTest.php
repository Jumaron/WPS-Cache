<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Cache;

use WPSCache\Cache\Page\DeviceClassifier;
use WPSCache\Tests\Framework\TestCase;

final class DeviceClassifierTest extends TestCase
{
    public function testCreatesConfiguredDeviceVariants(): void
    {
        $this->assertSame('', DeviceClassifier::suffix('Mozilla/5.0 (iPhone) Mobile', 'shared'));
        $this->assertSame('-mobile', DeviceClassifier::suffix('Mozilla/5.0 (iPhone) Mobile', 'mobile'));
        $this->assertSame('-tablet', DeviceClassifier::suffix('Mozilla/5.0 (iPad)', 'tablet'));
        $this->assertSame('', DeviceClassifier::suffix('Mozilla/5.0 (Windows NT 10.0)', 'tablet'));
    }
}
