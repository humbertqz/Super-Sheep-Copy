<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Support\EnvironmentChecker;

final class EnvironmentCheckerTest extends TestCase
{
    public function testReturnsStructuredChecks(): void
    {
        $checks = (new EnvironmentChecker())->check();

        self::assertArrayHasKey('php_version', $checks);
        self::assertArrayHasKey('zip', $checks);
        self::assertArrayHasKey('tar_gzip', $checks);
        self::assertArrayHasKey('folder_package', $checks);
        self::assertArrayHasKey('label', $checks['php_version']);
        self::assertArrayHasKey('value', $checks['php_version']);
        self::assertArrayHasKey('status', $checks['php_version']);
        self::assertSame('TAR/GZIP package support', $checks['tar_gzip']['label']);
        self::assertSame('Folder package fallback', $checks['folder_package']['label']);
    }
}
