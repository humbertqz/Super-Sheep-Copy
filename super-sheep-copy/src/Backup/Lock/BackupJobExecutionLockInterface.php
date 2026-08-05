<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Lock;

interface BackupJobExecutionLockInterface
{
    public function acquire(string $job_id): ?string;

    public function release(string $job_id, string $owner_token): void;
}
