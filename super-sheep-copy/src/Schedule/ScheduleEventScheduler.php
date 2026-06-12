<?php

declare(strict_types=1);

namespace SuperSheepCopy\Schedule;

final class ScheduleEventScheduler
{
    public const DUE_HOOK = 'super_sheep_copy_scheduled_backup_due';
    public const CONTINUE_HOOK = 'super_sheep_copy_scheduled_backup_continue';

    private ScheduleCalculator $calculator;

    public function __construct(?ScheduleCalculator $calculator = null)
    {
        $this->calculator = $calculator ?: new ScheduleCalculator();
    }

    public function sync(ScheduleSettings $settings, ?int $now = null): void
    {
        $this->clearDueEvent();
        if (!$settings->enabled()) {
            return;
        }

        $this->scheduleDueEvent($settings, $now);
    }

    public function scheduleDueEvent(ScheduleSettings $settings, ?int $now = null): void
    {
        if (!$settings->enabled()) {
            return;
        }

        if (!function_exists('wp_schedule_single_event')) {
            return;
        }

        wp_schedule_single_event($this->calculator->nextRunTimestamp($settings, $now), self::DUE_HOOK);
    }

    public function scheduleContinuation(int $delay_seconds = 60): void
    {
        if (!function_exists('wp_schedule_single_event')) {
            return;
        }

        wp_schedule_single_event(time() + max(1, $delay_seconds), self::CONTINUE_HOOK);
    }

    public function clearDueEvent(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::DUE_HOOK);
        }
    }

    public function nextDueTimestamp(): int
    {
        if (!function_exists('wp_next_scheduled')) {
            return 0;
        }

        $timestamp = wp_next_scheduled(self::DUE_HOOK);

        return is_numeric($timestamp) ? (int) $timestamp : 0;
    }
}
