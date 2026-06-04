<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleaner removes only validated paths inside plugin-owned backup storage.

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RuntimeException;
use SuperSheepCopy\Jobs\Job;

final class BackupJobFileCleaner
{
    private string $backup_directory;

    public function __construct(string $backup_directory)
    {
        $real_path = realpath($backup_directory);
        $this->backup_directory = $this->normalizePath($real_path === false ? $backup_directory : $real_path);
    }

    public function clean(Job $job): void
    {
        $payload = $job->payload();
        $paths = array();

        if (isset($payload['working_directory']) && is_string($payload['working_directory'])) {
            $paths[] = $payload['working_directory'];
        }

        if (isset($payload['archive_path']) && is_string($payload['archive_path'])) {
            $paths[] = $payload['archive_path'];
        }

        foreach (array_unique($paths) as $path) {
            $this->deletePath($path);
        }
    }

    /**
     * @param Job[] $jobs
     */
    public function cleanFailedJobs(array $jobs, int $older_than_seconds = 86400): int
    {
        $deleted = 0;
        $cutoff = time() - max(0, $older_than_seconds);

        foreach ($jobs as $job) {
            if (!$job instanceof Job || $job->type() !== 'backup' || $job->state() !== Job::FAILED) {
                continue;
            }

            $payload = $job->payload();
            $updated_at = isset($payload['updated_at']) && is_scalar($payload['updated_at'])
                ? strtotime((string) $payload['updated_at'])
                : false;
            if ($updated_at !== false && $updated_at > $cutoff) {
                continue;
            }

            $this->clean($job);
            if ($this->hasRemainingFailedJobFiles($job)) {
                throw new RuntimeException('Unable to clean failed backup files for job: ' . esc_html($job->id()));
            }
            $deleted++;
        }

        return $deleted;
    }

    private function hasRemainingFailedJobFiles(Job $job): bool
    {
        $payload = $job->payload();
        if (!isset($payload['working_directory']) || !is_string($payload['working_directory']) || $payload['working_directory'] === '') {
            return false;
        }

        $real_path = realpath($payload['working_directory']);
        if ($real_path === false) {
            return false;
        }

        $real_path = $this->normalizePath($real_path);

        return $this->isInsideBackupDirectory($real_path) && file_exists($real_path);
    }

    private function deletePath(string $path): void
    {
        if ($path === '') {
            return;
        }

        $real_path = realpath($path);
        if ($real_path === false) {
            return;
        }

        $real_path = $this->normalizePath($real_path);
        if (!$this->isInsideBackupDirectory($real_path)) {
            return;
        }

        if (is_dir($real_path) && !is_link($real_path)) {
            $this->removeDirectory($real_path);
            return;
        }

        if (is_file($real_path) || is_link($real_path)) {
            if (!@unlink($real_path)) {
                throw new RuntimeException('Unable to delete backup file: ' . esc_html($real_path));
            }
        }
    }

    private function isInsideBackupDirectory(string $path): bool
    {
        return $path !== $this->backup_directory
            && strpos($path . '/', $this->backup_directory . '/') === 0;
    }

    private function removeDirectory(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
                continue;
            }

            if (is_file($path) || is_link($path)) {
                if (!@unlink($path)) {
                    throw new RuntimeException('Unable to delete backup file: ' . esc_html($path));
                }
            }
        }

        if (!@rmdir($directory)) {
            throw new RuntimeException('Unable to delete backup directory: ' . esc_html($directory));
        }
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
