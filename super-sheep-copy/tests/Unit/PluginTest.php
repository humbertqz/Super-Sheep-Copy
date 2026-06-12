<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Plugin;
use SuperSheepCopy\Schedule\ScheduleEventScheduler;

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ssc_test_scheduled_events'] = array(
            ScheduleEventScheduler::DUE_HOOK => array('timestamp' => 100, 'hook' => ScheduleEventScheduler::DUE_HOOK, 'args' => array()),
            ScheduleEventScheduler::CONTINUE_HOOK => array('timestamp' => 200, 'hook' => ScheduleEventScheduler::CONTINUE_HOOK, 'args' => array()),
        );
    }

    public function testDeactivateClearsScheduledBackupHooks(): void
    {
        Plugin::deactivate();

        self::assertArrayNotHasKey(ScheduleEventScheduler::DUE_HOOK, $GLOBALS['ssc_test_scheduled_events']);
        self::assertArrayNotHasKey(ScheduleEventScheduler::CONTINUE_HOOK, $GLOBALS['ssc_test_scheduled_events']);
    }
}
