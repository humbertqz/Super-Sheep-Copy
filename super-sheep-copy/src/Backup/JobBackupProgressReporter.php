<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class JobBackupProgressReporter implements BackupProgressReporterInterface
{
    private JobRepositoryInterface $jobs;

    public function __construct(JobRepositoryInterface $jobs)
    {
        $this->jobs = $jobs;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function report(string $job_id, string $state, array $payload): void
    {
        $job = $this->jobs->find($job_id);
        if (!$job instanceof Job) {
            return;
        }

        $payload['updated_at'] = gmdate('c');

        $this->jobs->save(new Job(
            $job->id(),
            $job->type(),
            $state,
            array_merge($job->payload(), $payload)
        ));
    }
}
