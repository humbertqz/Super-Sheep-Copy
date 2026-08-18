<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Support\EnvironmentChecker;

final class EnvironmentCheckerTest extends TestCase
{
    public function testReturnsStructuredChecks(): void
    {
        $checks = (new EnvironmentChecker(sys_get_temp_dir()))->check();

        self::assertArrayHasKey('php_version', $checks);
        self::assertArrayHasKey('zip', $checks);
        self::assertArrayHasKey('cli_zip', $checks);
        self::assertArrayHasKey('tar_gzip', $checks);
        self::assertArrayHasKey('folder_package', $checks);
        self::assertArrayHasKey('backup_storage', $checks);
        self::assertArrayHasKey('disk_free_space', $checks);
        self::assertArrayHasKey('label', $checks['php_version']);
        self::assertArrayHasKey('value', $checks['php_version']);
        self::assertArrayHasKey('status', $checks['php_version']);
        self::assertSame('TAR/GZIP package support', $checks['tar_gzip']['label']);
        self::assertSame('CLI zip command', $checks['cli_zip']['label']);
        self::assertSame('Folder package fallback', $checks['folder_package']['label']);
        self::assertSame('ok', $checks['backup_storage']['status']);
        self::assertNotSame('', $checks['disk_free_space']['value']);
    }
}
