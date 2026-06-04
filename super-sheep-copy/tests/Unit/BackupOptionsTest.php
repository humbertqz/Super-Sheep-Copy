<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupOptions;
use SuperSheepCopy\Backup\BackupResult;
use SuperSheepCopy\Jobs\Job;

final class BackupOptionsTest extends TestCase
{
    public function testStoresBackupOptions(): void
    {
        $metadata = array('source_site_url' => 'https://example.com');
        $options = new BackupOptions('/site', '/backups', 'wp_', 'prefixed', 100, $metadata);

        self::assertSame('/site', $options->siteRoot());
        self::assertSame('/backups', $options->workingBaseDirectory());
        self::assertSame('wp_', $options->tablePrefix());
        self::assertSame('prefixed', $options->tableSelectionMode());
        self::assertSame(100, $options->databaseChunkSize());
        self::assertSame($metadata, $options->manifestMetadata());
    }

    public function testRejectsEmptySiteRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Site root is required.');

        new BackupOptions('', '/backups', 'wp_', 'prefixed', 100);
    }

    public function testRejectsInvalidChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database chunk size must be greater than zero.');

        new BackupOptions('/site', '/backups', 'wp_', 'prefixed', 0);
    }

    public function testStoresBackupResult(): void
    {
        $result = new BackupResult('backup-123', '/backups/backup-123', '/backups/backup-123/database', '/backups/backup-123/backup-123.zip', 2048, 7, 3, Job::COMPLETED);

        self::assertSame('backup-123', $result->jobId());
        self::assertSame('/backups/backup-123', $result->workingDirectory());
        self::assertSame('/backups/backup-123/database', $result->databaseDirectory());
        self::assertSame('/backups/backup-123/backup-123.zip', $result->archivePath());
        self::assertSame(2048, $result->archiveSize());
        self::assertSame(7, $result->scannedFileCount());
        self::assertSame(3, $result->databaseFileCount());
        self::assertSame(Job::COMPLETED, $result->state());
    }
}
