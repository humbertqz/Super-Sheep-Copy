<?php

declare(strict_types=1);

namespace SuperSheepCopy\Schedule;

use SuperSheepCopy\Backup\BackupMetadataCollectorInterface;
use SuperSheepCopy\Backup\BackupOptions;
use SuperSheepCopy\Backup\BackupStepRunnerInterface;
use SuperSheepCopy\Backup\Lock\BackupJobExecutionLock;
use SuperSheepCopy\Backup\Lock\BackupJobExecutionLockInterface;
use SuperSheepCopy\Backup\Lock\WordPressOptionBackupJobLockStore;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Jobs\RefreshableJobRepositoryInterface;
use SuperSheepCopy\Settings\BackupSettingsRepository;
use Throwable;

final class ScheduledBackupRunner
{
    private const MAX_STEPS_PER_TICK = 3;

    private JobRepositoryInterface $jobs;
    private ScheduleSettingsRepository $schedule_settings;
    private BackupSettingsRepository $backup_settings;
    private BackupMetadataCollectorInterface $metadata_collector;
    private BackupStepRunnerInterface $step_runner;
    private string $site_root;
    private string $backup_directory;
    private ScheduleEventScheduler $events;
    private BackupJobExecutionLockInterface $lock;

    public function __construct(
        JobRepositoryInterface $jobs,
        ScheduleSettingsRepository $schedule_settings,
        BackupSettingsRepository $backup_settings,
        BackupMetadataCollectorInterface $metadata_collector,
        BackupStepRunnerInterface $step_runner,
        string $site_root,
        string $backup_directory,
        ?ScheduleEventScheduler $events = null,
        ?BackupJobExecutionLockInterface $lock = null
    ) {
        $this->jobs = $jobs;
        $this->schedule_settings = $schedule_settings;
        $this->backup_settings = $backup_settings;
        $this->metadata_collector = $metadata_collector;
        $this->step_runner = $step_runner;
        $this->site_root = $site_root;
        $this->backup_directory = $backup_directory;
        $this->events = $events ?: new ScheduleEventScheduler();
        $wpdb = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : new \stdClass();
        $this->lock = $lock ?? new BackupJobExecutionLock(new WordPressOptionBackupJobLockStore($wpdb));
    }

    public function register(): void
    {
        add_action(ScheduleEventScheduler::DUE_HOOK, array($this, 'handleDueEvent'));
        add_action(ScheduleEventScheduler::CONTINUE_HOOK, array($this, 'handleContinuationEvent'));
    }

    public function handleDueEvent(?int $scheduled_for = null): void
    {
        $settings = $this->schedule_settings->get();
        if (!$settings->enabled()) {
            return;
        }

        $run_at = gmdate('c');
        if ($this->runningBackup() !== null) {
            $this->schedule_settings->save($settings->withLastRun('skipped', 'A backup is already running.', $run_at));
            $this->events->scheduleDueEvent($settings, $scheduled_for ?? time());
            return;
        }

        $this->queueScheduledBackup($settings, $scheduled_for ?? time());
        $this->schedule_settings->save($settings->withLastRun('queued', 'Scheduled backup queued.', $run_at));
        $this->events->scheduleDueEvent($settings, $scheduled_for ?? time());
        $this->events->scheduleContinuation();
    }

    public function handleContinuationEvent(): void
    {
        $job = $this->scheduledRunningBackup();
        if ($job === null) {
            return;
        }

        for ($i = 0; $i < self::MAX_STEPS_PER_TICK; $i++) {
            $job_id = $job->id();
            $owner_token = $this->lock->acquire($job_id);
            if ($owner_token === null) {
                $this->events->scheduleContinuation();
                return;
            }

            try {
                $this->refreshJobs();
                $job = $this->jobs->find($job_id);
                if ($job !== null && $this->isScheduledRunningJob($job)) {
                    $job = $this->step_runner->runStep($job);
                    $this->jobs->save($job);
                }
            } finally {
                $this->releaseLock($job_id, $owner_token);
            }

            if ($job === null) {
                return;
            }

            if ($job->state() === Job::COMPLETED) {
                $this->recordLastRun('completed', 'Scheduled backup completed.');
                return;
            }

            if ($job->state() === Job::FAILED) {
                $this->recordLastRun('failed', 'Scheduled backup failed.');
                return;
            }

            if (!$this->isScheduledRunningJob($job)) {
                return;
            }
        }

        $this->events->scheduleContinuation();
    }

    private function queueScheduledBackup(ScheduleSettings $settings, int $scheduled_for): void
    {
        $metadata = $this->metadata_collector->collect();
        $backup_settings = $this->backup_settings->get();
        $options = new BackupOptions(
            $this->site_root,
            $this->backup_directory,
            isset($metadata['table_prefix']) ? (string) $metadata['table_prefix'] : '',
            'prefixed',
            5000,
            $metadata
        );
        $job_id = 'backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $working_directory = rtrim($options->workingBaseDirectory(), '/\\') . '/' . $job_id;

        $this->jobs->save(new Job($job_id, 'backup', Job::CREATED, array(
            'trigger' => 'scheduled',
            'schedule_frequency' => $settings->frequency(),
            'scheduled_for' => gmdate('c', $scheduled_for),
            'site_root' => $options->siteRoot(),
            'working_directory' => $working_directory,
            'table_prefix' => $options->tablePrefix(),
            'table_selection_mode' => $options->tableSelectionMode(),
            'database_chunk_size' => $options->databaseChunkSize(),
            'manifest_metadata' => $options->manifestMetadata(),
            'backup_settings' => $backup_settings->toArray(),
            'message' => 'Scheduled backup queued.',
            'updated_at' => gmdate('c'),
        )));
    }

    private function recordLastRun(string $status, string $message): void
    {
        $settings = $this->schedule_settings->get();
        $this->schedule_settings->save($settings->withLastRun($status, $message, gmdate('c')));
    }

    private function runningBackup(): ?Job
    {
        foreach ($this->jobs->all() as $job) {
            if ($job->type() === 'backup' && $this->isRunningState($job->state())) {
                return $job;
            }
        }

        return null;
    }

    private function scheduledRunningBackup(): ?Job
    {
        foreach ($this->jobs->all() as $job) {
            $payload = $job->payload();
            if ($job->type() === 'backup' && isset($payload['trigger']) && $payload['trigger'] === 'scheduled' && $this->isRunningState($job->state())) {
                return $job;
            }
        }

        return null;
    }

    private function isRunningState(string $state): bool
    {
        return !in_array($state, array(Job::COMPLETED, Job::FAILED, Job::ROLLED_BACK), true);
    }

    private function isScheduledRunningJob(Job $job): bool
    {
        $payload = $job->payload();

        return $job->type() === 'backup'
            && isset($payload['trigger'])
            && $payload['trigger'] === 'scheduled'
            && $this->isRunningState($job->state());
    }

    private function releaseLock(string $job_id, string $owner_token): void
    {
        try {
            $this->lock->release($job_id, $owner_token);
        } catch (Throwable $throwable) {
            // The expiring lease permits recovery; preserve the backup result.
        }
    }

    private function refreshJobs(): void
    {
        if ($this->jobs instanceof RefreshableJobRepositoryInterface) {
            $this->jobs->refresh();
        }
    }
}
