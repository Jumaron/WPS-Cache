<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Admin;

use WPSCache\Admin\Settings\SettingsController;
use WPSCache\Admin\Settings\SettingsValidator;
use WPSCache\Tests\Framework\TestCase;

final class SettingsControllerTest extends TestCase
{
    public function testBootRegistersItsWordPressBoundaries(): void
    {
        (new SettingsController(new SettingsValidator()))->boot();

        $this->assertTrue(isset(\WPTestState::$hooks['admin_init']));
        $this->assertTrue(isset(\WPTestState::$hooks['admin_post_wpsc_refresh_stats']));
    }
}
