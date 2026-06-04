<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use SuperSheepCopy\Jobs\Job;

interface BackupStepRunnerInterface
{
    public function runStep(Job $job): Job;
}
