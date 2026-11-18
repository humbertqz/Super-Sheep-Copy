<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\OptionJobRepository;

final class OptionJobRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ssc_test_options'] = array();
        $GLOBALS['ssc_test_cache_deletes'] = array();
        $GLOBALS['wpdb'] = new \SscTestJobOptionsWpdb();
    }

    public function testDeletesJobById(): void
    {
        $repository = new OptionJobRepository();
        $repository->save(new Job('backup-123', 'backup', Job::COMPLETED));
        $repository->save(new Job('restore-123', 'restore', Job::CREATED));

        $repository->delete('backup-123');

        self::assertNull($repository->find('backup-123'));
        self::assertInstanceOf(Job::class, $repository->find('restore-123'));
        self::assertSame(array('restore-123'), array_map(static function (Job $job): string {
            return $job->id();
        }, $repository->all()));
    }

    public function testRefreshInvalidatesWordPressOptionCache(): void
    {
        $repository = new OptionJobRepository();

        $repository->refresh();

        self::assertSame(array(
            array('super_sheep_copy_jobs', 'options'),
        ), $GLOBALS['ssc_test_cache_deletes']);
    }

    public function testLegacyJobsCanBeReadUpdatedAndDeletedIndependently(): void
    {
        $backup = new Job('legacy-backup', 'backup');
        $restore = new Job('legacy-restore', 'restore', Job::COMPLETED);
        $legacy = array($backup->id() => $backup->toArray(), $restore->id() => $restore->toArray());
        update_option('super_sheep_copy_jobs', $legacy);

        $repository = new OptionJobRepository();
        self::assertSame(Job::CREATED, $repository->find($backup->id())->state());
        $repository->save(new Job($backup->id(), 'backup', Job::EXPORTING_DATABASE));
        $repository->delete($restore->id());

        $fresh_repository = new OptionJobRepository();
        self::assertSame(Job::EXPORTING_DATABASE, $fresh_repository->find($backup->id())->state());
        self::assertNull($fresh_repository->find($restore->id()));
        self::assertCount(1, $fresh_repository->all());
        self::assertSame($legacy, get_option('super_sheep_copy_jobs'));
    }

    public function testFailedWriteIsReportedWhenCheckpointDidNotChange(): void
    {
        $repository = new OptionJobRepository();
        $job = new Job('backup-123', 'backup');
        $repository->save($job);

        $GLOBALS['ssc_test_reject_job_updates'] = true;
        try {
            $repository->save($job);
            $this->expectExceptionMessage('Unable to save the job state.');
            $repository->save(new Job($job->id(), 'backup', Job::SCANNING_FILES));
        } finally {
            unset($GLOBALS['ssc_test_reject_job_updates']);
            self::assertSame(Job::CREATED, $repository->find($job->id())->state());
        }
    }
}
