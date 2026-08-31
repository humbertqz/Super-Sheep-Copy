<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Support\ErrorLogLogger;

final class ErrorLogLoggerTest extends TestCase
{
    public function testWritesWarningsAndErrorsWithContextWhileDebugIsDisabled(): void
    {
        $entries = array();
        $logger = new ErrorLogLogger(false, static function (string $entry) use (&$entries): void {
            $entries[] = $entry;
        });

        $logger->info('Backup started.');
        $logger->warning('Restore warning.', array('job_id' => 'restore-123'));
        $logger->error('Backup failed.', array('error' => 'disk full'));

        self::assertCount(2, $entries);
        self::assertStringContainsString('[Super Sheep Copy] WARNING: Restore warning.', $entries[0]);
        self::assertStringContainsString('"job_id":"restore-123"', $entries[0]);
        self::assertStringContainsString('[Super Sheep Copy] ERROR: Backup failed.', $entries[1]);
        self::assertStringContainsString('"error":"disk full"', $entries[1]);
    }

    public function testWritesInformationWhenDebugIsEnabled(): void
    {
        $entries = array();
        $logger = new ErrorLogLogger(true, static function (string $entry) use (&$entries): void {
            $entries[] = $entry;
        });

        $logger->info('Backup started.');

        self::assertSame(array('[Super Sheep Copy] INFO: Backup started.'), $entries);
    }
}
