<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

interface BackupProgressReporterInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function report(string $job_id, string $state, array $payload): void;
}
