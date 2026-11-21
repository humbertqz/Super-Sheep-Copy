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
                'archive_changed_file_count' => 1,
            )),
        ));

        self::assertStringContainsString('Plugin version: 0.1.1', $report);
        self::assertStringContainsString('WordPress version: 6.5', $report);
        self::assertStringContainsString('PHP version:', $report);
        self::assertStringContainsString('Backup storage writable:', $report);
        self::assertStringContainsString('ZIP support:', $report);
        self::assertStringContainsString('Last backup: completed', $report);
        self::assertStringContainsString('Skipped large files: 2', $report);
        self::assertStringContainsString('Files changed during backup: 1', $report);
    }

    public function testBuildReportDoesNotIncludeSecretLikePayloadValues(): void
    {
        $report = (new DiagnosticsReportBuilder())->build('/tmp/backups', array(
            new Job('backup-older', 'backup', Job::FAILED, array(
                'updated_at' => '2026-11-18T12:00:00+00:00',
            )),
            new Job('backup-latest-failure', 'backup', Job::FAILED, array(
                'updated_at' => '2026-11-19T12:00:00-06:00',
                'archive_validation_status' => 'invalid',
                'message' => 'Checksum mismatch for archive entry: private/path.php',
            )),
            new Job('backup-123', 'backup', Job::COMPLETED, array(
                'backup_completed_at' => 1795190400,
                'db_password' => 'secret-pass',
                'restore_token' => 'secret-token',
                'nonce' => 'secret-nonce',
            )),
        ));

        self::assertStringNotContainsString('secret-pass', $report);
        self::assertStringNotContainsString('secret-token', $report);
        self::assertStringNotContainsString('secret-nonce', $report);
        self::assertStringNotContainsString('private/path.php', $report);
        self::assertStringNotContainsString('backup-older', $report);
        self::assertStringContainsString('Job ID: backup-latest-failure', $report);
        self::assertStringContainsString('Error code: BACKUP_ARCHIVE_INVALID', $report);
        self::assertStringContainsString('Failed step: validating_backup', $report);
        self::assertStringContainsString('Time (UTC): 2026-11-19T18:00:00+00:00', $report);
    }
}
