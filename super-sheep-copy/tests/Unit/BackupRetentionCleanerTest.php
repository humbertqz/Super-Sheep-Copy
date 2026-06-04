<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupRetentionCleaner;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class BackupRetentionCleanerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-retention-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testKeepsNewestSuccessfulBackupsAndDeletesOlderArchives(): void
    {
        $jobs = new InMemoryJobRepositoryForRetention(array(
            $this->job('backup-old', 100),
            $this->job('backup-mid', 200),
            $this->job('backup-new', 300),
        ));

        (new BackupRetentionCleaner($jobs, $this->root))->clean(2);

        self::assertNull($jobs->find('backup-old'));
        self::assertNotNull($jobs->find('backup-mid'));
        self::assertNotNull($jobs->find('backup-new'));
        self::assertFileDoesNotExist($this->root . '/backup-old.zip');
        self::assertFileExists($this->root . '/backup-mid.zip');
        self::assertFileExists($this->root . '/backup-new.zip');
    }

    public function testDoesNotDeleteFailedOrRunningJobs(): void
    {
        $failed = new Job('backup-failed', 'backup', Job::FAILED, array(
            'backup_completed_at' => 50,
            'archive_path' => $this->archive('backup-failed'),
        ));
        $jobs = new InMemoryJobRepositoryForRetention(array($failed, $this->job('backup-new', 300)));

        (new BackupRetentionCleaner($jobs, $this->root))->clean(1);

        self::assertNotNull($jobs->find('backup-failed'));
        self::assertFileExists($this->root . '/backup-failed.zip');
    }

    private function job(string $id, int $completed_at): Job
    {
        return new Job($id, 'backup', Job::COMPLETED, array(
            'backup_completed_at' => $completed_at,
            'archive_path' => $this->archive($id),
        ));
    }

    private function archive(string $id): string
    {
        $path = $this->root . '/' . $id . '.zip';
        file_put_contents($path, 'archive');

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $item) {
            $child = $path . '/' . $item;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}

final class InMemoryJobRepositoryForRetention implements JobRepositoryInterface
{
    /** @var array<string,Job> */
    private array $jobs = array();

    /**
     * @param Job[] $jobs
     */
    public function __construct(array $jobs)
    {
        foreach ($jobs as $job) {
            $this->jobs[$job->id()] = $job;
        }
    }

    public function save(Job $job): void
    {
        $this->jobs[$job->id()] = $job;
    }

    public function find(string $id): ?Job
    {
        return $this->jobs[$id] ?? null;
    }

    public function delete(string $id): void
    {
        unset($this->jobs[$id]);
    }

    public function all(): array
    {
        return array_values($this->jobs);
    }
}
