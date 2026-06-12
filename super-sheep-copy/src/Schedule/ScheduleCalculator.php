<?php

declare(strict_types=1);

namespace SuperSheepCopy\Schedule;

final class ScheduleCalculator
{
    private \DateTimeZone $timezone;

    public function __construct(?\DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone ?: $this->siteTimezone();
    }

    public function nextRunTimestamp(ScheduleSettings $settings, ?int $now = null): int
    {
        $now_datetime = new \DateTimeImmutable('@' . (string) ($now ?? time()));
        $now_datetime = $now_datetime->setTimezone($this->timezone);
        [$hour, $minute] = array_map('intval', explode(':', $settings->timeOfDay()));

        if ($settings->frequency() === 'weekly') {
            return $this->nextWeekly($now_datetime, $hour, $minute);
        }

        if ($settings->frequency() === 'monthly') {
            return $this->nextMonthly($now_datetime, $hour, $minute);
        }

        return $this->nextDaily($now_datetime, $hour, $minute);
    }

    private function nextDaily(\DateTimeImmutable $now, int $hour, int $minute): int
    {
        $candidate = $now->setTime($hour, $minute, 0);
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate->getTimestamp();
    }

    private function nextWeekly(\DateTimeImmutable $now, int $hour, int $minute): int
    {
        $candidate = $now->modify('monday this week')->setTime($hour, $minute, 0);
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+1 week');
        }

        return $candidate->getTimestamp();
    }

    private function nextMonthly(\DateTimeImmutable $now, int $hour, int $minute): int
    {
        $candidate = $now->modify('first day of this month')->setTime($hour, $minute, 0);
        if ($candidate <= $now) {
            $candidate = $candidate->modify('first day of next month');
        }

        return $candidate->getTimestamp();
    }

    private function siteTimezone(): \DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        return new \DateTimeZone(date_default_timezone_get());
    }
}
