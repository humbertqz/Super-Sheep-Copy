<?php

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

interface InstallerPreparationManagerInterface
{
    public function prepare(string $restore_job_id): InstallerPreparationResult;
}
