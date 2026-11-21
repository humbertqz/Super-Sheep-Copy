<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use SuperSheepCopy\Jobs\Job;

final class BackupFailure
{
    /** @return array{job_id:string,state:string,error_code:string,failed_at:string,plugin_version:string} */
    public static function context(Job $job): array
    {
        $payload = $job->payload();
        $steps = array(
            Job::CREATED => 'BACKUP_SETUP_FAILED',
            Job::EXPORTING_DATABASE => 'BACKUP_DATABASE_EXPORT_FAILED',
            Job::SCANNING_FILES => 'BACKUP_FILE_SCAN_FAILED',
            Job::PACKAGING_ARCHIVE => 'BACKUP_PACKAGING_FAILED',
            Job::VALIDATING_BACKUP => 'BACKUP_VALIDATION_FAILED',
        );
        $state = is_string($payload['failed_state'] ?? null) ? $payload['failed_state'] : '';
        $code = $steps[$state] ?? 'BACKUP_FAILED';
        if (($payload['error'] ?? '') === 'Job belongs to a different site or upload directory.') {
            $code = 'BACKUP_SITE_MISMATCH';
        } elseif (empty($payload['error']) && ($payload['archive_validation_status'] ?? '') === 'invalid') {
            $state = Job::VALIDATING_BACKUP;
            $code = 'BACKUP_ARCHIVE_INVALID';
        }
        $timestamp = is_string($payload['updated_at'] ?? null) ? strtotime($payload['updated_at']) : false;

        return array(
            'job_id' => $job->id(),
            'state' => isset($steps[$state]) ? $state : 'unknown',
            'error_code' => $code,
            'failed_at' => $timestamp === false ? 'unknown' : gmdate('c', $timestamp),
            'plugin_version' => defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : 'unknown',
        );
    }

    public static function message(string $code): string
    {
        switch ($code) {
            case 'BACKUP_SITE_MISMATCH':
                return __('This backup belongs to a different site or upload directory. Create a new backup on this site.', 'super-sheep-copy');
            case 'BACKUP_SETUP_FAILED':
                return __('The backup could not start. Check that backup storage is writable, then retry.', 'super-sheep-copy');
            case 'BACKUP_DATABASE_EXPORT_FAILED':
                return __('The backup stopped during database export. Retry the backup. If it fails again, share the error details with support.', 'super-sheep-copy');
            case 'BACKUP_FILE_SCAN_FAILED':
                return __('The backup stopped while scanning site files. Check that WordPress can read the site files, then retry.', 'super-sheep-copy');
            case 'BACKUP_PACKAGING_FAILED':
                return __('The backup stopped while creating the archive. Check available disk space and backup storage permissions, then retry.', 'super-sheep-copy');
            case 'BACKUP_ARCHIVE_INVALID':
                return __('The backup archive failed validation. Create a new backup. If it fails again, share the error details with support.', 'super-sheep-copy');
            case 'BACKUP_VALIDATION_FAILED':
                return __('The backup stopped while checking the archive. Retry the backup. If it fails again, share the error details with support.', 'super-sheep-copy');
            default:
                return __('The backup stopped unexpectedly. Retry the backup or share the error details with support.', 'super-sheep-copy');
        }
    }

    /** @param array{job_id:string,state:string,error_code:string,failed_at:string,plugin_version:string} $context */
    public static function report(array $context): string
    {
        return implode("\n", array(
            'Error code: ' . $context['error_code'],
            'Job ID: ' . $context['job_id'],
            'Failed step: ' . $context['state'],
            'Time (UTC): ' . $context['failed_at'],
            'Plugin version: ' . $context['plugin_version'],
        ));
    }
}
