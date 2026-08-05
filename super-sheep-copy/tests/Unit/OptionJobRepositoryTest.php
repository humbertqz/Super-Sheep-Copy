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
}
