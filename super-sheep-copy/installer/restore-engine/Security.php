<?php
// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Standalone installer uses signed restore token before WordPress is available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class Security
{
    /**
     * @param array<string,mixed> $config
     */
    public function verifyToken(string $token, array $config): bool
    {
        if ($token === '') {
            return false;
        }

        if (!empty($config['locked']) && empty($config['database_tables_swapped']) && empty($config['database_tables_swap_pending'])) {
            return false;
        }

        $hash = isset($config['token_hash']) ? (string) $config['token_hash'] : '';
        if ($hash === '') {
            return false;
        }

        return password_verify($token, $hash);
    }

    public function requestToken(): string
    {
        return isset($_GET['token']) && is_string($_GET['token']) ? (string) $_GET['token'] : '';
    }
}
