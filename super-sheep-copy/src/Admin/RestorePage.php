<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable,WordPress.WP.AlternativeFunctions.unlink_unlink -- Restore admin deletes plugin-owned staged archives after capability and nonce checks.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Action detection reads request method before capability and nonce checks.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Status/query reads are non-mutating admin display state.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploaded file arrays are validated by restore preparation before use.

declare(strict_types=1);

namespace SuperSheepCopy\Admin;

use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Plugin;
use SuperSheepCopy\Restore\InstallerPreparationManagerInterface;
use SuperSheepCopy\Restore\RestorePreparationManagerInterface;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;
use SuperSheepCopy\Support\Filesystem;
use SuperSheepCopy\Support\LoggerInterface;
use Throwable;

final class RestorePage
{
    private const ACTION_FIELD = 'super_sheep_copy_action';
    private const ACTION_DELETE_STAGED_ARCHIVE = 'delete_staged_archive';
    private const ACTION_PREPARE_INSTALLER = 'prepare_installer';
    private const ACTION_PREPARE_RESTORE = 'prepare_restore';
    private const STATUS_FIELD = 'super_sheep_copy_status';
    private const FILE_FIELD = 'super_sheep_copy_restore_archive';
    private const RESTORE_ERROR_FIELD = 'super_sheep_copy_restore_error';
    private const STAGED_ARCHIVE_FIELD = 'super_sheep_copy_staged_archive';
    private const JOB_ID_FIELD = 'super_sheep_copy_restore_job_id';
    private const INSTALLER_TOKEN_FIELD = 'super_sheep_copy_installer_token';

    private Capability $capability;
    private Nonce $nonce;
    private EnvironmentCheckerInterface $environment_checker;
    private LoggerInterface $logger;
    private RestorePreparationManagerInterface $restore_preparation;
    private JobRepositoryInterface $jobs;
    private InstallerPreparationManagerInterface $installer_preparation;

    public function __construct(
        Capability $capability,
        Nonce $nonce,
        EnvironmentCheckerInterface $environment_checker,
        LoggerInterface $logger,
        RestorePreparationManagerInterface $restore_preparation,
        JobRepositoryInterface $jobs,
        InstallerPreparationManagerInterface $installer_preparation
    ) {
        $this->capability = $capability;
        $this->nonce = $nonce;
        $this->environment_checker = $environment_checker;
        $this->logger = $logger;
        $this->restore_preparation = $restore_preparation;
        $this->jobs = $jobs;
        $this->installer_preparation = $installer_preparation;
    }

    public function render(): void
    {
        $this->capability->requireManageBackups();
        $this->handleDeleteStagedArchive();
        $this->handlePrepareRestore();
        $this->handlePrepareInstaller();

        $environment = $this->environment_checker->check();
        $status = $this->status();
        $restore_error = $this->restoreError();
        $restore_job = $this->restoreJob();
        $installer_token = $this->installerToken();
        $installer_launch_url = $this->installerLaunchUrl($restore_job, $installer_token);
        $restore_staging_directory = $this->restoreStagingDirectory();
        $max_upload_size_label = $this->maxUploadSizeLabel();
        $staged_archives = $this->stagedArchives($restore_staging_directory);
        $nonce_field = $this->nonce->field();
        include SUPER_SHEEP_COPY_DIR . 'templates/restore-page.php';
    }

    private function handlePrepareRestore(): bool
    {
        if (!$this->isPrepareRestoreRequest()) {
            return false;
        }

        $this->capability->assertManageBackups();
        $this->nonce->verifyRequest();

        try {
            $upload = $this->selectedStagedArchiveUpload();
            if ($upload === array()) {
                $upload = isset($_FILES[self::FILE_FIELD]) && is_array($_FILES[self::FILE_FIELD]) ? $_FILES[self::FILE_FIELD] : array();
            }
            $result = $this->restore_preparation->prepare($upload);
            $this->redirectToState('restore_prepared', array(self::JOB_ID_FIELD => $result->jobId()));
        } catch (Throwable $throwable) {
            $error = $this->truncateRestoreError($throwable->getMessage());
            $this->logger->error('Restore preparation failed.', $this->exceptionContext($throwable));
            $this->redirectToState('restore_failed', array(self::RESTORE_ERROR_FIELD => $error));
        }

        return true;
    }

    private function handleDeleteStagedArchive(): bool
    {
        if (!$this->isAction(self::ACTION_DELETE_STAGED_ARCHIVE)) {
            return false;
        }

        $this->capability->assertManageBackups();
        $this->nonce->verifyRequest();

        try {
            $this->deleteSelectedStagedArchive();
            $this->redirectToState('backup_deleted');
        } catch (Throwable $throwable) {
            $archive = isset($_POST[self::STAGED_ARCHIVE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::STAGED_ARCHIVE_FIELD])) : '';
            $error = $this->truncateRestoreError($throwable->getMessage());
            $this->logger->error('Restore backup deletion failed.', $this->exceptionContext($throwable, array('archive' => $archive)));
            $this->redirectToState('backup_delete_failed', array(self::RESTORE_ERROR_FIELD => $error));
        }

        return true;
    }

    private function isPrepareRestoreRequest(): bool
    {
        return $this->isAction(self::ACTION_PREPARE_RESTORE);
    }

    private function handlePrepareInstaller(): bool
    {
        if (!$this->isAction(self::ACTION_PREPARE_INSTALLER)) {
            return false;
        }

        $this->capability->assertManageBackups();
        $this->nonce->verifyRequest();

        try {
            $job_id = isset($_POST[self::JOB_ID_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::JOB_ID_FIELD])) : '';
            $result = $this->installer_preparation->prepare($job_id);
            $this->redirectToState('installer_prepared', array(
                self::JOB_ID_FIELD => $result->jobId(),
                self::INSTALLER_TOKEN_FIELD => $result->token(),
            ));
        } catch (Throwable $throwable) {
            $job_id = isset($_POST[self::JOB_ID_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::JOB_ID_FIELD])) : '';
            $error = $this->truncateRestoreError($throwable->getMessage());
            $this->logger->error('Installer preparation failed.', $this->exceptionContext($throwable, array('job_id' => $job_id)));
            $this->redirectToState('installer_failed', array(self::RESTORE_ERROR_FIELD => $error));
        }

        return true;
    }

    private function isAction(string $expected): bool
    {
        $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

        return $action === $expected;
    }

    /**
     * @param array<string,string> $extra
     */
    private function redirect(string $status, array $extra = array()): void
    {
        wp_safe_redirect(add_query_arg(
            array_merge(
                array(
                    'page' => 'super-sheep-copy-restore',
                    self::STATUS_FIELD => $status,
                ),
                $extra
            ),
            admin_url('admin.php')
        ));
    }

    /**
     * @param array<string,string> $extra
     */
    private function redirectToState(string $status, array $extra = array()): void
    {
        $_GET[self::STATUS_FIELD] = $status;
        foreach ($extra as $key => $value) {
            $_GET[$key] = $value;
        }

        $this->redirect($status, $extra);
    }

    private function status(): string
    {
        return isset($_GET[self::STATUS_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::STATUS_FIELD])) : '';
    }

    private function restoreError(): string
    {
        $error = isset($_GET[self::RESTORE_ERROR_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::RESTORE_ERROR_FIELD])) : '';

        return $error === '' ? '' : $this->truncateRestoreError($error);
    }

    private function truncateRestoreError(string $error): string
    {
        $error = trim($error);

        return substr($error !== '' ? $error : 'An unexpected error occurred.', 0, 500);
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

    private function restoreJob(): ?Job
    {
        $job_id = isset($_GET[self::JOB_ID_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::JOB_ID_FIELD])) : '';
        if ($job_id === '') {
            return null;
        }

        $job = $this->jobs->find($job_id);
        return $job instanceof Job && $job->type() === 'restore' ? $job : null;
    }

    private function restoreStagingDirectory(): string
    {
        return trailingslashit(Plugin::backupDirectory()) . 'restore';
    }

    private function maxUploadSizeLabel(): string
    {
        return size_format(wp_max_upload_size());
    }

    /**
     * @return list<string>
     */
    private function stagedArchives(string $directory): array
    {
        if (!is_dir($directory)) {
            return array();
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return array();
        }

        $archives = array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = rtrim($directory, '/\\') . '/' . $entry;
            if ((is_file($path) && is_readable($path) && $this->isSupportedPackageFile($entry)) || $this->isPackageDirectory($path)) {
                $archives[] = $entry;
            }
        }

        sort($archives, SORT_NATURAL | SORT_FLAG_CASE);

        return $archives;
    }

    /**
     * @return array<string,mixed>
     */
    private function selectedStagedArchiveUpload(): array
    {
        $selected = isset($_POST[self::STAGED_ARCHIVE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::STAGED_ARCHIVE_FIELD])) : '';
        if ($selected === '' || $selected !== basename($selected)) {
            return array();
        }

        $path = rtrim($this->restoreStagingDirectory(), '/\\') . '/' . $selected;
        if (!((is_file($path) && is_readable($path) && $this->isSupportedPackageFile($selected)) || $this->isPackageDirectory($path))) {
            return array();
        }

        return array(
            'name' => $selected,
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path) ?: 0,
        );
    }

    private function deleteSelectedStagedArchive(): void
    {
        $selected = isset($_POST[self::STAGED_ARCHIVE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::STAGED_ARCHIVE_FIELD])) : '';
        if ($selected === '' || $selected !== basename($selected)) {
            throw new \RuntimeException('Invalid staged archive.');
        }

        $path = rtrim($this->restoreStagingDirectory(), '/\\') . '/' . $selected;
        if (is_file($path) && $this->isSupportedPackageFile($selected) && is_writable($path) && unlink($path)) {
            return;
        }

        if ($this->isPackageDirectory($path) && is_writable($path)) {
            $this->removeDirectory($path);
            return;
        }

        if (is_file($path) || is_dir($path)) {
            throw new \RuntimeException('Unable to delete staged archive.');
        }

        throw new \RuntimeException('Invalid staged archive.');
    }

    private function isSupportedPackageFile(string $name): bool
    {
        $lower = strtolower($name);

        return substr($lower, -4) === '.zip'
            || substr($lower, -4) === '.tar'
            || substr($lower, -7) === '.tar.gz';
    }

    private function isPackageDirectory(string $path): bool
    {
        return is_dir($path) && is_readable($path) && is_file(rtrim($path, '/\\') . '/manifest.json');
    }

    private function removeDirectory(string $path): void
    {
        if (!Filesystem::removeDirectory($path)) {
            throw new \RuntimeException('Unable to delete staged archive.');
        }
    }

    private function installerToken(): string
    {
        return isset($_GET[self::INSTALLER_TOKEN_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::INSTALLER_TOKEN_FIELD])) : '';
    }

    private function installerLaunchUrl(?Job $restore_job, string $token): string
    {
        if (!$restore_job instanceof Job || $token === '') {
            return '';
        }

        $payload = $restore_job->payload();
        $installer_url = isset($payload['installer_url']) ? (string) $payload['installer_url'] : site_url() . '/installer.php';

        return add_query_arg(array('token' => $token), $installer_url);
    }
}
