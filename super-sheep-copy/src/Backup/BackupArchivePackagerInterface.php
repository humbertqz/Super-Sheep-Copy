<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

interface BackupArchivePackagerInterface
{
    /**
     * @param ScannedFile[] $site_files
     * @param array<string,mixed> $metadata
     */
    public function package(string $job_id, string $working_directory, string $database_directory, array $site_files, array $metadata): ArchivePackageResult;
}
