<?php
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- AJAX request is verified by Nonce::verifyRequest() before request values are consumed.

declare(strict_types=1);

namespace SuperSheepCopy\Admin;

use SuperSheepCopy\Backup\BackupStepRunnerInterface;
use SuperSheepCopy\Backup\BackupJobSiteGuard;
use SuperSheepCopy\Backup\Lock\BackupJobExecutionLockInterface;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Plugin;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use Throwable;

final class BackupStepAjaxHandler
{
    private Capability $capability;
    private Nonce $nonce;
    private JobRepositoryInterface $jobs;
    private BackupStepRunnerInterface $runner;
    private BackupJobExecutionLockInterface $lock;

    public function __construct(
        Capability $capability,
        Nonce $nonce,
        JobRepositoryInterface $jobs,
        BackupStepRunnerInterface $runner,
        BackupJobExecutionLockInterface $lock
    ) {
        $this->capability = $capability;
        $this->nonce = $nonce;
        $this->jobs = $jobs;
        $this->runner = $runner;
        $this->lock = $lock;
    }

    public function handle(): void
    {
        $this->capability->requireManageBackups();
        $this->nonce->verifyRequest();

        $job_id = isset($_REQUEST['job_id']) ? sanitize_text_field(wp_unslash($_REQUEST['job_id'])) : '';
        $job = $this->jobs->find($job_id);

        if ($job === null) {
            wp_send_json_error(array('job_id' => $job_id), 404);
        }

        if ((new BackupJobSiteGuard())->isForeignRunningBackupJob($job, defined('ABSPATH') ? ABSPATH : '', Plugin::backupDirectory())) {
            $payload = $job->payload();
            $payload['message'] = 'Backup failed: job belongs to a different site or upload directory.';
            $payload['error'] = 'Job belongs to a different site or upload directory.';
            $payload['failed_state'] = $job->state();
            $job = new Job($job->id(), $job->type(), Job::FAILED, $payload);
            $this->jobs->save($job);
            wp_send_json_success($this->responsePayload($job));
        }

        $owner_token = $this->lock->acquire($job->id());
        if ($owner_token === null) {
            $payload = $job->payload();
            $payload['message'] = 'Another backup step is still running.';
            $busy_job = new Job($job->id(), $job->type(), $job->state(), $payload);
            wp_send_json_success($this->responsePayload($busy_job));
        }

        try {
            $retry = isset($_REQUEST['retry']) && sanitize_text_field(wp_unslash($_REQUEST['retry'])) === '1';
            if ($retry && $job->state() === Job::FAILED) {
                $payload = $job->payload();
                $failed_state = isset($payload['failed_state']) && is_scalar($payload['failed_state']) ? (string) $payload['failed_state'] : Job::CREATED;
                unset($payload['error']);
                $payload['message'] = 'Retrying backup.';
                $job = new Job($job->id(), $job->type(), $failed_state, $payload);
            }

            try {
                $job = $this->runner->runStep($job);
            } catch (Throwable $throwable) {
                $payload = $job->payload();
                $payload['message'] = 'Backup failed: ' . $throwable->getMessage();
                $payload['error'] = $throwable->getMessage();
                $payload['failed_state'] = $job->state();
                $job = new Job($job->id(), $job->type(), Job::FAILED, $payload);
                $this->jobs->save($job);
            }
        } finally {
            $this->releaseLock($job->id(), $owner_token);
        }

        wp_send_json_success($this->responsePayload($job));
    }

    /**
     * @return array<string,string>
     */
    private function responsePayload(Job $job): array
    {
        $payload = $job->payload();
        $message = isset($payload['message']) && is_scalar($payload['message']) ? (string) $payload['message'] : '';

        return array(
            'job_id' => $job->id(),
            'state' => $job->state(),
            'message' => $message,
            'status' => $this->statusForState($job->state()),
        );
    }

    private function statusForState(string $state): string
    {
        if ($state === Job::COMPLETED || $state === Job::FAILED || $state === Job::ROLLED_BACK) {
            return $state;
        }

        return 'queued';
    }

    private function releaseLock(string $job_id, string $owner_token): void
    {
        try {
            $this->lock->release($job_id, $owner_token);
        } catch (Throwable $throwable) {
            // The expiring lease permits recovery; preserve the backup result.
        }
    }
}
