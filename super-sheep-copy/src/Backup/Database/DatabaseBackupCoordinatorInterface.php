<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

interface DatabaseBackupCoordinatorInterface
{
    public function export(string $working_directory, string $table_prefix, string $selection_mode, int $chunk_size, ?string $job_id = null): void;
}
