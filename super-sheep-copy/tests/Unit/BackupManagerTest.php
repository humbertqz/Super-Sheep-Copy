<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\ArchivePackageResult;
use SuperSheepCopy\Backup\BackupArchivePackagerInterface;
use SuperSheepCopy\Backup\BackupManager;
use SuperSheepCopy\Backup\BackupOptions;
use SuperSheepCopy\Backup\BackupProgressReporterInterface;
use SuperSheepCopy\Backup\Database\DatabaseBackupCoordinatorInterface;
use SuperSheepCopy\Backup\FileScanner;
use SuperSheepCopy\Backup\ScannedFile;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class BackupManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-backup-manager-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/site/wp-content/uploads', 0777, true);
        mkdir($this->root . '/working', 0777, true);
        file_put_contents($this->root . '/site/index.php', '<?php echo "site";');
        file_put_contents($this->root . '/site/wp-content/uploads/image.txt', 'image');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRunsBackupWorkflow(): void
    {
        $jobs = new MemoryJobRepository();
        $database = new FakeDatabaseBackupCoordinator();
        $packager = new FakeBackupArchivePackager();
        $reporter = new FakeBackupProgressReporter();
        $manager = new BackupManager($jobs, $database, new FileScanner(), $packager, $reporter);

        $result = $manager->run(new BackupOptions($this->root . '/site', $this->root . '/working', 'wp_', 'prefixed', 100, array('source_site_url' => 'https://example.com')));

        self::assertSame(Job::COMPLETED, $result->state());
        self::assertDirectoryExists($result->workingDirectory());
        self::assertSame($result->workingDirectory() . '/database', $result->databaseDirectory());
        self::assertSame($result->workingDirectory() . '/backup.zip', $result->archivePath());
        self::assertSame(4096, $result->archiveSize());
        self::assertSame(2, $result->scannedFileCount());
        self::assertSame(1, $result->databaseFileCount());
        self::assertSame(array(Job::CREATED, Job::EXPORTING_DATABASE, Job::SCANNING_FILES, Job::PACKAGING_ARCHIVE, Job::COMPLETED), $jobs->states());
        self::assertSame($result->workingDirectory(), $database->workingDirectory());
        self::assertSame('wp_', $database->tablePrefix());
        self::assertSame('prefixed', $database->selectionMode());
        self::assertSame(100, $database->chunkSize());
        self::assertSame($result->jobId(), $packager->jobId());
        self::assertSame($result->workingDirectory(), $packager->workingDirectory());
        self::assertSame($result->databaseDirectory(), $packager->databaseDirectory());
        self::assertCount(2, $packager->siteFiles());
        self::assertSame(array('source_site_url' => 'https://example.com'), $packager->metadata());
        self::assertSame(array(
            'created',
            'database_started',
            'database_finished',
            'file_scan_started',
            'file_scan_finished',
            'archive_package_started',
            'archive_package_finished',
            'completed',
        ), $reporter->steps());

        $completed = $jobs->find($result->jobId());
        self::assertInstanceOf(Job::class, $completed);
        self::assertSame(2, $completed->payload()['scanned_file_count']);
        self::assertSame(1, $completed->payload()['database_file_count']);
        self::assertSame($result->archivePath(), $completed->payload()['archive_path']);
        self::assertSame(4096, $completed->payload()['archive_size']);
        self::assertSame('directory', $completed->payload()['package_format']);
        self::assertSame('', $completed->payload()['package_extension']);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }
            unlink($child);
        }
        rmdir($path);
    }
}

final class FakeBackupProgressReporter implements BackupProgressReporterInterface
{
    /** @var string[] */
    private array $steps = array();

    /**
     * @param array<string, mixed> $payload
     */
    public function report(string $job_id, string $state, array $payload): void
    {
        $this->steps[] = $payload['step'];
    }

    /**
     * @return string[]
     */
    public function steps(): array
    {
        return $this->steps;
    }
}

final class FakeBackupArchivePackager implements BackupArchivePackagerInterface
{
    private string $job_id = '';
    private string $working_directory = '';
    private string $database_directory = '';
    /** @var ScannedFile[] */
    private array $site_files = array();
    /** @var array<string,mixed> */
    private array $metadata = array();

    public function package(string $job_id, string $working_directory, string $database_directory, array $site_files, array $metadata): ArchivePackageResult
    {
        $this->job_id = $job_id;
        $this->working_directory = $working_directory;
        $this->database_directory = $database_directory;
        $this->site_files = $site_files;
        $this->metadata = $metadata;

        return new ArchivePackageResult($working_directory . '/backup.zip', 4096, count($site_files), 1, array('database/tables.json' => 'hash123'), 'directory', '');
    }

    public function jobId(): string
    {
        return $this->job_id;
    }

    public function workingDirectory(): string
    {
        return $this->working_directory;
    }

    public function databaseDirectory(): string
    {
        return $this->database_directory;
    }

    /**
     * @return ScannedFile[]
     */
    public function siteFiles(): array
    {
        return $this->site_files;
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}

final class FakeDatabaseBackupCoordinator implements DatabaseBackupCoordinatorInterface
{
    private string $working_directory = '';
    private string $table_prefix = '';
    private string $selection_mode = '';
    private int $chunk_size = 0;

    public function export(string $working_directory, string $table_prefix, string $selection_mode, int $chunk_size, ?string $job_id = null): void
    {
        $this->working_directory = $working_directory;
        $this->table_prefix = $table_prefix;
        $this->selection_mode = $selection_mode;
        $this->chunk_size = $chunk_size;
        mkdir($working_directory . '/database', 0777, true);
    }

    public function workingDirectory(): string
    {
        return $this->working_directory;
    }

    public function tablePrefix(): string
    {
        return $this->table_prefix;
    }

    public function selectionMode(): string
    {
        return $this->selection_mode;
    }

    public function chunkSize(): int
    {
        return $this->chunk_size;
    }
}

final class MemoryJobRepository implements JobRepositoryInterface
{
    /** @var array<string, Job> */
    private array $jobs = array();
    /** @var string[] */
    private array $states = array();

    public function save(Job $job): void
    {
        $this->jobs[$job->id()] = $job;
        $this->states[] = $job->state();
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

    /**
     * @return string[]
     */
    public function states(): array
    {
        return $this->states;
    }
}
