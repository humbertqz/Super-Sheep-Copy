<?php
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- AJAX request is verified by Nonce::verifyRequest() before request values are consumed.

declare(strict_types=1);

namespace SuperSheepCopy\Admin;

use SuperSheepCopy\Backup\BackupFailure;
use SuperSheepCopy\Backup\BackupJobSiteGuard;
use SuperSheepCopy\Backup\BackupStepRunnerInterface;
use SuperSheepCopy\Backup\Lock\BackupJobExecutionLockInterface;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Jobs\RefreshableJobRepositoryInterface;
use SuperSheepCopy\Plugin;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Support\LoggerInterface;
use SuperSheepCopy\Support\NullLogger;
use Throwable;

final class BackupStepAjaxHandler
{
    private Capability $capability;
    private Nonce $nonce;
    private JobRepositoryInterface $jobs;
    private BackupStepRunnerInterface $runner;
    private BackupJobExecutionLockInterface $lock;
    private LoggerInterface $logger;

    public function __construct(
        Capability $capability,
        Nonce $nonce,
        JobRepositoryInterface $jobs,
        BackupStepRunnerInterface $runner,
        BackupJobExecutionLockInterface $lock,
        ?LoggerInterface $logger = null
    ) {
        $this->capability = $capability;
        $this->nonce = $nonce;
        $this->jobs = $jobs;
        $this->runner = $runner;
        $this->lock = $lock;
        $this->logger = $logger !== null ? $logger : new NullLogger();
    }

    public function handle(): void
    {
        $this->capability->requireManageBackups();
        $this->nonce->verifyRequest();

        $job_id = isset($_REQUEST['job_id']) ? sanitize_text_field(wp_unslash($_REQUEST['job_id'])) : '';
        try {
            $owner_token = $this->lock->acquire($job_id);
        } catch (Throwable $throwable) {
            $this->logger->error('Backup step lock acquisition failed.', $this->exceptionContext($throwable, array(
                'job_id' => $job_id,
                'error_code' => 'BACKUP_REQUEST_HTTP_500',
            )));
            wp_send_json_error(array(
                'job_id' => $job_id,
                'message' => __('The backup step could not start. Try again.', 'super-sheep-copy'),
            ), 500);
            return;
        }
        if ($owner_token === null) {
            $this->refreshJobs();
            $job = $this->jobs->find($job_id);
            if ($job === null) {
                wp_send_json_error(array('job_id' => $job_id, 'message' => 'Backup job was not found.'), 404);
                return;
            }

            $payload = $job->payload();
            $payload['message'] = 'Another backup step is still running.';
            $busy_job = new Job($job->id(), $job->type(), $job->state(), $payload);
            wp_send_json_success($this->responsePayload($busy_job));
            return;
        }

        $job = null;
        $request_error = null;
        try {
            $this->refreshJobs();
            $job = $this->jobs->find($job_id);
            if ($job !== null && (new BackupJobSiteGuard())->isForeignRunningBackupJob($job, defined('ABSPATH') ? ABSPATH : '', Plugin::backupDirectory())) {
                $payload = $job->payload();
                $payload['message'] = 'Backup failed: job belongs to a different site or upload directory.';
                $payload['error'] = 'Job belongs to a different site or upload directory.';
                $payload['failed_state'] = $job->state();
                $payload['updated_at'] = gmdate('c');
                $job = new Job($job->id(), $job->type(), Job::FAILED, $payload);
                $this->jobs->save($job);
                $this->logger->error('Backup job stopped.', BackupFailure::context($job) + array(
                    'error' => $payload['error'],
                ));
            } elseif ($job !== null) {
                $retry = isset($_REQUEST['retry']) && sanitize_text_field(wp_unslash($_REQUEST['retry'])) === '1';
                if ($retry && $job->state() === Job::FAILED) {
                    $payload = $job->payload();
                    $failed_state = isset($payload['failed_state']) && is_scalar($payload['failed_state']) ? (string) $payload['failed_state'] : Job::CREATED;
                    unset($payload['error']);
                    $payload['message'] = 'Retrying backup.';
                    $job = new Job($job->id(), $job->type(), $failed_state, $payload);
                }

                $job = $this->runner->runStep($job);
                if ($job->state() === Job::FAILED) {
                    $payload = $job->payload();
                    $this->logger->error('Backup step failed.', BackupFailure::context($job) + array(
                        'error' => isset($payload['error']) && is_scalar($payload['error']) ? (string) $payload['error'] : (isset($payload['message']) ? (string) $payload['message'] : ''),
                    ));
                }
            }
        } catch (Throwable $throwable) {
            $request_error = $throwable;
            $context = array('job_id' => $job_id, 'error_code' => 'BACKUP_REQUEST_HTTP_500');
            if ($job instanceof Job) {
                $payload = $job->payload();
                $payload['error'] = $throwable->getMessage();
                $payload['failed_state'] = $job->state();
                $payload['updated_at'] = gmdate('c');
                $job = new Job($job->id(), $job->type(), Job::FAILED, $payload);
                $context = BackupFailure::context($job);
                try {
                    $this->jobs->save($job);
                } catch (Throwable $save_error) {
                    $context['save_error'] = $save_error->getMessage();
                }
            }
            $this->logger->error('Backup step failed.', $this->exceptionContext($throwable, $context));
        } finally {
            $this->releaseLock($job_id, $owner_token);
        }

        if ($job === null) {
            $status_code = $request_error instanceof Throwable ? 500 : 404;
            $message = $request_error instanceof Throwable
                ? __('The backup step could not start. Try again.', 'super-sheep-copy')
                : 'Backup job was not found.';
            wp_send_json_error(array('job_id' => $job_id, 'message' => $message), $status_code);
            return;
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

        $response = array(
            'job_id' => $job->id(),
            'state' => $job->state(),
            'message' => $message,
            'status' => $this->statusForState($job->state()),
        );
        if ($job->state() === Job::FAILED) {
            $failure = BackupFailure::context($job);
            $response['message'] = BackupFailure::message($failure['error_code']);
            $response['error_report'] = BackupFailure::report($failure);
        }

        return $response;
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
            $this->logger->warning('Backup step lock release failed.', $this->exceptionContext($throwable, array('job_id' => $job_id)));
        }
    }

    private function refreshJobs(): void
    {
        if ($this->jobs instanceof RefreshableJobRepositoryInterface) {
            $this->jobs->refresh();
        }
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
}
