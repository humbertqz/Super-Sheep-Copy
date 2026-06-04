<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

interface BackupRunnerInterface
{
    public function run(BackupOptions $options): BackupResult;
}
