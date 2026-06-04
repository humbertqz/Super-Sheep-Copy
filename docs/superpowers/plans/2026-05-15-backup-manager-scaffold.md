# Backup Manager Scaffold Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a synchronous backup manager scaffold that creates a working directory, records job states, runs database export, scans files, and returns a backup summary.

**Architecture:** Add `BackupOptions`, `BackupResult`, and `BackupManager` under `src/Backup/`. Add a small `DatabaseBackupCoordinatorInterface` so the manager can depend on an export contract and tests can use a fake coordinator while the existing `DatabaseBackupCoordinator` remains the production implementation.

**Tech Stack:** PHP 7.4+, Composer PSR-4 autoloading, PHPUnit 9.6, local filesystem temp directories.

---

## Scope Check

This plan implements `docs/superpowers/specs/2026-05-15-backup-manager-scaffold-design.md`.

Included:
- `BackupOptions` value object.
- `BackupResult` value object.
- `BackupManager` service.
- `DatabaseBackupCoordinatorInterface` to make the manager testable.
- Job state transitions: `created`, `exporting_database`, `scanning_files`, `completed`.
- Unit tests with fake job repository, fake database coordinator, temporary directories, and file fixtures.

Excluded:
- ZIP archive creation.
- Admin form submission.
- AJAX or REST job runner.
- WP-Cron.
- Real `$wpdb` service wiring.
- Download links.
- Restore work.

## File Structure

- Create `super-sheep-copy/src/Backup/BackupOptions.php`
  - Validated value object for backup inputs.
- Create `super-sheep-copy/src/Backup/BackupResult.php`
  - Value object returned by `BackupManager`.
- Create `super-sheep-copy/src/Backup/BackupManager.php`
  - Coordinates job persistence, database export, file scan, and result creation.
- Create `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinatorInterface.php`
  - Export contract consumed by `BackupManager`.
- Modify `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php`
  - Implement `DatabaseBackupCoordinatorInterface`.
- Create tests:
  - `super-sheep-copy/tests/Unit/BackupOptionsTest.php`
  - `super-sheep-copy/tests/Unit/BackupManagerTest.php`

---

### Task 1: Backup Options and Result

**Files:**
- Create: `super-sheep-copy/src/Backup/BackupOptions.php`
- Create: `super-sheep-copy/src/Backup/BackupResult.php`
- Test: `super-sheep-copy/tests/Unit/BackupOptionsTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/BackupOptionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupOptions;
use SuperSheepCopy\Backup\BackupResult;
use SuperSheepCopy\Jobs\Job;

final class BackupOptionsTest extends TestCase
{
    public function testStoresBackupOptions(): void
    {
        $options = new BackupOptions('/site', '/backups', 'wp_', 'prefixed', 100);

        self::assertSame('/site', $options->siteRoot());
        self::assertSame('/backups', $options->workingBaseDirectory());
        self::assertSame('wp_', $options->tablePrefix());
        self::assertSame('prefixed', $options->tableSelectionMode());
        self::assertSame(100, $options->databaseChunkSize());
    }

    public function testRejectsEmptySiteRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Site root is required.');

        new BackupOptions('', '/backups', 'wp_', 'prefixed', 100);
    }

    public function testRejectsInvalidChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database chunk size must be greater than zero.');

        new BackupOptions('/site', '/backups', 'wp_', 'prefixed', 0);
    }

    public function testStoresBackupResult(): void
    {
        $result = new BackupResult('backup-123', '/backups/backup-123', '/backups/backup-123/database', 7, Job::COMPLETED);

        self::assertSame('backup-123', $result->jobId());
        self::assertSame('/backups/backup-123', $result->workingDirectory());
        self::assertSame('/backups/backup-123/database', $result->databaseDirectory());
        self::assertSame(7, $result->scannedFileCount());
        self::assertSame(Job::COMPLETED, $result->state());
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupOptionsTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\BackupOptions" not found`.

- [x] **Step 3: Add `BackupOptions`**

Create `super-sheep-copy/src/Backup/BackupOptions.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use InvalidArgumentException;

final class BackupOptions
{
    private string $site_root;
    private string $working_base_directory;
    private string $table_prefix;
    private string $table_selection_mode;
    private int $database_chunk_size;

    public function __construct(
        string $site_root,
        string $working_base_directory,
        string $table_prefix,
        string $table_selection_mode,
        int $database_chunk_size
    ) {
        if ($site_root === '') {
            throw new InvalidArgumentException('Site root is required.');
        }

        if ($working_base_directory === '') {
            throw new InvalidArgumentException('Working base directory is required.');
        }

        if ($table_prefix === '') {
            throw new InvalidArgumentException('Table prefix is required.');
        }

        if ($table_selection_mode === '') {
            throw new InvalidArgumentException('Table selection mode is required.');
        }

        if ($database_chunk_size < 1) {
            throw new InvalidArgumentException('Database chunk size must be greater than zero.');
        }

        $this->site_root = $site_root;
        $this->working_base_directory = $working_base_directory;
        $this->table_prefix = $table_prefix;
        $this->table_selection_mode = $table_selection_mode;
        $this->database_chunk_size = $database_chunk_size;
    }

    public function siteRoot(): string
    {
        return $this->site_root;
    }

    public function workingBaseDirectory(): string
    {
        return $this->working_base_directory;
    }

    public function tablePrefix(): string
    {
        return $this->table_prefix;
    }

    public function tableSelectionMode(): string
    {
        return $this->table_selection_mode;
    }

    public function databaseChunkSize(): int
    {
        return $this->database_chunk_size;
    }
}
```

- [x] **Step 4: Add `BackupResult`**

Create `super-sheep-copy/src/Backup/BackupResult.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class BackupResult
{
    private string $job_id;
    private string $working_directory;
    private string $database_directory;
    private int $scanned_file_count;
    private string $state;

    public function __construct(
        string $job_id,
        string $working_directory,
        string $database_directory,
        int $scanned_file_count,
        string $state
    ) {
        $this->job_id = $job_id;
        $this->working_directory = $working_directory;
        $this->database_directory = $database_directory;
        $this->scanned_file_count = $scanned_file_count;
        $this->state = $state;
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

    public function scannedFileCount(): int
    {
        return $this->scanned_file_count;
    }

    public function state(): string
    {
        return $this->state;
    }
}
```

- [x] **Step 5: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupOptionsTest.php
```

Expected: PASS with `OK (4 tests`.

- [x] **Step 6: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 7: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/BackupOptions.php super-sheep-copy/src/Backup/BackupResult.php super-sheep-copy/tests/Unit/BackupOptionsTest.php
git commit -m "feat: add backup manager value objects"
```

Expected: commit succeeds.

---

### Task 2: Backup Manager

**Files:**
- Create: `super-sheep-copy/src/Backup/BackupManager.php`
- Create: `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinatorInterface.php`
- Modify: `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php`
- Test: `super-sheep-copy/tests/Unit/BackupManagerTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/BackupManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupManager;
use SuperSheepCopy\Backup\BackupOptions;
use SuperSheepCopy\Backup\Database\DatabaseBackupCoordinatorInterface;
use SuperSheepCopy\Backup\FileScanner;
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
        $manager = new BackupManager($jobs, $database, new FileScanner());

        $result = $manager->run(new BackupOptions($this->root . '/site', $this->root . '/working', 'wp_', 'prefixed', 100));

        self::assertSame(Job::COMPLETED, $result->state());
        self::assertDirectoryExists($result->workingDirectory());
        self::assertSame($result->workingDirectory() . '/database', $result->databaseDirectory());
        self::assertSame(2, $result->scannedFileCount());
        self::assertSame(array(Job::CREATED, Job::EXPORTING_DATABASE, Job::SCANNING_FILES, Job::COMPLETED), $jobs->states());
        self::assertSame($result->workingDirectory(), $database->workingDirectory());
        self::assertSame('wp_', $database->tablePrefix());
        self::assertSame('prefixed', $database->selectionMode());
        self::assertSame(100, $database->chunkSize());

        $completed = $jobs->find($result->jobId());
        self::assertInstanceOf(Job::class, $completed);
        self::assertSame(2, $completed->payload()['scanned_file_count']);
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

final class FakeDatabaseBackupCoordinator implements DatabaseBackupCoordinatorInterface
{
    private string $working_directory = '';
    private string $table_prefix = '';
    private string $selection_mode = '';
    private int $chunk_size = 0;

    public function export(string $working_directory, string $table_prefix, string $selection_mode, int $chunk_size): void
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
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupManagerTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\BackupManager" not found`.

- [x] **Step 3: Add coordinator interface**

Create `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinatorInterface.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

interface DatabaseBackupCoordinatorInterface
{
    public function export(string $working_directory, string $table_prefix, string $selection_mode, int $chunk_size): void;
}
```

- [x] **Step 4: Update real coordinator**

Modify the class declaration in `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php`:

```php
final class DatabaseBackupCoordinator implements DatabaseBackupCoordinatorInterface
```

- [x] **Step 5: Add `BackupManager`**

Create `super-sheep-copy/src/Backup/BackupManager.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RuntimeException;
use SuperSheepCopy\Backup\Database\DatabaseBackupCoordinatorInterface;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class BackupManager
{
    private JobRepositoryInterface $jobs;
    private DatabaseBackupCoordinatorInterface $database;
    private FileScanner $files;

    public function __construct(JobRepositoryInterface $jobs, DatabaseBackupCoordinatorInterface $database, FileScanner $files)
    {
        $this->jobs = $jobs;
        $this->database = $database;
        $this->files = $files;
    }

    public function run(BackupOptions $options): BackupResult
    {
        $job_id = 'backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $working_directory = rtrim($options->workingBaseDirectory(), '/\\') . '/' . $job_id;
        $database_directory = $working_directory . '/database';

        $this->ensureDirectory($working_directory);

        $this->save($job_id, Job::CREATED, array('working_directory' => $working_directory));
        $this->save($job_id, Job::EXPORTING_DATABASE, array('working_directory' => $working_directory));

        $this->database->export(
            $working_directory,
            $options->tablePrefix(),
            $options->tableSelectionMode(),
            $options->databaseChunkSize()
        );

        $this->save($job_id, Job::SCANNING_FILES, array('working_directory' => $working_directory));
        $files = $this->files->scan($options->siteRoot());
        $scanned_file_count = count($files);

        $payload = array(
            'working_directory' => $working_directory,
            'database_directory' => $database_directory,
            'scanned_file_count' => $scanned_file_count,
        );
        $this->save($job_id, Job::COMPLETED, $payload);

        return new BackupResult($job_id, $working_directory, $database_directory, $scanned_file_count, Job::COMPLETED);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function save(string $job_id, string $state, array $payload): void
    {
        $this->jobs->save(new Job($job_id, 'backup', $state, $payload));
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create backup working directory: ' . $directory);
        }
    }
}
```

- [x] **Step 6: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupManagerTest.php
```

Expected: PASS with `OK (1 test`.

- [x] **Step 7: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 8: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/BackupManager.php super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinatorInterface.php super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php super-sheep-copy/tests/Unit/BackupManagerTest.php
git commit -m "feat: scaffold backup manager"
```

Expected: commit succeeds.

---

### Task 3: Final Verification

**Files:**
- Verify: `super-sheep-copy/src/Backup/BackupOptions.php`
- Verify: `super-sheep-copy/src/Backup/BackupResult.php`
- Verify: `super-sheep-copy/src/Backup/BackupManager.php`
- Verify: `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinatorInterface.php`
- Verify: `super-sheep-copy/tests/Unit/BackupOptionsTest.php`
- Verify: `super-sheep-copy/tests/Unit/BackupManagerTest.php`

- [x] **Step 1: Run lint**

Run:

```bash
cd super-sheep-copy && composer run lint
```

Expected: every PHP file reports `No syntax errors detected`.

- [x] **Step 2: Run full PHPUnit suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: all tests pass.

- [x] **Step 3: Confirm manager has no direct WordPress dependency**

Run:

```bash
rg "\\$wpdb|ABSPATH|wp-load|wp_" super-sheep-copy/src/Backup/BackupManager.php super-sheep-copy/src/Backup/BackupOptions.php super-sheep-copy/src/Backup/BackupResult.php
```

Expected: no matches.

- [x] **Step 4: Check git status**

Run:

```bash
git status --short
```

Expected: empty working tree after task commits.

## Self-Review

- Spec coverage: The plan covers backup options, backup result, working directory creation, job state persistence in order, database export invocation, file scanning, completed payload, and result fields.
- Placeholder scan: No step relies on unspecified implementation details. New tests and implementation code are concrete.
- Type consistency: `BackupManager` uses `BackupOptions`, `BackupResult`, `JobRepositoryInterface`, `DatabaseBackupCoordinatorInterface`, and `FileScanner` exactly as defined in the plan.
