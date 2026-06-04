<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperSheepCopy\Admin\BackupDownloadHandler;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;

final class BackupDownloadHandlerTest extends TestCase
{
    private ?string $root = null;

    protected function setUp(): void
    {
        $_REQUEST = array(Nonce::FIELD => 'test-nonce');
        $GLOBALS['ssc_test_current_user_can'] = true;
        $GLOBALS['ssc_test_nonce_valid'] = true;
    }

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testPreparesCompletedArchiveDownloadInsideBackupDirectory(): void
    {
        $backup_directory = $this->makeDirectory('backups/super-sheep-copy');
        $working_directory = $this->makeDirectory('backups/super-sheep-copy/backup-123');
        $archive_path = $working_directory . '/backup-123.zip';
        file_put_contents($archive_path, 'zip');

        $handler = new BackupDownloadHandler(
            new Capability(),
            new Nonce(),
            new BackupDownloadJobRepository(array(new Job('backup-123', 'backup', Job::COMPLETED, array('archive_path' => $archive_path)))),
            $backup_directory
        );

        $download = $handler->prepare('backup-123');

        self::assertSame(realpath($archive_path), $download['path']);
        self::assertSame('backup-123.zip', $download['filename']);
        self::assertSame(3, $download['size']);
    }

    public function testRejectsArchiveOutsideBackupDirectory(): void
    {
        $backup_directory = $this->makeDirectory('backups/super-sheep-copy');
        $outside_directory = $this->makeDirectory('outside');
        $archive_path = $outside_directory . '/backup-123.zip';
        file_put_contents($archive_path, 'zip');

        $handler = new BackupDownloadHandler(
            new Capability(),
            new Nonce(),
            new BackupDownloadJobRepository(array(new Job('backup-123', 'backup', Job::COMPLETED, array('archive_path' => $archive_path)))),
            $backup_directory
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup archive is outside the protected backup directory.');

        $handler->prepare('backup-123');
    }

    public function testRejectsDownloadWithoutValidNonce(): void
    {
        $GLOBALS['ssc_test_nonce_valid'] = false;

        $handler = new BackupDownloadHandler(
            new Capability(),
            new Nonce(),
            new BackupDownloadJobRepository(array()),
            $this->makeDirectory('backups/super-sheep-copy')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Super Sheep Copy nonce.');

        $handler->prepare('backup-123');
    }

    public function testRejectsDownloadWithoutCapability(): void
    {
        $GLOBALS['ssc_test_current_user_can'] = false;

        $handler = new BackupDownloadHandler(
            new Capability(),
            new Nonce(),
            new BackupDownloadJobRepository(array()),
            $this->makeDirectory('backups/super-sheep-copy')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current user cannot manage Super Sheep Copy backups.');

        $handler->prepare('backup-123');
    }

    private function makeDirectory(string $path): string
    {
        $directory = $this->root() . '/' . $path;
        mkdir($directory, 0777, true);

        return $directory;
    }

    private function root(): string
    {
        if ($this->root === null) {
            $this->root = sys_get_temp_dir() . '/ssc-download-test-' . bin2hex(random_bytes(6));
            mkdir($this->root, 0777, true);
        }

        return $this->root;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: array(), array('.', '..')) as $item) {
            $path = $directory . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}

final class BackupDownloadJobRepository implements JobRepositoryInterface
{
    /** @var array<string,Job> */
    private array $jobs = array();

    /**
     * @param list<Job> $jobs
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
