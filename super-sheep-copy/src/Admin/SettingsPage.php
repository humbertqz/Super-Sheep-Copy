<?php
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Action detection reads request method before capability and nonce checks.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Status/query reads are non-mutating admin display state.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings arrays are normalized by BackupSettings::fromArray before storage.

declare(strict_types=1);

namespace SuperSheepCopy\Admin;

use SuperSheepCopy\Backup\BackupJobFileCleaner;
use SuperSheepCopy\Backup\BackupRetentionCleaner;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Plugin;
use SuperSheepCopy\Schedule\ScheduleEventScheduler;
use SuperSheepCopy\Schedule\ScheduleSettings;
use SuperSheepCopy\Schedule\ScheduleSettingsRepository;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Settings\BackupSettings;
use SuperSheepCopy\Settings\BackupSettingsRepository;
use SuperSheepCopy\Settings\DiagnosticsReportBuilder;

final class SettingsPage
{
    private const ACTION_FIELD = 'super_sheep_copy_action';
    private const ACTION_SAVE_SETTINGS = 'save_settings';
    private const ACTION_CLEAN_FAILED_JOBS = 'clean_failed_jobs';
    private const ACTION_DOWNLOAD_DIAGNOSTICS = 'download_diagnostics';
    private const STATUS_FIELD = 'super_sheep_copy_status';

    private Capability $capability;
    private Nonce $nonce;
    private BackupSettingsRepository $settings;
    private ScheduleSettingsRepository $schedule_settings;
    private ScheduleEventScheduler $schedule_events;
    private ?JobRepositoryInterface $jobs;
    /** @var callable(string):void */
    private $diagnostics_sender;

    /**
     * @param callable(string):void|null $diagnostics_sender
     */
    public function __construct(
        Capability $capability,
        Nonce $nonce,
        BackupSettingsRepository $settings,
        ?JobRepositoryInterface $jobs = null,
        $diagnostics_sender = null,
        ?ScheduleSettingsRepository $schedule_settings = null,
        ?ScheduleEventScheduler $schedule_events = null
    ) {
        $this->capability = $capability;
        $this->nonce = $nonce;
        $this->settings = $settings;
        $this->schedule_settings = $schedule_settings ?: new ScheduleSettingsRepository();
        $this->schedule_events = $schedule_events ?: new ScheduleEventScheduler();
        $this->jobs = $jobs;
        $this->diagnostics_sender = $diagnostics_sender !== null ? $diagnostics_sender : array($this, 'sendDiagnosticsReportAndExit');
    }

    public function render(): void
    {
        $this->capability->requireManageBackups();
        $settings = $this->settings->get();
        $schedule_settings = $this->schedule_settings->get();
        $next_run_timestamp = $this->schedule_events->nextDueTimestamp();
        $next_run_label = $next_run_timestamp > 0 ? gmdate('Y-m-d H:i', $next_run_timestamp) . ' UTC' : __('Not scheduled', 'super-sheep-copy');
        $status = isset($_GET[self::STATUS_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::STATUS_FIELD])) : '';
        $nonce_field = $this->nonce->field();
        $backup_storage_path = Plugin::backupDirectory();
        $backup_storage_used = $this->formatBytes($this->directorySize($backup_storage_path));
        $jobs = $this->jobs !== null ? $this->jobs->all() : array();
        $diagnostics = new DiagnosticsReportBuilder();
        $last_backup_summary = $diagnostics->lastBackupSummary($jobs);
        include SUPER_SHEEP_COPY_DIR . 'templates/settings-page.php';
    }

    public function handleActions(): void
    {
        if ($this->isDownloadDiagnosticsRequest()) {
            $this->capability->assertManageBackups();
            $this->nonce->verifyRequest();
            $jobs = $this->jobs !== null ? $this->jobs->all() : array();
            $report = (new DiagnosticsReportBuilder())->build(Plugin::backupDirectory(), $jobs);
            $sender = $this->diagnostics_sender;
            $sender($report);
            return;
        }

        if ($this->isCleanFailedJobsRequest()) {
            $this->capability->assertManageBackups();
            $this->nonce->verifyRequest();
            $jobs = $this->jobs !== null ? $this->jobs->all() : array();
            try {
                (new BackupJobFileCleaner(Plugin::backupDirectory()))->cleanFailedJobs($jobs, 0);
                $this->redirect('failed_jobs_cleaned');
            } catch (\Throwable $throwable) {
                $this->redirect('failed_jobs_cleanup_failed');
            }
            return;
        }

        if (!$this->isSaveSettingsRequest()) {
            return;
        }

        $this->capability->assertManageBackups();
        $this->nonce->verifyRequest();

        $submitted = isset($_POST['super_sheep_copy_settings']) && is_array($_POST['super_sheep_copy_settings'])
            ? wp_unslash($_POST['super_sheep_copy_settings'])
            : array();

        $settings = BackupSettings::fromArray(is_array($submitted) ? $submitted : array());
        $submitted_schedule = isset($_POST['super_sheep_copy_schedule']) && is_array($_POST['super_sheep_copy_schedule'])
            ? wp_unslash($_POST['super_sheep_copy_schedule'])
            : array();
        $schedule_settings = ScheduleSettings::fromArray(is_array($submitted_schedule) ? $submitted_schedule : array());
        $saved = $this->settings->save($settings);
        $schedule_saved = $this->schedule_settings->save($schedule_settings);
        if ($saved && $this->jobs !== null) {
            (new BackupRetentionCleaner($this->jobs, Plugin::backupDirectory()))->clean($settings->retentionCount());
        }
        if ($saved && $schedule_saved) {
            $this->schedule_events->sync($schedule_settings);
        }
        $this->redirect($saved && $schedule_saved ? 'settings_saved' : 'settings_failed');
    }

    private function isSaveSettingsRequest(): bool
    {
        $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

        return $action === self::ACTION_SAVE_SETTINGS;
    }

    private function isCleanFailedJobsRequest(): bool
    {
        $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

        return $action === self::ACTION_CLEAN_FAILED_JOBS;
    }

    private function isDownloadDiagnosticsRequest(): bool
    {
        $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

        return $action === self::ACTION_DOWNLOAD_DIAGNOSTICS;
    }

    private function sendDiagnosticsReportAndExit(string $report): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="super-sheep-copy-diagnostics.txt"');
        echo esc_html($report);
        exit;
    }

    private function directorySize(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $total = 0;
        $items = scandir($directory);
        if ($items === false) {
            return 0;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = rtrim($directory, '/\\') . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $total += $this->directorySize($path);
                continue;
            }

            if (is_file($path)) {
                $total += (int) filesize($path);
            }
        }

        return $total;
    }

    private function formatBytes(int $bytes): string
    {
        if (function_exists('size_format')) {
            return size_format($bytes);
        }

        return number_format($bytes) . ' B';
    }

    private function redirect(string $status): void
    {
        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'super-sheep-copy-settings',
                self::STATUS_FIELD => $status,
            ),
            admin_url('admin.php')
        ));
    }
}
