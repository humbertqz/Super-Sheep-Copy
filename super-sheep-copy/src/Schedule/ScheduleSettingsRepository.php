<?php

declare(strict_types=1);

namespace SuperSheepCopy\Schedule;

final class ScheduleSettingsRepository
{
    public const OPTION_NAME = 'super_sheep_copy_schedule_settings';

    public function get(): ScheduleSettings
    {
        $value = get_option(self::OPTION_NAME, array());

        return is_array($value) ? ScheduleSettings::fromArray($value) : ScheduleSettings::defaults();
    }

    public function save(ScheduleSettings $settings): bool
    {
        $current = get_option(self::OPTION_NAME, null);
        if (is_array($current) && ScheduleSettings::fromArray($current)->toArray() === $settings->toArray()) {
            return true;
        }

        return update_option(self::OPTION_NAME, $settings->toArray(), false);
    }
}
