<?php

declare(strict_types=1);

namespace WPSCache\Tests\Integration;

use WPSCache\Bootstrap\Application;
use WPSCache\Tests\Framework\TestCase;

final class ApplicationBootTest extends TestCase
{
    public function testCompositionRootBuildsAndRegistersRuntimeHooks(): void
    {
        $application = Application::boot();

        $this->assertSame($application, Application::boot());
        $this->assertTrue(isset(\WPTestState::$hooks['plugins_loaded']));
        $this->assertTrue(isset(\WPTestState::$hooks['updated_option']));
        $this->assertTrue(isset(\WPTestState::$hooks['template_redirect']));
        $this->assertTrue(isset(\WPTestState::$hooks['wpsc_scheduled_preload']));
        $this->assertTrue(isset(\WPTestState::$hooks['wpsc_cache_cleanup']));
    }
}
