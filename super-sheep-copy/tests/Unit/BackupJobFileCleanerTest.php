<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupJobFileCleaner;
use SuperSheepCopy\Jobs\Job;

final class BackupJobFileCleanerTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testDeletesBackupWorkingDirectoryAndFiles(): void
    {
        $backup_directory = $this->makeDirectory('backup-root/super-sheep-copy');
        $working_directory = $this->makeDirectory('backup-root/super-sheep-copy/backup-123/database');
        $working_directory = dirname($working_directory);
        $archive_path = $working_directory . '/backup-123.zip';
        file_put_contents($working_directory . '/database/chunk-000001.sql', 'rows');
        file_put_contents($archive_path, 'zip');

        $job = new Job('backup-123', 'backup', Job::COMPLETED, array(
            'working_directory' => $working_directory,
            'archive_path' => $archive_path,
        ));

        (new BackupJobFileCleaner($backup_directory))->clean($job);

        self::assertDirectoryDoesNotExist($working_directory);
        self::assertFileDoesNotExist($archive_path);
    }

    public function testRefusesToDeletePathsOutsideBackupDirectory(): void
    {
        $backup_directory = $this->makeDirectory('backup-root/super-sheep-copy');
        $outside_file = $this->makeFile('outside.txt', 'keep');
        $outside_directory = $this->makeDirectory('outside-dir');
        file_put_contents($outside_directory . '/keep.txt', 'keep');

        $job = new Job('backup-123', 'backup', Job::COMPLETED, array(
            'working_directory' => $outside_directory,
            'archive_path' => $outside_file,
        ));

        (new BackupJobFileCleaner($backup_directory))->clean($job);

        self::assertFileExists($outside_file);
        self::assertDirectoryExists($outside_directory);
        self::assertFileExists($outside_directory . '/keep.txt');
    }

    public function testCleanFailedJobsDeletesOnlyFailedWorkingDirectories(): void
    {
        $failed_dir = $this->makeDirectory('backup-failed');
        file_put_contents($failed_dir . '/partial.txt', 'partial');
        $completed_dir = $this->makeDirectory('backup-complete');
        file_put_contents($completed_dir . '/archive.zip', 'archive');

        $failed = new Job('backup-failed', 'backup', Job::FAILED, array(
            'working_directory' => $failed_dir,
            'updated_at' => gmdate('c', time() - 90000),
        ));
        $completed = new Job('backup-complete', 'backup', Job::COMPLETED, array(
            'working_directory' => $completed_dir,
            'archive_path' => $completed_dir . '/archive.zip',
            'updated_at' => gmdate('c', time() - 90000),
        ));

        $cleaner = new BackupJobFileCleaner((string) $this->root);

        self::assertSame(1, $cleaner->cleanFailedJobs(array($failed, $completed), 86400));
        self::assertDirectoryDoesNotExist($failed_dir);
        self::assertDirectoryExists($completed_dir);
    }

    public function testManualFailedJobCleanupCanDeleteRecentFailedWorkingDirectories(): void
    {
        $failed_dir = $this->makeDirectory('backup-failed-recent');
        file_put_contents($failed_dir . '/partial.txt', 'partial');
        $failed = new Job('backup-failed-recent', 'backup', Job::FAILED, array(
            'working_directory' => $failed_dir,
            'updated_at' => gmdate('c'),
        ));

        $cleaner = new BackupJobFileCleaner((string) $this->root);

        self::assertSame(1, $cleaner->cleanFailedJobs(array($failed), 0));
        self::assertDirectoryDoesNotExist($failed_dir);
    }

    public function testCleanFailedJobsThrowsWhenDirectoryCannotBeRemoved(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Directory permissions behave differently on Windows.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to delete backup file:');

        $failed_dir = $this->makeDirectory('backup-failed-locked');
        file_put_contents($failed_dir . '/partial.txt', 'partial');
        chmod($failed_dir, 0500);

        try {
            $failed = new Job('backup-failed-locked', 'backup', Job::FAILED, array(
                'working_directory' => $failed_dir,
                'updated_at' => gmdate('c'),
            ));
            $cleaner = new BackupJobFileCleaner((string) $this->root);

            $cleaner->cleanFailedJobs(array($failed), 0);
        } finally {
            chmod($failed_dir, 0700);
        }
    }

    private function makeDirectory(string $path): string
    {
        $directory = $this->root() . '/' . $path;
        mkdir($directory, 0777, true);

        return $directory;
    }

    private function makeFile(string $path, string $contents): string
    {
        $file = $this->root() . '/' . $path;
        file_put_contents($file, $contents);

        return $file;
    }

    private function root(): string
    {
        if ($this->root === null) {
            $this->root = sys_get_temp_dir() . '/ssc-cleaner-test-' . bin2hex(random_bytes(6));
            mkdir($this->root, 0777, true);
        }

        return $this->root;
    }

    private function removeDirectory(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
