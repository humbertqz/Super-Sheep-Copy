<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class BackupResult
{
    private string $job_id;
    private string $working_directory;
    private string $database_directory;
    private string $archive_path;
    private int $archive_size;
    private int $scanned_file_count;
    private int $database_file_count;
    private string $state;

    public function __construct(
        string $job_id,
        string $working_directory,
        string $database_directory,
        string $archive_path,
        int $archive_size,
        int $scanned_file_count,
        int $database_file_count,
        string $state
    ) {
        $this->job_id = $job_id;
        $this->working_directory = $working_directory;
        $this->database_directory = $database_directory;
        $this->archive_path = $archive_path;
        $this->archive_size = $archive_size;
        $this->scanned_file_count = $scanned_file_count;
        $this->database_file_count = $database_file_count;
        $this->state = $state;
    }

    public function jobId(): string
    {
        return $this->job_id;
    }

    public function workingDirectory(): string
    {
        return $this->working_directory;
    }

    public function databaseDirectory(): string
    {
        return $this->database_directory;
    }

    public function archivePath(): string
    {
        return $this->archive_path;
    }

    public function archiveSize(): int
    {
        return $this->archive_size;
    }

    public function scannedFileCount(): int
    {
        return $this->scanned_file_count;
    }

    public function databaseFileCount(): int
    {
        return $this->database_file_count;
    }

    public function state(): string
    {
        return $this->state;
    }
}
