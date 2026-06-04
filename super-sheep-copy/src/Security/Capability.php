<?php

declare(strict_types=1);

namespace SuperSheepCopy\Security;

use RuntimeException;

final class Capability
{
    public const MANAGE_BACKUPS = 'manage_options';

    public function canManageBackups(): bool
    {
        return current_user_can(self::MANAGE_BACKUPS);
    }

    public function requireManageBackups(): void
    {
        if (!$this->canManageBackups()) {
            wp_die(esc_html__('You do not have permission to manage backups.', 'super-sheep-copy'));
        }
    }

    public function assertManageBackups(): void
    {
        if (!$this->canManageBackups()) {
            throw new RuntimeException('Current user cannot manage Super Sheep Copy backups.');
        }
    }
}
