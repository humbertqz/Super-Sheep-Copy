<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Settings\DiagnosticsReportBuilder;

final class DiagnosticsReportBuilderTest extends TestCase
{
    public function testBuildReportIncludesSafeEnvironmentAndLastBackup(): void
    {
        $report = (new DiagnosticsReportBuilder())->build('/tmp/backups', array(
            new Job('backup-123', 'backup', Job::COMPLETED, array(
                'archive_size' => 1048576,
                'backup_total_seconds' => 12,
                'skipped_large_file_count' => 2,
            )),
        ));

        self::assertStringContainsString('Plugin version: 0.1.0', $report);
        self::assertStringContainsString('WordPress version: 6.5', $report);
        self::assertStringContainsString('PHP version:', $report);
        self::assertStringContainsString('Backup storage writable:', $report);
        self::assertStringContainsString('ZIP support:', $report);
        self::assertStringContainsString('Last backup: completed', $report);
        self::assertStringContainsString('Skipped large files: 2', $report);
    }

    public function testBuildReportDoesNotIncludeSecretLikePayloadValues(): void
    {
        $report = (new DiagnosticsReportBuilder())->build('/tmp/backups', array(
            new Job('backup-123', 'backup', Job::COMPLETED, array(
                'db_password' => 'secret-pass',
                'restore_token' => 'secret-token',
                'nonce' => 'secret-nonce',
            )),
        ));

        self::assertStringNotContainsString('secret-pass', $report);
        self::assertStringNotContainsString('secret-token', $report);
        self::assertStringNotContainsString('secret-nonce', $report);
    }
}
