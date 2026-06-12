<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Schedule\ScheduleCalculator;
use SuperSheepCopy\Schedule\ScheduleSettings;

final class ScheduleCalculatorTest extends TestCase
{
    public function testCalculatesNextDailyRun(): void
    {
        $calculator = new ScheduleCalculator(new \DateTimeZone('UTC'));
        $settings = ScheduleSettings::fromArray(array('frequency' => 'daily', 'time_of_day' => '02:00'));

        self::assertSame(
            strtotime('2026-06-13 02:00:00 UTC'),
            $calculator->nextRunTimestamp($settings, strtotime('2026-06-12 03:00:00 UTC'))
        );
    }

    public function testCalculatesNextWeeklyRunOnMonday(): void
    {
        $calculator = new ScheduleCalculator(new \DateTimeZone('UTC'));
        $settings = ScheduleSettings::fromArray(array('frequency' => 'weekly', 'time_of_day' => '03:30'));

        self::assertSame(
            strtotime('2026-06-15 03:30:00 UTC'),
            $calculator->nextRunTimestamp($settings, strtotime('2026-06-12 10:00:00 UTC'))
        );
    }

    public function testCalculatesNextMonthlyRunOnFirstDay(): void
    {
        $calculator = new ScheduleCalculator(new \DateTimeZone('UTC'));
        $settings = ScheduleSettings::fromArray(array('frequency' => 'monthly', 'time_of_day' => '04:15'));

        self::assertSame(
            strtotime('2026-07-01 04:15:00 UTC'),
            $calculator->nextRunTimestamp($settings, strtotime('2026-06-12 10:00:00 UTC'))
        );
    }
}
