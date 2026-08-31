<?php
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Action detection reads request method before capability and nonce checks.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Status/query reads are non-mutating admin display state.

declare(strict_types=1);

namespace SuperSheepCopy\Admin;

use SuperSheepCopy\Backup\BackupManagerFactoryInterface;
use SuperSheepCopy\Backup\BackupMetadataCollectorInterface;
use SuperSheepCopy\Backup\BackupJobFileCleaner;
use SuperSheepCopy\Backup\BackupJobSiteGuard;
use SuperSheepCopy\Backup\BackupOptions;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Plugin;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Settings\BackupSettingsRepository;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;
use SuperSheepCopy\Support\LoggerInterface;
use SuperSheepCopy\Support\NullLogger;
use Throwable;

final class BackupPage
{
    private const ACTION_FIELD = 'super_sheep_copy_action';
    private const ACTION_CREATE_BACKUP = 'create_backup';
    private const ACTION_DELETE_JOB = 'delete_job';
    private const ACTION_DOWNLOAD_BACKUP = 'download_backup';
    private const STATUS_FIELD = 'super_sheep_copy_status';
    private const JOB_ID_FIELD = 'job_id';
    private const ERROR_FIELD = 'super_sheep_copy_error';

    private Capability $capability;
    private Nonce $nonce;
    private EnvironmentCheckerInterface $environment_checker;
    private JobRepositoryInterface $jobs;
    private ?BackupManagerFactoryInterface $backup_factory;
    private ?BackupMetadataCollectorInterface $metadata_collector;
    private ?BackupJobFileCleaner $job_file_cleaner;
    private BackupSettingsRepository $settings;
    private LoggerInterface $logger;

    public function __construct(
        Capability $capability,
        Nonce $nonce,
        EnvironmentCheckerInterface $environment_checker,
        JobRepositoryInterface $jobs,
        ?BackupManagerFactoryInterface $backup_factory = null,
        ?BackupMetadataCollectorInterface $metadata_collector = null,
        ?BackupJobFileCleaner $job_file_cleaner = null,
        ?BackupSettingsRepository $settings = null,
        ?LoggerInterface $logger = null
    ) {
        $this->capability = $capability;
        $this->nonce = $nonce;
        $this->environment_checker = $environment_checker;
        $this->jobs = $jobs;
        $this->backup_factory = $backup_factory;
        $this->metadata_collector = $metadata_collector;
        $this->job_file_cleaner = $job_file_cleaner;
        $this->settings = $settings !== null ? $settings : new BackupSettingsRepository();
        $this->logger = $logger !== null ? $logger : new NullLogger();
    }

    public function render(): void
    {
        $this->capability->requireManageBackups();
        $this->pruneMissingRestoreArchives();
        $this->stopForeignRunningBackupJobs();
        $this->autoCleanFailedBackupFiles();

        $environment = $this->environment_checker->check();
        $jobs = $this->jobsForDisplay();
        $job_created_date_labels = $this->jobCreatedDateLabels($jobs);
        $current_job = $this->currentJob();
        $backup_settings = $this->settings->get();
        $backup_settings_summary = $backup_settings->summaryLabels();
        $manifest_preview = array(
            'project' => 'Super Sheep Copy',
            'plugin_version' => defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : '0.1.1',
            'backup_format_version' => '1',
            'source_site_url' => function_exists('site_url') ? site_url() : '',
            'source_home_url' => function_exists('home_url') ? home_url() : '',
            'is_multisite' => function_exists('is_multisite') ? is_multisite() : false,
        );
        $status = $this->status();
        $backup_error = $this->requestError();
        $nonce_field = $this->nonce->field();
        include SUPER_SHEEP_COPY_DIR . 'templates/backup-page.php';
    }

    public function handleActions(): void
    {
        if ($this->handleCreateBackup()) {
            return;
        }
        if ($this->handleDeleteJob()) {
            return;
        }
        if ($this->handleDownloadBackup()) {
            return;
        }
    }

    private function handleCreateBackup(): bool
    {
        if (!$this->isCreateBackupRequest()) {
            return false;
        }

        $this->capability->assertManageBackups();
        $this->nonce->verifyRequest();

        try {
            if ($this->metadata_collector === null) {
                throw new \RuntimeException('Backup services are not configured.');
            }

            $metadata = $this->metadata_collector->collect();
            $backup_settings = $this->settings->get();
            $options = new BackupOptions(
                defined('ABSPATH') ? ABSPATH : '',
                Plugin::backupDirectory(),
                isset($metadata['table_prefix']) ? (string) $metadata['table_prefix'] : '',
                'prefixed',
                5000,
                $metadata
            );
            $job_id = 'backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
            $working_directory = rtrim($options->workingBaseDirectory(), '/\\') . '/' . $job_id;

            $this->jobs->save(new Job($job_id, 'backup', Job::CREATED, array(
                'site_root' => $options->siteRoot(),
                'working_directory' => $working_directory,
                'table_prefix' => $options->tablePrefix(),
                'table_selection_mode' => $options->tableSelectionMode(),
                'database_chunk_size' => $options->databaseChunkSize(),
                'manifest_metadata' => $options->manifestMetadata(),
                'backup_settings' => $backup_settings->toArray(),
                'message' => 'Backup queued.',
                'updated_at' => gmdate('c'),
            )));
            $this->redirect('backup_queued', array(self::JOB_ID_FIELD => $job_id));
        } catch (Throwable $throwable) {
            $this->logger->error('Backup creation failed.', $this->exceptionContext($throwable));
            $this->redirect('backup_failed', array(self::ERROR_FIELD => $this->displayError($throwable)));
        }

        return true;
    }

    private function isCreateBackupRequest(): bool
    {
        $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

        return $action === self::ACTION_CREATE_BACKUP;
    }

    private function handleDeleteJob(): bool
    {
        if (!$this->isDeleteJobRequest()) {
            return false;
        }

        $this->capability->assertManageBackups();
        $this->nonce->verifyRequest();

        try {
            $job_id = isset($_POST[self::JOB_ID_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::JOB_ID_FIELD])) : '';
            if ($job_id !== '') {
                $job = $this->jobs->find($job_id);
                if ($job !== null) {
                    $this->jobFileCleaner()->clean($job);
                }

                $this->jobs->delete($job_id);
            }

            $this->redirect('job_deleted');
        } catch (Throwable $throwable) {
            $this->logger->error('Backup deletion failed.', $this->exceptionContext($throwable, array('job_id' => $job_id ?? '')));
            $this->redirect('job_delete_failed', array(self::ERROR_FIELD => $this->displayError($throwable)));
        }

        return true;
    }

    private function isDeleteJobRequest(): bool
    {
        $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

        return $action === self::ACTION_DELETE_JOB;
    }

    private function handleDownloadBackup(): bool
    {
        if (!$this->isDownloadBackupRequest()) {
            return false;
        }

        try {
            $this->downloadHandler()->handleRequest();
        } catch (Throwable $throwable) {
            $job_id = isset($_POST[self::JOB_ID_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::JOB_ID_FIELD])) : '';
            $this->logger->error('Backup download failed.', $this->exceptionContext($throwable, array('job_id' => $job_id)));
            $this->redirect('download_failed', array(self::ERROR_FIELD => $this->displayError($throwable)));
        }

        return true;
    }

    private function isDownloadBackupRequest(): bool
    {
        $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

        return $action === self::ACTION_DOWNLOAD_BACKUP;
    }

    private function downloadHandler(): BackupDownloadHandler
    {
        return new BackupDownloadHandler($this->capability, $this->nonce, $this->jobs, Plugin::backupDirectory());
    }

    private function jobFileCleaner(): BackupJobFileCleaner
    {
        if ($this->job_file_cleaner === null) {
            $this->job_file_cleaner = new BackupJobFileCleaner(Plugin::backupDirectory());
        }

        return $this->job_file_cleaner;
    }

    private function pruneMissingRestoreArchives(): void
    {
        foreach ($this->jobs->all() as $job) {
            if ($job->type() !== 'restore') {
                continue;
            }

            $payload = $job->payload();
            $staged_archive = isset($payload['staged_archive']) && is_scalar($payload['staged_archive'])
                ? (string) $payload['staged_archive']
                : '';

            if (!$this->isRestoreArchiveName($staged_archive)) {
                continue;
            }

            $archive_path = trailingslashit(Plugin::backupDirectory()) . 'restore/' . $staged_archive;
            if (!is_readable($archive_path)) {
                $this->jobs->delete($job->id());
            }
        }
    }

    private function isRestoreArchiveName(string $archive): bool
    {
        $lower = strtolower($archive);

        return $archive !== ''
            && basename($archive) === $archive
            && (
                substr($lower, -4) === '.zip'
                || substr($lower, -4) === '.tar'
                || substr($lower, -7) === '.tar.gz'
                || strpos($archive, '.') === false
            );
    }

    private function stopForeignRunningBackupJobs(): void
    {
        $guard = new BackupJobSiteGuard();
        foreach ($this->jobs->all() as $job) {
            if (!$guard->isForeignRunningBackupJob($job, defined('ABSPATH') ? ABSPATH : '', Plugin::backupDirectory())) {
                continue;
            }

            $payload = $job->payload();
            $payload['message'] = 'Backup failed: job belongs to a different site or upload directory.';
            $payload['error'] = 'Job belongs to a different site or upload directory.';
            $payload['failed_state'] = $job->state();
            $payload['updated_at'] = gmdate('c');
            $this->jobs->save(new Job($job->id(), $job->type(), Job::FAILED, $payload));
            $this->logger->error('Backup job stopped.', array(
                'job_id' => $job->id(),
                'state' => $job->state(),
                'error' => $payload['error'],
            ));
        }
    }

    private function autoCleanFailedBackupFiles(): void
    {
        $settings = $this->settings->get();
        if (!$settings->autoCleanFailedJobs()) {
            return;
        }

        $this->jobFileCleaner()->cleanFailedJobs($this->jobs->all(), 86400);
    }

    /**
     * @return list<Job>
     */
    private function jobsForDisplay(): array
    {
        $indexed_jobs = array();
        foreach ($this->jobs->all() as $index => $job) {
            $indexed_jobs[] = array(
                'index' => $index,
                'timestamp' => $this->jobTimestamp($job),
                'job' => $job,
            );
        }

        usort($indexed_jobs, static function (array $a, array $b): int {
            if ($a['timestamp'] === $b['timestamp']) {
                return $a['index'] <=> $b['index'];
            }

            return $b['timestamp'] <=> $a['timestamp'];
        });

        return array_map(static function (array $indexed_job): Job {
            return $indexed_job['job'];
        }, $indexed_jobs);
    }

    private function jobTimestamp(Job $job): int
    {
        $payload = $job->payload();
        $updated_at = isset($payload['updated_at']) && is_scalar($payload['updated_at'])
            ? strtotime((string) $payload['updated_at'])
            : false;
        if ($updated_at !== false) {
            return $updated_at;
        }

        if (isset($payload['backup_completed_at']) && is_numeric($payload['backup_completed_at'])) {
            return (int) $payload['backup_completed_at'];
        }

        return $this->jobIdTimestamp($job);
    }

    /**
     * @param list<Job> $jobs
     * @return array<string,string>
     */
    private function jobCreatedDateLabels(array $jobs): array
    {
        $labels = array();
        foreach ($jobs as $job) {
            $labels[$job->id()] = $this->jobCreatedDateLabel($job);
        }

        return $labels;
    }

    private function jobCreatedDateLabel(Job $job): string
    {
        $timestamp = $this->jobCreatedTimestamp($job);
        if ($timestamp <= 0) {
            return '';
        }

        if (function_exists('wp_date')) {
            $date_format = get_option('date_format', 'Y-m-d');
            $time_format = get_option('time_format', 'H:i');

            return wp_date((string) $date_format . ' ' . (string) $time_format, $timestamp);
        }

        return gmdate('Y-m-d H:i', $timestamp) . ' UTC';
    }

    private function jobCreatedTimestamp(Job $job): int
    {
        $from_id = $this->jobIdTimestamp($job);
        if ($from_id > 0) {
            return $from_id;
        }

        $payload = $job->payload();
        $created_at = isset($payload['created_at']) && is_scalar($payload['created_at'])
            ? strtotime((string) $payload['created_at'])
            : false;
        if ($created_at !== false) {
            return $created_at;
        }

        $updated_at = isset($payload['updated_at']) && is_scalar($payload['updated_at'])
            ? strtotime((string) $payload['updated_at'])
            : false;
        if ($updated_at !== false) {
            return $updated_at;
        }

        return isset($payload['backup_completed_at']) && is_numeric($payload['backup_completed_at'])
            ? (int) $payload['backup_completed_at']
            : 0;
    }

    private function jobIdTimestamp(Job $job): int
    {
        if (preg_match('/^(?:backup|restore)-(\d{8})-(\d{6})-/', $job->id(), $matches) !== 1) {
            return 0;
        }

        $from_id = strtotime($matches[1] . $matches[2] . ' UTC');

        return $from_id !== false ? $from_id : 0;
    }

    /**
     * @param array<string,string> $extra
     */
    private function redirect(string $status, array $extra = array()): void
    {
        wp_safe_redirect(add_query_arg(
            array_merge(
                array(
                    'page' => 'super-sheep-copy',
                    self::STATUS_FIELD => $status,
                ),
                $extra
            ),
            admin_url('admin.php')
        ));
    }

    private function status(): string
    {
        return isset($_GET[self::STATUS_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::STATUS_FIELD])) : '';
    }

    private function requestError(): string
    {
        $error = isset($_GET[self::ERROR_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::ERROR_FIELD])) : '';

        return substr($error, 0, 500);
    }

    private function displayError(Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());

        return substr($message !== '' ? $message : 'An unexpected error occurred.', 0, 500);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function exceptionContext(Throwable $throwable, array $context = array()): array
    {
        return array_merge($context, array(
            'exception' => get_class($throwable),
            'error' => $throwable->getMessage(),
        ));
    }

    private function currentJob(): ?Job
    {
        $job_id = isset($_GET[self::JOB_ID_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::JOB_ID_FIELD])) : '';
        if ($job_id === '') {
            return null;
        }

        $job = $this->jobs->find($job_id);

        return $job instanceof Job && $job->type() === 'backup' ? $job : null;
    }
}
