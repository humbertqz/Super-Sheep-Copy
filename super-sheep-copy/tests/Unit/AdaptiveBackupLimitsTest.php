<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\AdaptiveBackupLimits;

final class AdaptiveBackupLimitsTest extends TestCase
{
    public function testDatabaseChunkSizeGrowsAfterFastStepWithinCap(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(5000, $limits->databaseChunkSize(array(
            'database_adaptive_chunk_size' => 5000,
            'database_last_step_seconds' => 2.0,
            'database_last_step_rows' => 5000,
            'database_last_step_bytes' => 256000,
        )));
    }

    public function testDatabaseChunkSizeKeepsConfiguredInitialSize(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(2, $limits->databaseChunkSize(array(
            'database_chunk_size' => 2,
        )));
    }

    public function testDatabaseChunkSizeShrinksAfterSlowStep(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(2500, $limits->databaseChunkSize(array(
            'database_adaptive_chunk_size' => 5000,
            'database_last_step_seconds' => 20.0,
            'database_last_step_rows' => 5000,
            'database_last_step_bytes' => 256000,
        )));
    }

    public function testDatabaseChunkSizeUsesBytesAndRestartsSmallForEachTable(): void
    {
        $limits = new AdaptiveBackupLimits();
        $payload = array(
            'database_adaptive_chunk_size' => 1000,
            'database_last_step_seconds' => 0.5,
            'database_last_step_rows' => 1000,
            'database_last_step_bytes' => 16 * 1048576,
        );

        self::assertSame(250, $limits->databaseChunkSize($payload));
        self::assertSame(500, $limits->databaseChunkSize($payload, true));
        $payload['database_attempt_limit'] = 250;
        self::assertSame(125, $limits->databaseChunkSize($payload));
        $payload['database_attempt_limit'] = 2;
        self::assertSame(1, $limits->databaseChunkSize($payload));
    }

    public function testFileScanBatchSizeGrowsAfterFastStepWithinCap(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(2000, $limits->fileScanBatchSize(array(
            'file_scan_adaptive_batch_size' => 1000,
            'file_scan_last_step_seconds' => 1.0,
        )));
    }

    public function testFileScanBatchSizeUsesConfiguredInitialSize(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(1, $limits->fileScanBatchSize(array(), 1));
    }

    public function testFileScanBatchSizeGrowsConfiguredSizeAfterMeasuredFastStep(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(2, $limits->fileScanBatchSize(array(
            'file_scan_adaptive_batch_size' => 1,
            'file_scan_last_step_seconds' => 1.0,
        ), 1));
    }

    public function testArchiveTimeBudgetGrowsAfterFastStepWithinCap(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(6.0, $limits->archiveTimeBudgetSeconds(array(
            'archive_adaptive_time_budget_seconds' => 5.0,
            'archive_last_step_seconds' => 2.0,
        )));
    }

    public function testArchiveBudgetsClampLegacyValuesAndShrinkAfterSlowClose(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(10.0, $limits->archiveTimeBudgetSeconds(array('archive_adaptive_time_budget_seconds' => 45.0)));
        self::assertSame(5.0, $limits->archiveTimeBudgetSeconds(array(
            'archive_adaptive_time_budget_seconds' => 45.0,
            'archive_last_step_seconds' => 35.0,
        )));
        self::assertSame(8 * 1048576, $limits->archiveBatchBytes(array()));
        self::assertSame(2 * 1048576, $limits->archiveBatchBytes(array(
            'archive_last_step_bytes' => 8 * 1048576,
            'archive_last_step_seconds' => 20.0,
        )));
        self::assertSame(1048576, $limits->archiveBatchBytes(array(
            'archive_last_step_bytes' => 10,
            'archive_last_step_seconds' => 20.0,
        )));
        self::assertSame(128 * 1048576, $limits->archiveBatchBytes(array(
            'archive_last_step_bytes' => 8 * 1048576,
            'archive_last_step_seconds' => 0.001,
        )));
    }

    public function testFastArchiveStepsCanGrowBeyondOldThirtyTwoMegabyteCap(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(80 * 1048576, $limits->archiveBatchBytes(array(
            'archive_last_step_bytes' => 32 * 1048576,
            'archive_last_step_seconds' => 2.0,
        )));
        self::assertSame(8 * 1048576, $limits->archiveBatchBytes(array(
            'archive_last_step_bytes' => 32 * 1048576,
            'archive_last_step_seconds' => 20.0,
        )));
    }
}
