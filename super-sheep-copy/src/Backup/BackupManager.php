<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Backup manager creates plugin-owned working directories.

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RuntimeException;
use SuperSheepCopy\Backup\Database\DatabaseBackupCoordinatorInterface;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class BackupManager implements BackupRunnerInterface
{
    private JobRepositoryInterface $jobs;
    private DatabaseBackupCoordinatorInterface $database;
    private FileScanner $files;
    private BackupArchivePackagerInterface $packager;
    private ?BackupProgressReporterInterface $progress;

    public function __construct(JobRepositoryInterface $jobs, DatabaseBackupCoordinatorInterface $database, FileScanner $files, BackupArchivePackagerInterface $packager, ?BackupProgressReporterInterface $progress = null)
    {
        $this->jobs = $jobs;
        $this->database = $database;
        $this->files = $files;
        $this->packager = $packager;
        $this->progress = $progress;
    }

    public function run(BackupOptions $options): BackupResult
    {
        $job_id = 'backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $working_directory = rtrim($options->workingBaseDirectory(), '/\\') . '/' . $job_id;
        $database_directory = $working_directory . '/database';

        $this->ensureDirectory($working_directory);

        $this->save($job_id, Job::CREATED, array('working_directory' => $working_directory));
        $this->report($job_id, Job::CREATED, 'created');
        $this->save($job_id, Job::EXPORTING_DATABASE, array('working_directory' => $working_directory));
        $this->report($job_id, Job::EXPORTING_DATABASE, 'database_started');

        $this->database->export(
            $working_directory,
            $options->tablePrefix(),
            $options->tableSelectionMode(),
            $options->databaseChunkSize(),
            $job_id
        );
        $this->report($job_id, Job::EXPORTING_DATABASE, 'database_finished');

        $this->save($job_id, Job::SCANNING_FILES, array('working_directory' => $working_directory));
        $this->report($job_id, Job::SCANNING_FILES, 'file_scan_started');
        $files = $this->files->scan($options->siteRoot());
        $scanned_file_count = count($files);
        $this->report($job_id, Job::SCANNING_FILES, 'file_scan_finished');

        $this->save($job_id, Job::PACKAGING_ARCHIVE, array('working_directory' => $working_directory));
        $this->report($job_id, Job::PACKAGING_ARCHIVE, 'archive_package_started');
        $archive = $this->packager->package($job_id, $working_directory, $database_directory, $files, $options->manifestMetadata());
        $this->report($job_id, Job::PACKAGING_ARCHIVE, 'archive_package_finished');

        $payload = array(
            'working_directory' => $working_directory,
            'database_directory' => $database_directory,
            'archive_path' => $archive->archivePath(),
            'archive_size' => $archive->archiveSize(),
            'package_format' => $archive->packageFormat(),
            'package_extension' => $archive->packageExtension(),
            'scanned_file_count' => $scanned_file_count,
            'database_file_count' => $archive->databaseFileCount(),
        );
        $this->save($job_id, Job::COMPLETED, $payload);
        $this->report($job_id, Job::COMPLETED, 'completed');

        return new BackupResult(
            $job_id,
            $working_directory,
            $database_directory,
            $archive->archivePath(),
            $archive->archiveSize(),
            $scanned_file_count,
            $archive->databaseFileCount(),
            Job::COMPLETED
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function save(string $job_id, string $state, array $payload): void
    {
        $this->jobs->save(new Job($job_id, 'backup', $state, $payload));
    }

    private function report(string $job_id, string $state, string $step): void
    {
        if ($this->progress === null) {
            return;
        }

        $this->progress->report($job_id, $state, array('step' => $step));
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create backup working directory: ' . esc_html($directory));
        }
    }
}
