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
use Throwable;

final class BackupPage
{
    private const ACTION_FIELD = 'super_sheep_copy_action';
    private const ACTION_CREATE_BACKUP = 'create_backup';
    private const ACTION_DELETE_JOB = 'delete_job';
    private const ACTION_DOWNLOAD_BACKUP = 'download_backup';
    private const STATUS_FIELD = 'super_sheep_copy_status';
    private const JOB_ID_FIELD = 'job_id';

    private Capability $capability;
    private Nonce $nonce;
    private EnvironmentCheckerInterface $environment_checker;
    private JobRepositoryInterface $jobs;
    private ?BackupManagerFactoryInterface $backup_factory;
    private ?BackupMetadataCollectorInterface $metadata_collector;
    private ?BackupJobFileCleaner $job_file_cleaner;
    private BackupSettingsRepository $settings;

    public function __construct(
        Capability $capability,
        Nonce $nonce,
        EnvironmentCheckerInterface $environment_checker,
        JobRepositoryInterface $jobs,
        ?BackupManagerFactoryInterface $backup_factory = null,
        ?BackupMetadataCollectorInterface $metadata_collector = null,
        ?BackupJobFileCleaner $job_file_cleaner = null,
        ?BackupSettingsRepository $settings = null
    ) {
        $this->capability = $capability;
        $this->nonce = $nonce;
        $this->environment_checker = $environment_checker;
        $this->jobs = $jobs;
        $this->backup_factory = $backup_factory;
        $this->metadata_collector = $metadata_collector;
        $this->job_file_cleaner = $job_file_cleaner;
        $this->settings = $settings !== null ? $settings : new BackupSettingsRepository();
    }

    public function render(): void
    {
        $this->capability->requireManageBackups();
        $this->pruneMissingRestoreArchives();
        $this->stopForeignRunningBackupJobs();
        $this->autoCleanFailedBackupFiles();

        $environment = $this->environment_checker->check();
        $jobs = $this->jobs->all();
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
            $this->redirect('backup_failed');
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

        $job_id = isset($_POST[self::JOB_ID_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::JOB_ID_FIELD])) : '';
        if ($job_id !== '') {
            $job = $this->jobs->find($job_id);
            if ($job !== null) {
                $this->jobFileCleaner()->clean($job);
            }

            $this->jobs->delete($job_id);
        }

        $this->redirect('job_deleted');

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
            $this->redirect('download_failed');
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
