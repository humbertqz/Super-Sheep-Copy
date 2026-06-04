<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Settings\BackupSettings;
use SuperSheepCopy\Settings\BackupSettingsRepository;

final class BackupSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ssc_test_options'] = array();
    }

    public function testDefaultsAreSafeForNormalUsers(): void
    {
        $settings = BackupSettings::defaults();

        self::assertTrue($settings->excludeCacheFiles());
        self::assertTrue($settings->skipLargeFiles());
        self::assertSame(250, $settings->largeFileLimitMb());
        self::assertSame(5, $settings->retentionCount());
        self::assertTrue($settings->autoCleanFailedJobs());
        self::assertFalse($settings->debugLogging());
    }

    public function testFromArraySanitizesAndClampsValues(): void
    {
        $settings = BackupSettings::fromArray(array(
            'exclude_cache_files' => '0',
            'skip_large_files' => '1',
            'large_file_limit_mb' => '9999',
            'retention_count' => '-4',
            'auto_clean_failed_jobs' => '',
            'debug_logging' => '1',
        ));

        self::assertFalse($settings->excludeCacheFiles());
        self::assertTrue($settings->skipLargeFiles());
        self::assertSame(2048, $settings->largeFileLimitMb());
        self::assertSame(1, $settings->retentionCount());
        self::assertFalse($settings->autoCleanFailedJobs());
        self::assertTrue($settings->debugLogging());
    }

    public function testToArrayUsesStableOptionKeys(): void
    {
        self::assertSame(array(
            'exclude_cache_files' => true,
            'skip_large_files' => true,
            'large_file_limit_mb' => 250,
            'retention_count' => 5,
            'auto_clean_failed_jobs' => true,
            'debug_logging' => false,
        ), BackupSettings::defaults()->toArray());
    }

    public function testSummaryLabelsDescribeFutureBackupDefaults(): void
    {
        self::assertSame(array(
            'Cache folders excluded',
            'Files over 250 MB skipped',
            'Keeping last 5 successful backups',
        ), BackupSettings::defaults()->summaryLabels());
    }

    public function testRepositoryLoadsDefaultsWhenOptionIsMissing(): void
    {
        $repository = new BackupSettingsRepository();

        self::assertSame(BackupSettings::defaults()->toArray(), $repository->get()->toArray());
    }

    public function testRepositorySavesSanitizedSettings(): void
    {
        $repository = new BackupSettingsRepository();

        $repository->save(BackupSettings::fromArray(array(
            'exclude_cache_files' => '0',
            'skip_large_files' => '1',
            'large_file_limit_mb' => '75',
            'retention_count' => '3',
            'auto_clean_failed_jobs' => '1',
            'debug_logging' => '0',
        )));

        self::assertSame(array(
            'exclude_cache_files' => false,
            'skip_large_files' => true,
            'large_file_limit_mb' => 75,
            'retention_count' => 3,
            'auto_clean_failed_jobs' => true,
            'debug_logging' => false,
        ), $GLOBALS['ssc_test_options'][BackupSettingsRepository::OPTION_NAME]);
    }

    public function testRepositoryTreatsUnchangedSettingsAsSuccessfulSave(): void
    {
        $repository = new BackupSettingsRepository();
        $settings = BackupSettings::defaults();
        $GLOBALS['ssc_test_options'][BackupSettingsRepository::OPTION_NAME] = $settings->toArray();

        self::assertTrue($repository->save($settings));
    }
}
