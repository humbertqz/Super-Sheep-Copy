<?php

declare(strict_types=1);

namespace SuperSheepCopy\Settings;

final class BackupSettingsRepository
{
    public const OPTION_NAME = 'super_sheep_copy_settings';

    public function get(): BackupSettings
    {
        $value = get_option(self::OPTION_NAME, array());
        if (!is_array($value)) {
            return BackupSettings::defaults();
        }

        return BackupSettings::fromArray($value);
    }

    public function save(BackupSettings $settings): bool
    {
        $current = get_option(self::OPTION_NAME, array());
        if (is_array($current) && BackupSettings::fromArray($current)->toArray() === $settings->toArray()) {
            return true;
        }

        return update_option(self::OPTION_NAME, $settings->toArray(), false);
    }
}
