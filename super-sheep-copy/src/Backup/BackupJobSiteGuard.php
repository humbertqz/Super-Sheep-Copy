<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use SuperSheepCopy\Jobs\Job;

final class BackupJobSiteGuard
{
    public function isForeignRunningBackupJob(Job $job, string $site_root, string $backup_directory): bool
    {
        return $job->type() === 'backup'
            && $this->isRunningBackupState($job->state())
            && $this->isForeignBackupJob($job, $site_root, $backup_directory);
    }

    private function isRunningBackupState(string $state): bool
    {
        return in_array($state, array(Job::CREATED, Job::EXPORTING_DATABASE, Job::SCANNING_FILES, Job::PACKAGING_ARCHIVE, Job::VALIDATING_BACKUP), true);
    }

    private function isForeignBackupJob(Job $job, string $site_root, string $backup_directory): bool
    {
        $payload = $job->payload();
        $job_site_root = isset($payload['site_root']) && is_scalar($payload['site_root']) ? (string) $payload['site_root'] : '';
        $working_directory = isset($payload['working_directory']) && is_scalar($payload['working_directory']) ? (string) $payload['working_directory'] : '';

        if ($job_site_root !== '' && $this->normalizePath($job_site_root) !== $this->normalizePath($site_root)) {
            return true;
        }

        if ($working_directory !== '' && !$this->isPathInside($working_directory, $backup_directory)) {
            return true;
        }

        return false;
    }

    private function isPathInside(string $path, string $directory): bool
    {
        $path = $this->normalizePath($path);
        $directory = rtrim($this->normalizePath($directory), '/');

        return $path === $directory || strpos($path, $directory . '/') === 0;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
