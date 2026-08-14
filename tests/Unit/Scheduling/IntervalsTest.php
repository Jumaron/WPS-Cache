<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Scheduling;

use WPSCache\Scheduling\Intervals;
use WPSCache\Tests\Framework\TestCase;

final class IntervalsTest extends TestCase
{
    public function testAddsWeeklyAndMonthlyIntervalsWithoutOverwritingExistingOnes(): void
    {
        $intervals = new Intervals();
        $result = $intervals->addIntervals([
            'weekly' => ['interval' => 123, 'display' => 'Existing'],
        ]);

        $this->assertSame(123, $result['weekly']['interval']);
        $this->assertSame(30 * DAY_IN_SECONDS, $result['monthly']['interval']);
    }
}
