<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Package\PackagePathGuard;

final class PackagePathGuardTest extends TestCase
{
    public function testAcceptsSafeRelativePackagePaths(): void
    {
        self::assertTrue(PackagePathGuard::isSafeEntryPath('manifest.json'));
        self::assertTrue(PackagePathGuard::isSafeEntryPath('files/wp-content/uploads/a.jpg'));
        self::assertTrue(PackagePathGuard::isSafeEntryPath('database/chunks/wp_posts.part001.sql'));
    }

    public function testRejectsUnsafePackagePaths(): void
    {
        self::assertFalse(PackagePathGuard::isSafeEntryPath(''));
        self::assertFalse(PackagePathGuard::isSafeEntryPath('../wp-config.php'));
        self::assertFalse(PackagePathGuard::isSafeEntryPath('files/../../wp-config.php'));
        self::assertFalse(PackagePathGuard::isSafeEntryPath('/absolute/path.php'));
        self::assertFalse(PackagePathGuard::isSafeEntryPath('C:/site/wp-config.php'));
        self::assertFalse(PackagePathGuard::isSafeEntryPath("files/bad\0name.php"));
    }
}
