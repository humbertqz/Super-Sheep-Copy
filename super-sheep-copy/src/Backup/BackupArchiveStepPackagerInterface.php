<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

interface BackupArchiveStepPackagerInterface
{
    /**
     * @param ScannedFile[] $site_files
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function packageStep(string $job_id, string $working_directory, string $database_directory, array $site_files, array $metadata, array $payload): array;
}
