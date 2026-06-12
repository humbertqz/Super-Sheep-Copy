<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Schedule\ScheduleSettings;
use SuperSheepCopy\Schedule\ScheduleSettingsRepository;

final class ScheduleSettingsTest extends TestCase
{
    public function testDefaultsAreDisabledDailyAtTwoAm(): void
    {
        $settings = ScheduleSettings::defaults();

        self::assertFalse($settings->enabled());
        self::assertSame('daily', $settings->frequency());
        self::assertSame('02:00', $settings->timeOfDay());
        self::assertSame('', $settings->lastStatus());
    }

    public function testSanitizesSubmittedValues(): void
    {
        $settings = ScheduleSettings::fromArray(array(
            'enabled' => '1',
            'frequency' => 'yearly',
            'time_of_day' => '25:99',
            'last_status' => 'completed',
            'last_message' => '<b>done</b>',
            'last_run_at' => '2026-06-12T08:00:00+00:00',
        ));

        self::assertTrue($settings->enabled());
        self::assertSame('daily', $settings->frequency());
        self::assertSame('02:00', $settings->timeOfDay());
        self::assertSame('completed', $settings->lastStatus());
        self::assertSame('done', $settings->lastMessage());
        self::assertSame('2026-06-12T08:00:00+00:00', $settings->lastRunAt());
    }

    public function testRepositoryPersistsSettings(): void
    {
        $repository = new ScheduleSettingsRepository();
        $repository->save(ScheduleSettings::fromArray(array(
            'enabled' => true,
            'frequency' => 'weekly',
            'time_of_day' => '03:30',
        )));

        self::assertSame(array(
            'enabled' => true,
            'frequency' => 'weekly',
            'time_of_day' => '03:30',
            'last_status' => '',
            'last_message' => '',
            'last_run_at' => '',
        ), $GLOBALS['ssc_test_options'][ScheduleSettingsRepository::OPTION_NAME]);
    }
}
