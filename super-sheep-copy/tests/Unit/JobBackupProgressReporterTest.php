<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\JobBackupProgressReporter;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class JobBackupProgressReporterTest extends TestCase
{
    public function testReportsProgressIntoExistingJobPayload(): void
    {
        $jobs = new ProgressReporterJobRepository();
        $jobs->save(new Job('backup-123', 'backup', Job::EXPORTING_DATABASE, array('working_directory' => '/tmp/work')));
        $reporter = new JobBackupProgressReporter($jobs);

        $reporter->report('backup-123', Job::EXPORTING_DATABASE, array(
            'phase' => 'database',
            'step' => 'table_started',
            'table' => 'wp_posts',
            'message' => 'Exporting table wp_posts',
        ));

        $job = $jobs->find('backup-123');
        self::assertInstanceOf(Job::class, $job);
        self::assertSame('backup', $job->type());
        self::assertSame(Job::EXPORTING_DATABASE, $job->state());
        self::assertSame('/tmp/work', $job->payload()['working_directory']);
        self::assertSame('database', $job->payload()['phase']);
        self::assertSame('table_started', $job->payload()['step']);
        self::assertSame('wp_posts', $job->payload()['table']);
        self::assertSame('Exporting table wp_posts', $job->payload()['message']);
        self::assertArrayHasKey('updated_at', $job->payload());
    }
}

final class ProgressReporterJobRepository implements JobRepositoryInterface
{
    /** @var array<string, Job> */
    private array $jobs = array();

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
