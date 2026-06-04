<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupPerformanceMetrics;

final class BackupPerformanceMetricsTest extends TestCase
{
    public function testBottleneckReturnsSlowestPhase(): void
    {
        $metrics = new BackupPerformanceMetrics();

        self::assertSame('archive', $metrics->bottleneck(array(
            'database_last_step_seconds' => 2.0,
            'file_scan_last_step_seconds' => 1.0,
            'archive_last_step_seconds' => 20.0,
        )));
    }

    public function testCompletedSummaryHidesRunningOnlyMetricsAndShowsAverageThroughput(): void
    {
        $metrics = new BackupPerformanceMetrics();

        self::assertSame('Completed in 10m | Avg 256.0 MB/min', $metrics->summary(array(
            'archive_size' => 2684354560,
            'backup_total_seconds' => 600,
            'archive_entries_per_second' => 565.35,
            'archive_mb_per_second' => 65.845,
            'archive_eta_seconds' => 0,
            'backup_bottleneck' => 'archive',
        ), 'completed'));
    }
}
