<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupMetadataCollectorInterface;
use SuperSheepCopy\Backup\BackupStepRunnerInterface;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Schedule\ScheduleSettings;
use SuperSheepCopy\Schedule\ScheduleSettingsRepository;
use SuperSheepCopy\Schedule\ScheduledBackupRunner;
use SuperSheepCopy\Settings\BackupSettings;
use SuperSheepCopy\Settings\BackupSettingsRepository;

final class ScheduledBackupRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ssc_test_scheduled_events'] = array();
    }

    public function testDueEventQueuesScheduledBackupAndSchedulesContinuation(): void
    {
        $jobs = new ScheduleRunnerJobRepository();
        $schedule_repository = new ScheduleSettingsRepository();
        $schedule_repository->save(ScheduleSettings::fromArray(array(
            'enabled' => true,
            'frequency' => 'daily',
            'time_of_day' => '02:00',
        )));
        (new BackupSettingsRepository())->save(BackupSettings::fromArray(array('retention_count' => 1)));
        $runner = $this->runner($jobs, new ScheduleRunnerStepRunner());

        $runner->handleDueEvent(strtotime('2026-06-12 02:00:00 UTC'));

        $queued = $jobs->all()[0] ?? null;
        self::assertInstanceOf(Job::class, $queued);
        self::assertSame('backup', $queued->type());
        self::assertSame(Job::CREATED, $queued->state());
        self::assertSame('scheduled', $queued->payload()['trigger']);
        self::assertSame(1, $queued->payload()['backup_settings']['retention_count']);
        self::assertArrayHasKey('super_sheep_copy_scheduled_backup_continue', $GLOBALS['ssc_test_scheduled_events']);
    }

    public function testDueEventSkipsWhenBackupAlreadyRunning(): void
    {
        $jobs = new ScheduleRunnerJobRepository(array(
            new Job('backup-running', 'backup', Job::EXPORTING_DATABASE, array()),
        ));
        $schedule_repository = new ScheduleSettingsRepository();
        $schedule_repository->save(ScheduleSettings::fromArray(array('enabled' => true)));

        $this->runner($jobs, new ScheduleRunnerStepRunner())->handleDueEvent(strtotime('2026-06-12 02:00:00 UTC'));

        self::assertCount(1, $jobs->all());
        $settings = $schedule_repository->get();
        self::assertSame('skipped', $settings->lastStatus());
        self::assertStringContainsString('already running', $settings->lastMessage());
    }

    public function testContinuationAdvancesScheduledJobAndRecordsCompletion(): void
    {
        $job = new Job('backup-scheduled', 'backup', Job::CREATED, array('trigger' => 'scheduled'));
        $jobs = new ScheduleRunnerJobRepository(array($job));
        $step_runner = new ScheduleRunnerStepRunner(Job::COMPLETED);
        (new ScheduleSettingsRepository())->save(ScheduleSettings::fromArray(array('enabled' => true)));

        $this->runner($jobs, $step_runner)->handleContinuationEvent();

        self::assertSame(Job::COMPLETED, $jobs->find('backup-scheduled')->state());
        self::assertSame('completed', (new ScheduleSettingsRepository())->get()->lastStatus());
        self::assertSame(1, $step_runner->calls);
    }

    public function testContinuationSchedulesAnotherTickWhenJobIsStillRunning(): void
    {
        $job = new Job('backup-scheduled', 'backup', Job::CREATED, array('trigger' => 'scheduled'));
        $jobs = new ScheduleRunnerJobRepository(array($job));
        $step_runner = new ScheduleRunnerStepRunner(Job::EXPORTING_DATABASE);
        (new ScheduleSettingsRepository())->save(ScheduleSettings::fromArray(array('enabled' => true)));

        $this->runner($jobs, $step_runner)->handleContinuationEvent();

        self::assertSame(Job::EXPORTING_DATABASE, $jobs->find('backup-scheduled')->state());
        self::assertSame(3, $step_runner->calls);
        self::assertArrayHasKey('super_sheep_copy_scheduled_backup_continue', $GLOBALS['ssc_test_scheduled_events']);
    }

    private function runner(ScheduleRunnerJobRepository $jobs, ScheduleRunnerStepRunner $step_runner): ScheduledBackupRunner
    {
        return new ScheduledBackupRunner(
            $jobs,
            new ScheduleSettingsRepository(),
            new BackupSettingsRepository(),
            new ScheduleRunnerMetadataCollector(),
            $step_runner,
            '/tmp/ssc-site',
            sys_get_temp_dir() . '/ssc-test-uploads/super-sheep-copy'
        );
    }
}

final class ScheduleRunnerJobRepository implements JobRepositoryInterface
{
    /** @var array<string,Job> */
    private array $jobs = array();

    /**
     * @param Job[] $jobs
     */
    public function __construct(array $jobs = array())
    {
        foreach ($jobs as $job) {
            $this->jobs[$job->id()] = $job;
        }
    }

    public function save(Job $job): void
    {
        $this->jobs[$job->id()] = $job;
    }

    public function delete(string $id): void
    {
        unset($this->jobs[$id]);
    }

    public function find(string $id): ?Job
    {
        return $this->jobs[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->jobs);
    }
}

final class ScheduleRunnerMetadataCollector implements BackupMetadataCollectorInterface
{
    public function collect(): array
    {
        return array('table_prefix' => 'wp_');
    }
}

final class ScheduleRunnerStepRunner implements BackupStepRunnerInterface
{
    public int $calls = 0;
    private string $next_state;

    public function __construct(string $next_state = Job::EXPORTING_DATABASE)
    {
        $this->next_state = $next_state;
    }

    public function runStep(Job $job): Job
    {
        $this->calls++;
        $payload = $job->payload();
        $payload['updated_at'] = gmdate('c');

        return new Job($job->id(), $job->type(), $this->next_state, $payload);
    }
}
