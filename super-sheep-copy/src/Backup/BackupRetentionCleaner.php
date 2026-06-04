<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class BackupRetentionCleaner
{
    private JobRepositoryInterface $jobs;
    private BackupJobFileCleaner $file_cleaner;

    public function __construct(JobRepositoryInterface $jobs, string $backup_directory)
    {
        $this->jobs = $jobs;
        $this->file_cleaner = new BackupJobFileCleaner($backup_directory);
    }

    public function clean(int $keep): int
    {
        $keep = max(1, $keep);
        $completed = array_values(array_filter($this->jobs->all(), static function (Job $job): bool {
            return $job->type() === 'backup' && $job->state() === Job::COMPLETED && isset($job->payload()['archive_path']);
        }));

        usort($completed, static function (Job $a, Job $b): int {
            $a_payload = $a->payload();
            $b_payload = $b->payload();
            $a_time = isset($a_payload['backup_completed_at']) ? (int) $a_payload['backup_completed_at'] : 0;
            $b_time = isset($b_payload['backup_completed_at']) ? (int) $b_payload['backup_completed_at'] : 0;

            return $b_time <=> $a_time;
        });

        $deleted = 0;
        foreach (array_slice($completed, $keep) as $job) {
            $this->file_cleaner->clean($job);
            $this->jobs->delete($job->id());
            $deleted++;
        }

        return $deleted;
    }
}
