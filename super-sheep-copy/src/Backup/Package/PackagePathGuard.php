<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

use RuntimeException;

final class PackagePathGuard
{
    public static function isSafeEntryPath(string $path): bool
    {
        if ($path === '' || strpos($path, "\0") !== false) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);
        if ($normalized[0] === '/' || preg_match('#^[A-Za-z]:/#', $normalized) === 1) {
            return false;
        }

        foreach (explode('/', $normalized) as $part) {
            if ($part === '..') {
                return false;
            }
        }

        return true;
    }

    public static function assertSafeEntryPath(string $path): void
    {
        if (!self::isSafeEntryPath($path)) {
            throw new RuntimeException('Unsafe package entry path: ' . esc_html($path));
        }
    }
}
