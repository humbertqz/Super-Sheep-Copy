<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupArchiveStepPackager;
use SuperSheepCopy\Backup\BackupArchiveStepPackagerInterface;
use SuperSheepCopy\Backup\BackupStepRunner;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\SqlDumpFormatter;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClientInterface;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;
use SuperSheepCopy\Backup\FileScanner;
use SuperSheepCopy\Backup\ManifestBuilder;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class BackupStepRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-step-runner-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/site', 0777, true);
        file_put_contents($this->root . '/site/index.php', '<?php');
        file_put_contents($this->root . '/site/readme.txt', 'readme');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExportsDatabaseOneChunkPerStepAndThenContinues(): void
    {
        $jobs = new BackupStepRunnerJobRepository();
        $runner = $this->runner($jobs);
        $job = new Job('backup-123', 'backup', Job::CREATED, $this->payload());

        $job = $runner->runStep($job);
        self::assertSame(Job::EXPORTING_DATABASE, $job->state());
        self::assertFileDoesNotExist($this->root . '/work/backup-123/database/chunks/wp_posts.part001.sql');

        $job = $runner->runStep($job);
        self::assertSame(Job::EXPORTING_DATABASE, $job->state());
        self::assertFileExists($this->root . '/work/backup-123/database/chunks/wp_posts.part001.sql');
        self::assertFileDoesNotExist($this->root . '/work/backup-123/database/chunks/wp_posts.part002.sql');

        $job = $runner->runStep($job);
        self::assertSame(Job::EXPORTING_DATABASE, $job->state());
        self::assertFileExists($this->root . '/work/backup-123/database/chunks/wp_posts.part002.sql');

        $job = $runner->runStep($job);
        self::assertSame(Job::SCANNING_FILES, $job->state());

        $manifest = json_decode((string) file_get_contents($this->root . '/work/backup-123/database/tables.json'), true);
        self::assertSame(array('wp_posts.part001.sql', 'wp_posts.part002.sql'), $manifest['tables'][0]['chunks']);
    }

    public function testDatabaseExportRecordsThroughputAndAdaptiveChunkSize(): void
    {
        $jobs = new BackupStepRunnerJobRepository();
        $runner = $this->runner($jobs);
        $job = new Job('backup-123', 'backup', Job::CREATED, $this->payload());

        $job = $runner->runStep($job);
        $job = $runner->runStep($job);

        self::assertArrayHasKey('database_last_step_seconds', $job->payload());
        self::assertSame(2, $job->payload()['database_last_step_rows']);
        self::assertGreaterThan(0, $job->payload()['database_rows_per_second']);
        self::assertArrayHasKey('database_adaptive_chunk_size', $job->payload());
        self::assertSame('database', $job->payload()['backup_bottleneck']);
    }

    public function testDatabaseExportKeepsConfiguredChunkSizeForOffsetTables(): void
    {
        $jobs = new BackupStepRunnerJobRepository();
        $runner = $this->runnerWithPackager($jobs, new BackupStepRunnerPackager(), 100, new BackupStepRunnerNoPrimaryKeyClient());
        $job = new Job('backup-123', 'backup', Job::CREATED, $this->payload());

        $job = $runner->runStep($job);
        $job = $runner->runStep($job);
        $job = $runner->runStep($job);

        self::assertSame(2, $job->payload()['database_adaptive_chunk_size']);
        self::assertFileExists($this->root . '/work/backup-123/database/chunks/wp_posts.part002.sql');
    }

    public function testCompletesFileScanAndPackagingInSeparateSteps(): void
    {
        $jobs = new BackupStepRunnerJobRepository();
        $runner = $this->runner($jobs);
        $payload = $this->payload();
        $payload['database_directory'] = $this->root . '/work/backup-123/database';
        mkdir($payload['database_directory'] . '/chunks', 0777, true);
        file_put_contents($payload['database_directory'] . '/tables.json', '{}');
        file_put_contents($payload['database_directory'] . '/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');
        $job = new Job('backup-123', 'backup', Job::SCANNING_FILES, $payload);

        $job = $runner->runStep($job);
        self::assertSame(Job::PACKAGING_ARCHIVE, $job->state(), (string) ($job->payload()['message'] ?? ''));
        self::assertSame(2, $job->payload()['scanned_file_count']);

        $job = $runner->runStep($job);
        self::assertSame(Job::COMPLETED, $job->state(), (string) ($job->payload()['message'] ?? ''));
        self::assertSame($this->root . '/work/backup-123/backup-123.zip', $job->payload()['archive_path']);
        self::assertArrayHasKey('backup_completed_at', $job->payload());
        self::assertArrayHasKey('backup_total_seconds', $job->payload());
        self::assertGreaterThanOrEqual(0, $job->payload()['backup_total_seconds']);
    }

    public function testCompletedBackupRunsRetentionCleanupFromPayloadSettings(): void
    {
        $jobs = new BackupStepRunnerJobRepository();
        $old_directory = $this->root . '/work/backup-old';
        mkdir($old_directory, 0777, true);
        $old_archive = $old_directory . '/backup-old.zip';
        file_put_contents($old_archive, 'old');
        $jobs->save(new Job('backup-old', 'backup', Job::COMPLETED, array(
            'working_directory' => $old_directory,
            'archive_path' => $old_archive,
            'backup_completed_at' => 1,
        )));

        $runner = $this->runner($jobs);
        $payload = $this->payload();
        $payload['backup_settings'] = array('retention_count' => 1);
        $payload['database_directory'] = $this->root . '/work/backup-123/database';
        mkdir($payload['database_directory'] . '/chunks', 0777, true);
        file_put_contents($payload['database_directory'] . '/tables.json', '{}');
        file_put_contents($payload['database_directory'] . '/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');
        $job = new Job('backup-123', 'backup', Job::SCANNING_FILES, $payload);

        $job = $runner->runStep($job);
        $job = $runner->runStep($job);

        self::assertSame(Job::COMPLETED, $job->state());
        self::assertNull($jobs->find('backup-old'));
        self::assertFileDoesNotExist($old_archive);
        self::assertNotNull($jobs->find('backup-123'));
    }

    public function testScanningFilesStateCanContinueAcrossMultipleSteps(): void
    {
        $jobs = new BackupStepRunnerJobRepository();
        $runner = $this->runnerWithPackager($jobs, new BackupStepRunnerPackager(), 1);
        $payload = $this->payload();
        $payload['database_directory'] = $this->root . '/work/backup-123/database';
        mkdir($payload['database_directory'] . '/chunks', 0777, true);
        file_put_contents($payload['database_directory'] . '/tables.json', '{}');
        file_put_contents($payload['database_directory'] . '/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');
        $job = new Job('backup-123', 'backup', Job::SCANNING_FILES, $payload);

        $job = $runner->runStep($job);
        self::assertSame(Job::SCANNING_FILES, $job->state());
        self::assertSame(1, $job->payload()['scanned_file_count']);

        $job = $runner->runStep($job);
        self::assertSame(Job::PACKAGING_ARCHIVE, $job->state(), (string) ($job->payload()['message'] ?? ''));
        self::assertSame(2, $job->payload()['scanned_file_count']);
        self::assertSame('File scan finished.', $job->payload()['message']);
    }

    public function testScanningFilesStoresFileListOutsideJobPayload(): void
    {
        $jobs = new BackupStepRunnerJobRepository();
        $runner = $this->runnerWithPackager($jobs, new BackupStepRunnerPackager(), 100);
        $payload = $this->payload();
        $payload['database_directory'] = $this->root . '/work/backup-123/database';
        mkdir($payload['database_directory'] . '/chunks', 0777, true);
        file_put_contents($payload['database_directory'] . '/tables.json', '{}');
        file_put_contents($payload['database_directory'] . '/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');

        $job = $runner->runStep(new Job('backup-123', 'backup', Job::SCANNING_FILES, $payload));

        self::assertSame(Job::PACKAGING_ARCHIVE, $job->state(), (string) ($job->payload()['message'] ?? ''));
        self::assertSame(2, $job->payload()['scanned_file_count']);
        self::assertArrayHasKey('scanned_files_path', $job->payload());
        self::assertFileExists($job->payload()['scanned_files_path']);
        self::assertArrayNotHasKey('scanned_files', $job->payload());
        self::assertStringContainsString('"relative_path":"index.php"', (string) file_get_contents($job->payload()['scanned_files_path']));
        self::assertStringContainsString('"relative_path":"readme.txt"', (string) file_get_contents($job->payload()['scanned_files_path']));
    }

    public function testFileScanRecordsThroughputAndAdaptiveBatchSize(): void
    {
        $jobs = new BackupStepRunnerJobRepository();
        $runner = $this->runnerWithPackager($jobs, new BackupStepRunnerPackager(), 1);
        $payload = $this->payload();
        $payload['database_directory'] = $this->root . '/work/backup-123/database';
        mkdir($payload['database_directory'] . '/chunks', 0777, true);
        file_put_contents($payload['database_directory'] . '/tables.json', '{}');
        file_put_contents($payload['database_directory'] . '/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');

        $job = $runner->runStep(new Job('backup-123', 'backup', Job::SCANNING_FILES, $payload));

        self::assertArrayHasKey('file_scan_last_step_seconds', $job->payload());
        self::assertSame(1, $job->payload()['file_scan_last_step_entries']);
        self::assertGreaterThan(0, $job->payload()['file_scan_entries_per_second']);
        self::assertArrayHasKey('file_scan_adaptive_batch_size', $job->payload());
        self::assertSame('file scan', $job->payload()['backup_bottleneck']);
    }

    public function testScanFilesUsesSettingsSnapshotFromPayload(): void
    {
        mkdir($this->root . '/site/wp-content/uploads', 0777, true);
        file_put_contents($this->root . '/site/wp-content/uploads/large.bin', str_repeat('x', 12));
        $payload = $this->payload();
        $payload['database_directory'] = $this->root . '/work/backup-123/database';
        $payload['backup_settings'] = array(
            'exclude_cache_files' => true,
            'skip_large_files' => true,
            'large_file_limit_mb' => 0,
        );
        mkdir($payload['database_directory'] . '/chunks', 0777, true);
        file_put_contents($payload['database_directory'] . '/tables.json', '{}');
        file_put_contents($payload['database_directory'] . '/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');

        $runner = $this->runnerWithPackager(new BackupStepRunnerJobRepository(), new BackupStepRunnerPackager(), 100);
        $job = new Job('backup-123', 'backup', Job::SCANNING_FILES, $payload);
        do {
            $job = $runner->runStep($job);
        } while ($job->state() === Job::SCANNING_FILES);

        self::assertSame(3, $job->payload()['skipped_large_file_count']);
    }

    public function testFailurePayloadStoresFailedStateForRetry(): void
    {
        $jobs = new BackupStepRunnerJobRepository();
        $payload = $this->payload();
        unset($payload['database_directory']);
        $runner = $this->runnerWithPackager($jobs, new BackupStepRunnerPackager());

        $job = $runner->runStep(new Job('backup-123', 'backup', Job::PACKAGING_ARCHIVE, $payload));

        self::assertSame(Job::FAILED, $job->state());
        self::assertSame(Job::PACKAGING_ARCHIVE, $job->payload()['failed_state']);
    }

    public function testPackagingArchiveStateCanContinueAcrossMultipleSteps(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $jobs = new BackupStepRunnerJobRepository();
        $runner = $this->runnerWithPackager($jobs, new BackupArchiveStepPackager(new ManifestBuilder('0.1.0', '1'), 1));
        $payload = $this->payload();
        $payload['database_directory'] = $this->root . '/work/backup-123/database';
        $payload['scanned_files_path'] = $this->root . '/work/backup-123/files.jsonl';
        $payload['scanned_file_count'] = 2;
        mkdir($payload['database_directory'] . '/chunks', 0777, true);
        file_put_contents($payload['database_directory'] . '/tables.json', '{}');
        file_put_contents($payload['database_directory'] . '/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');
        file_put_contents($payload['scanned_files_path'], implode("\n", array(
            json_encode(array('absolute_path' => $this->root . '/site/index.php', 'relative_path' => 'index.php', 'size' => 5, 'symlink' => false)),
            json_encode(array('absolute_path' => $this->root . '/site/readme.txt', 'relative_path' => 'readme.txt', 'size' => 6, 'symlink' => false)),
        )) . "\n");

        $job = new Job('backup-123', 'backup', Job::PACKAGING_ARCHIVE, $payload);
        $job = $runner->runStep($job);

        self::assertSame(Job::PACKAGING_ARCHIVE, $job->state());
        self::assertSame(1, $job->payload()['archive_index']);

        $job = $runner->runStep($job);
        self::assertSame(Job::PACKAGING_ARCHIVE, $job->state(), (string) ($job->payload()['message'] ?? ''));
        self::assertSame(2, $job->payload()['archive_index']);

        $job = $runner->runStep($job);
        self::assertSame(Job::PACKAGING_ARCHIVE, $job->state());
        self::assertSame(3, $job->payload()['archive_index']);

        $job = $runner->runStep($job);
        self::assertSame(Job::VALIDATING_BACKUP, $job->state(), (string) ($job->payload()['message'] ?? ''));
        self::assertSame(4, $job->payload()['archive_index']);

        $job = $runner->runStep($job);
        self::assertSame(Job::COMPLETED, $job->state(), (string) ($job->payload()['message'] ?? ''));
        self::assertSame('valid', $job->payload()['archive_validation_status']);
    }

    public function testPackagingPassesManifestPathWithoutMaterializingScannedFiles(): void
    {
        $jobs = new BackupStepRunnerJobRepository();
        $packager = new BackupStepRunnerManifestRecordingPackager();
        $runner = $this->runnerWithPackager($jobs, $packager);
        $payload = $this->payload();
        $payload['database_directory'] = $this->root . '/work/backup-123/database';
        $payload['scanned_files_path'] = $this->root . '/work/backup-123/files.jsonl';
        mkdir($payload['database_directory'] . '/chunks', 0777, true);
        file_put_contents($payload['database_directory'] . '/tables.json', '{}');
        file_put_contents($payload['scanned_files_path'], "{\"absolute_path\":\"/tmp/first\",\"relative_path\":\"first.txt\",\"size\":1,\"symlink\":false}\n");

        $runner->runStep(new Job('backup-123', 'backup', Job::PACKAGING_ARCHIVE, $payload));

        self::assertSame($payload['scanned_files_path'], $packager->received_manifest_path);
        self::assertSame(0, $packager->received_file_count);
    }

    private function runner(BackupStepRunnerJobRepository $jobs): BackupStepRunner
    {
        return $this->runnerWithPackager($jobs, new BackupStepRunnerPackager());
    }

    private function runnerWithPackager(BackupStepRunnerJobRepository $jobs, BackupArchiveStepPackagerInterface $packager, int $file_scan_batch_size = 100, ?WpdbClientInterface $client = null): BackupStepRunner
    {
        return new BackupStepRunner(
            $jobs,
            new WpdbDatabaseExporter($client ?? new BackupStepRunnerClient(), new TableSelector()),
            new ChunkPlanner(),
            new SqlDumpFormatter(),
            new DatabaseExportManifestBuilder(),
            new FileScanner(),
            $packager,
            $file_scan_batch_size
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        return array(
            'site_root' => $this->root . '/site',
            'working_directory' => $this->root . '/work/backup-123',
            'table_prefix' => 'wp_',
            'table_selection_mode' => TableSelector::MODE_PREFIXED,
            'database_chunk_size' => 2,
            'manifest_metadata' => array(
                'source_site_url' => 'https://example.com',
                'source_home_url' => 'https://example.com',
                'wordpress_version' => '6.5',
                'php_version' => PHP_VERSION,
                'database_version' => '8.0',
                'table_prefix' => 'wp_',
                'is_multisite' => false,
                'active_theme' => 'theme',
                'active_plugins' => array(),
                'must_use_plugins' => array(),
                'created_at' => '2026-05-16T12:00:00+00:00',
                'file_count' => 0,
                'database_table_count' => 0,
                'archive_size' => 0,
                'checksums' => array(),
                'exclusions' => array(),
                'environment' => array(),
            ),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
                continue;
            }
            unlink($child);
        }
        rmdir($path);
    }
}

final class BackupStepRunnerJobRepository implements JobRepositoryInterface
{
    /** @var array<string,Job> */
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

final class BackupStepRunnerClient implements WpdbClientInterface
{
    public function getTables(): array
    {
        return array('wp_posts');
    }

    public function getCreateTableSql(string $table): string
    {
        return 'CREATE TABLE `wp_posts` (`ID` bigint)';
    }

    public function getPrimaryKey(string $table): ?string
    {
        return 'ID';
    }

    public function getRowCount(string $table): int
    {
        return 3;
    }

    public function getTableStatus(string $table): array
    {
        return array();
    }

    public function getColumns(string $table): array
    {
        return array('ID', 'post_title');
    }

    public function getRows(string $sql): array
    {
        if (strpos($sql, 'WHERE `ID` > 2') !== false) {
            return array(array('ID' => 3, 'post_title' => 'Again'));
        }

        return array(array('ID' => 1, 'post_title' => 'Hello'), array('ID' => 2, 'post_title' => 'World'));
    }

    public function prepare(string $sql, array $args): string
    {
        foreach ($args as $arg) {
            $sql = preg_replace('/%d/', (string) $arg, $sql, 1);
        }

        return $sql;
    }
}

final class BackupStepRunnerNoPrimaryKeyClient implements WpdbClientInterface
{
    public function getTables(): array
    {
        return array('wp_posts');
    }

    public function getCreateTableSql(string $table): string
    {
        return 'CREATE TABLE `wp_posts` (`ID` bigint)';
    }

    public function getPrimaryKey(string $table): ?string
    {
        return null;
    }

    public function getRowCount(string $table): int
    {
        return 4;
    }

    public function getTableStatus(string $table): array
    {
        return array();
    }

    public function getColumns(string $table): array
    {
        return array('ID', 'post_title');
    }

    public function getRows(string $sql): array
    {
        if (strpos($sql, 'OFFSET 2') !== false) {
            return array(array('ID' => 3, 'post_title' => 'Again'), array('ID' => 4, 'post_title' => 'More'));
        }

        return array(array('ID' => 1, 'post_title' => 'Hello'), array('ID' => 2, 'post_title' => 'World'));
    }

    public function prepare(string $sql, array $args): string
    {
        foreach ($args as $arg) {
            $sql = preg_replace('/%d/', (string) $arg, $sql, 1);
        }

        return $sql;
    }
}

final class BackupStepRunnerPackager implements BackupArchiveStepPackagerInterface
{
    public function packageStep(string $job_id, string $working_directory, string $database_directory, array $site_files, array $metadata, array $payload): array
    {
        $archive_path = rtrim($working_directory, '/\\') . '/' . $job_id . '.zip';
        file_put_contents($archive_path, 'zip');

        $payload['archive_path'] = $archive_path;
        $payload['archive_size'] = 3;
        $payload['archive_database_file_count'] = 1;
        $payload['archive_complete'] = true;
        $payload['archive_validation_status'] = 'valid';
        $payload['message'] = 'Backup completed.';

        return $payload;
    }
}

final class BackupStepRunnerManifestRecordingPackager implements BackupArchiveStepPackagerInterface
{
    public string $received_manifest_path = '';
    public int $received_file_count = -1;

    public function packageStep(string $job_id, string $working_directory, string $database_directory, array $site_files, array $metadata, array $payload): array
    {
        $this->received_manifest_path = isset($payload['scanned_files_path']) ? (string) $payload['scanned_files_path'] : '';
        $this->received_file_count = count($site_files);
        $payload['archive_complete'] = false;

        return $payload;
    }
}
