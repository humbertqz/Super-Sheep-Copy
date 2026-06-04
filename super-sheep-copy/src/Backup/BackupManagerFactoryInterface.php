<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

interface BackupManagerFactoryInterface
{
    public function create(): BackupRunnerInterface;
}
