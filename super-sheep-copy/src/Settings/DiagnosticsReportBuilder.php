<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Diagnostics report checks server writeability only; no file mutation.

declare(strict_types=1);

namespace SuperSheepCopy\Settings;

use SuperSheepCopy\Jobs\Job;

final class DiagnosticsReportBuilder
{
    /**
     * @param Job[] $jobs
     */
    public function build(string $backup_directory, array $jobs): string
    {
        $last_backup = $this->lastBackup($jobs);
        $lines = array(
            'Super Sheep Copy Diagnostics',
            'Plugin version: ' . (defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : 'unknown'),
            'WordPress version: ' . (function_exists('get_bloginfo') ? get_bloginfo('version') : 'unknown'),
            'PHP version: ' . PHP_VERSION,
            'Backup storage writable: ' . (is_writable($backup_directory) ? 'yes' : 'no'),
            'ZIP support: ' . (class_exists('ZipArchive') ? 'yes' : 'no'),
            'TAR/GZIP package support: ' . (class_exists('PharData') ? 'yes' : 'no'),
            'Folder package fallback: yes',
            'Memory limit: ' . (string) ini_get('memory_limit'),
            'Max execution time: ' . (string) ini_get('max_execution_time'),
        );

        if ($last_backup instanceof Job) {
            $payload = $last_backup->payload();
            $lines[] = 'Last backup: ' . $last_backup->state();
            $lines[] = 'Last backup size: ' . (isset($payload['archive_size']) ? (string) (int) $payload['archive_size'] : 'unknown');
            $lines[] = 'Last backup duration: ' . (isset($payload['backup_total_seconds']) ? (string) (int) $payload['backup_total_seconds'] : 'unknown');
            $lines[] = 'Skipped large files: ' . (isset($payload['skipped_large_file_count']) ? (string) (int) $payload['skipped_large_file_count'] : '0');
            $lines[] = 'Files changed during backup: ' . (isset($payload['archive_changed_file_count']) ? (string) (int) $payload['archive_changed_file_count'] : '0');
        } else {
            $lines[] = 'Last backup: none';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param Job[] $jobs
     */
    public function lastBackupSummary(array $jobs): string
    {
        $last_backup = $this->lastBackup($jobs);
        if (!$last_backup instanceof Job) {
            return 'No backups yet.';
        }

        $payload = $last_backup->payload();
        $size = isset($payload['archive_size']) ? (int) $payload['archive_size'] : 0;
        $seconds = isset($payload['backup_total_seconds']) ? (int) $payload['backup_total_seconds'] : 0;

        return $last_backup->state() . ' backup, ' . $size . ' bytes, ' . $seconds . ' seconds.';
    }

    /**
     * @param Job[] $jobs
     */
    private function lastBackup(array $jobs): ?Job
    {
        $backups = array_values(array_filter($jobs, static function ($job): bool {
            return $job instanceof Job && $job->type() === 'backup';
        }));

        usort($backups, static function (Job $a, Job $b): int {
            $a_payload = $a->payload();
            $b_payload = $b->payload();
            $a_time = isset($a_payload['backup_completed_at']) ? (int) $a_payload['backup_completed_at'] : 0;
            $b_time = isset($b_payload['backup_completed_at']) ? (int) $b_payload['backup_completed_at'] : 0;

            return $b_time <=> $a_time;
        });

        return $backups[0] ?? null;
    }
}
