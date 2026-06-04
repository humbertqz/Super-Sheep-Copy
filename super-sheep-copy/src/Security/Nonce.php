<?php

declare(strict_types=1);

namespace SuperSheepCopy\Security;

use RuntimeException;

final class Nonce
{
    public const ACTION = 'super_sheep_copy_action';
    public const FIELD = 'super_sheep_copy_nonce';

    public function field(): string
    {
        return wp_nonce_field(self::ACTION, self::FIELD, true, false);
    }

    public function verifyRequest(): void
    {
        $nonce = isset($_REQUEST[self::FIELD]) ? sanitize_text_field(wp_unslash($_REQUEST[self::FIELD])) : '';

        if ($nonce === '' || !wp_verify_nonce($nonce, self::ACTION)) {
            throw new RuntimeException('Invalid Super Sheep Copy nonce.');
        }
    }
}
