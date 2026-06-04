# Archive Packaging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Connect `BackupManager` to ZIP archive creation so a backup run packages scanned site files, database export files, manifest metadata, checksums, and a log.

**Architecture:** Add a `BackupArchivePackagerInterface`, `BackupArchivePackager`, and `ArchivePackageResult` under `src/Backup/`. Expand `ArchiveWriter` so it can write database files under `database/...`; then update `BackupManager` to call the packager, record a `packaging_archive` state, and return archive details in `BackupResult`.

**Tech Stack:** PHP 7.4+, Composer PSR-4 autoloading, PHPUnit 9.6, `ZipArchive` when available, local filesystem temp directories.

---

## Scope Check

This plan implements `docs/superpowers/specs/2026-05-16-archive-packaging-design.md`.

Included:
- Archive package result value object.
- Archive packager interface for testable manager orchestration.
- Archive packager service.
- Database file support in `ArchiveWriter`.
- Optional manifest metadata on `BackupOptions`.
- Archive details on `BackupResult`.
- `BackupManager` state transition and completed payload updates.

Excluded:
- Admin download URLs.
- Backup list UI.
- Live WordPress metadata gathering.
- Background or chunked archive writing.
- Restore archive validation.
- Standalone installer changes.
- Cleanup of working directories after archive creation.

## File Structure

- Create `super-sheep-copy/src/Backup/ArchivePackageResult.php`
  - Immutable value object returned by archive packaging.
- Create `super-sheep-copy/src/Backup/BackupArchivePackagerInterface.php`
  - Contract consumed by `BackupManager` and faked in tests.
- Create `super-sheep-copy/src/Backup/BackupArchivePackager.php`
  - Discovers database files, computes checksums, builds manifest metadata, and calls `ArchiveWriter`.
- Modify `super-sheep-copy/src/Backup/ArchiveWriter.php`
  - Accept site files and database files separately and write database files under `database/...`.
- Modify `super-sheep-copy/src/Backup/BackupOptions.php`
  - Add optional manifest metadata array.
- Modify `super-sheep-copy/src/Backup/BackupResult.php`
  - Add archive path, archive size, and database file count.
- Modify `super-sheep-copy/src/Backup/BackupManager.php`
  - Inject packager, call it after scanning, record `packaging_archive`, and include archive details in completed payload.
- Modify `super-sheep-copy/src/Jobs/Job.php`
  - Add `PACKAGING_ARCHIVE = 'packaging_archive'`.
- Create/modify tests:
  - `super-sheep-copy/tests/Unit/BackupArchivePackagerTest.php`
  - `super-sheep-copy/tests/Unit/ArchiveWriterTest.php`
  - `super-sheep-copy/tests/Unit/BackupOptionsTest.php`
  - `super-sheep-copy/tests/Unit/BackupManagerTest.php`

---

### Task 1: Archive Writer Database Entries

**Files:**
- Modify: `super-sheep-copy/src/Backup/ArchiveWriter.php`
- Modify: `super-sheep-copy/tests/Unit/ArchiveWriterTest.php`

- [x] **Step 1: Write the failing test**

Replace `testWritesBackupPackageShape()` in `super-sheep-copy/tests/Unit/ArchiveWriterTest.php` with this version:

```php
public function testWritesBackupPackageShape(): void
{
    if (!class_exists(ZipArchive::class)) {
        self::markTestSkipped('ZipArchive is not available.');
    }

    $root = sys_get_temp_dir() . '/ssc-archive-' . bin2hex(random_bytes(4));
    mkdir($root . '/source/wp-content/uploads', 0777, true);
    mkdir($root . '/database/wp_posts', 0777, true);
    mkdir($root . '/output', 0777, true);
    file_put_contents($root . '/source/wp-content/uploads/file.txt', 'backup file');
    file_put_contents($root . '/database/tables.json', '{"tables":[]}');
    file_put_contents($root . '/database/wp_posts/chunk-000001.sql', 'INSERT INTO wp_posts VALUES (1);');

    $archive = $root . '/output/backup.zip';
    $writer = new ArchiveWriter();
    $writer->write(
        $archive,
        new Manifest(array('project' => 'Super Sheep Copy')),
        array(new ScannedFile($root . '/source/wp-content/uploads/file.txt', 'wp-content/uploads/file.txt', 11, false)),
        array(
            new ScannedFile($root . '/database/tables.json', 'tables.json', 13, false),
            new ScannedFile($root . '/database/wp_posts/chunk-000001.sql', 'wp_posts/chunk-000001.sql', 30, false),
        ),
        array(
            'files/wp-content/uploads/file.txt' => 'hash123',
            'database/tables.json' => 'hash456',
            'database/wp_posts/chunk-000001.sql' => 'hash789',
        ),
        'backup started'
    );

    $zip = new ZipArchive();
    self::assertTrue($zip->open($archive));
    self::assertSame('backup file', $zip->getFromName('files/wp-content/uploads/file.txt'));
    self::assertSame('{"tables":[]}', $zip->getFromName('database/tables.json'));
    self::assertSame('INSERT INTO wp_posts VALUES (1);', $zip->getFromName('database/wp_posts/chunk-000001.sql'));
    self::assertNotFalse($zip->getFromName('manifest.json'));
    self::assertNotFalse($zip->getFromName('checksums.json'));
    self::assertSame('backup started', $zip->getFromName('logs/backup.log'));
    $zip->close();

    $this->removeDirectory($root);
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/ArchiveWriterTest.php
```

Expected: FAIL with an argument count or type error because `ArchiveWriter::write()` does not yet accept database files.

- [x] **Step 3: Update `ArchiveWriter`**

Replace `super-sheep-copy/src/Backup/ArchiveWriter.php` with:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RuntimeException;
use ZipArchive;

final class ArchiveWriter
{
    /**
     * @param ScannedFile[] $site_files
     * @param ScannedFile[] $database_files
     * @param array<string,string> $checksums
     */
    public function write(string $archive_path, Manifest $manifest, array $site_files, array $database_files, array $checksums, string $log): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is not available.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create backup archive.');
        }

        $zip->addFromString('manifest.json', $manifest->toJson());
        $zip->addFromString('checksums.json', (string) json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('logs/backup.log', $log);

        foreach ($site_files as $file) {
            if ($file->isSymlink()) {
                continue;
            }
            $zip->addFile($file->absolutePath(), 'files/' . $file->relativePath());
        }

        foreach ($database_files as $file) {
            if ($file->isSymlink()) {
                continue;
            }
            $zip->addFile($file->absolutePath(), 'database/' . $file->relativePath());
        }

        $zip->close();
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/ArchiveWriterTest.php
```

Expected: PASS with `OK (1 test`, or SKIPPED if `ZipArchive` is unavailable.

- [x] **Step 5: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS. If a failure reports old `ArchiveWriter::write()` argument order, update that call site to pass an empty database files array as the fourth argument.

- [x] **Step 6: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/ArchiveWriter.php super-sheep-copy/tests/Unit/ArchiveWriterTest.php
git commit -m "feat: write database files to backup archives"
```

Expected: commit succeeds.

---

### Task 2: Archive Package Result and Packager

**Files:**
- Create: `super-sheep-copy/src/Backup/ArchivePackageResult.php`
- Create: `super-sheep-copy/src/Backup/BackupArchivePackagerInterface.php`
- Create: `super-sheep-copy/src/Backup/BackupArchivePackager.php`
- Create: `super-sheep-copy/tests/Unit/BackupArchivePackagerTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/BackupArchivePackagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\ArchiveWriter;
use SuperSheepCopy\Backup\BackupArchivePackager;
use SuperSheepCopy\Backup\ManifestBuilder;
use SuperSheepCopy\Backup\ScannedFile;
use ZipArchive;

final class BackupArchivePackagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-packager-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/site/wp-content/uploads', 0777, true);
        mkdir($this->root . '/working/database/wp_posts', 0777, true);
        file_put_contents($this->root . '/site/wp-content/uploads/file.txt', 'backup file');
        file_put_contents($this->root . '/working/database/tables.json', '{"tables":[]}');
        file_put_contents($this->root . '/working/database/wp_posts/chunk-000001.sql', 'INSERT INTO wp_posts VALUES (1);');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPackagesBackupArchiveWithManifestChecksumsAndDatabaseFiles(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $packager = new BackupArchivePackager(new ArchiveWriter(), new ManifestBuilder('0.1.0', '1'));
        $result = $packager->package(
            'backup-123',
            $this->root . '/working',
            $this->root . '/working/database',
            array(new ScannedFile($this->root . '/site/wp-content/uploads/file.txt', 'wp-content/uploads/file.txt', 11, false)),
            $this->metadata()
        );

        self::assertSame($this->root . '/working/backup-123.zip', $result->archivePath());
        self::assertGreaterThan(0, $result->archiveSize());
        self::assertSame(1, $result->siteFileCount());
        self::assertSame(2, $result->databaseFileCount());
        self::assertSame(hash('sha256', 'backup file'), $result->checksums()['files/wp-content/uploads/file.txt']);
        self::assertSame(hash('sha256', '{"tables":[]}'), $result->checksums()['database/tables.json']);
        self::assertSame(hash('sha256', 'INSERT INTO wp_posts VALUES (1);'), $result->checksums()['database/wp_posts/chunk-000001.sql']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($result->archivePath()));
        self::assertSame('backup file', $zip->getFromName('files/wp-content/uploads/file.txt'));
        self::assertSame('{"tables":[]}', $zip->getFromName('database/tables.json'));
        self::assertSame('INSERT INTO wp_posts VALUES (1);', $zip->getFromName('database/wp_posts/chunk-000001.sql'));

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['file_count']);
        self::assertSame(2, $manifest['database_table_count']);
        self::assertSame($result->archiveSize(), $manifest['archive_size']);
        self::assertSame($result->checksums(), $manifest['checksums']);

        $checksums = json_decode((string) $zip->getFromName('checksums.json'), true);
        self::assertSame($result->checksums(), $checksums);
        self::assertStringContainsString('Backup backup-123 packaged.', (string) $zip->getFromName('logs/backup.log'));
        $zip->close();
    }

    public function testRejectsMissingDatabaseDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database export directory does not exist.');

        $packager = new BackupArchivePackager(new ArchiveWriter(), new ManifestBuilder('0.1.0', '1'));
        $packager->package(
            'backup-123',
            $this->root . '/working',
            $this->root . '/working/missing-database',
            array(),
            $this->metadata()
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(): array
    {
        return array(
            'source_site_url' => 'https://example.com',
            'source_home_url' => 'https://example.com',
            'wordpress_version' => '6.5',
            'php_version' => PHP_VERSION,
            'database_version' => '8.0',
            'table_prefix' => 'wp_',
            'is_multisite' => false,
            'active_theme' => 'twentytwentyfour',
            'active_plugins' => array('akismet/akismet.php'),
            'must_use_plugins' => array(),
            'created_at' => '2026-05-16T12:00:00+00:00',
            'file_count' => 0,
            'database_table_count' => 0,
            'archive_size' => 0,
            'checksums' => array(),
            'exclusions' => array('wp-content/cache'),
            'environment' => array('zip' => true),
        );
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
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupArchivePackagerTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\BackupArchivePackager" not found`.

- [x] **Step 3: Add `ArchivePackageResult`**

Create `super-sheep-copy/src/Backup/ArchivePackageResult.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class ArchivePackageResult
{
    private string $archive_path;
    private int $archive_size;
    private int $site_file_count;
    private int $database_file_count;
    /** @var array<string,string> */
    private array $checksums;

    /**
     * @param array<string,string> $checksums
     */
    public function __construct(string $archive_path, int $archive_size, int $site_file_count, int $database_file_count, array $checksums)
    {
        $this->archive_path = $archive_path;
        $this->archive_size = $archive_size;
        $this->site_file_count = $site_file_count;
        $this->database_file_count = $database_file_count;
        $this->checksums = $checksums;
    }

    public function archivePath(): string
    {
        return $this->archive_path;
    }

    public function archiveSize(): int
    {
        return $this->archive_size;
    }

    public function siteFileCount(): int
    {
        return $this->site_file_count;
    }

    public function databaseFileCount(): int
    {
        return $this->database_file_count;
    }

    /**
     * @return array<string,string>
     */
    public function checksums(): array
    {
        return $this->checksums;
    }
}
```

- [x] **Step 4: Add packager interface**

Create `super-sheep-copy/src/Backup/BackupArchivePackagerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

interface BackupArchivePackagerInterface
{
    /**
     * @param ScannedFile[] $site_files
     * @param array<string,mixed> $metadata
     */
    public function package(string $job_id, string $working_directory, string $database_directory, array $site_files, array $metadata): ArchivePackageResult;
}
```

- [x] **Step 5: Add `BackupArchivePackager`**

Create `super-sheep-copy/src/Backup/BackupArchivePackager.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class BackupArchivePackager implements BackupArchivePackagerInterface
{
    private ArchiveWriter $archive_writer;
    private ManifestBuilder $manifest_builder;

    public function __construct(ArchiveWriter $archive_writer, ManifestBuilder $manifest_builder)
    {
        $this->archive_writer = $archive_writer;
        $this->manifest_builder = $manifest_builder;
    }

    /**
     * @param ScannedFile[] $site_files
     * @param array<string,mixed> $metadata
     */
    public function package(string $job_id, string $working_directory, string $database_directory, array $site_files, array $metadata): ArchivePackageResult
    {
        $database_files = $this->databaseFiles($database_directory);
        $checksums = array_merge(
            $this->checksumsForFiles('files', $site_files),
            $this->checksumsForFiles('database', $database_files)
        );

        $archive_path = rtrim($working_directory, '/\\') . '/' . $job_id . '.zip';
        $metadata['file_count'] = count($site_files);
        $metadata['database_table_count'] = count($database_files);
        $archive_size = 0;
        $metadata['archive_size'] = $archive_size;
        $metadata['checksums'] = $checksums;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $metadata['archive_size'] = $archive_size;
            $this->archive_writer->write(
                $archive_path,
                $this->manifest_builder->build($metadata),
                $site_files,
                $database_files,
                $checksums,
                'Backup ' . $job_id . ' packaged.'
            );

            clearstatcache(true, $archive_path);
            $new_archive_size = filesize($archive_path);
            if ($new_archive_size === false) {
                throw new RuntimeException('Unable to read backup archive size.');
            }

            if ((int) $new_archive_size === $archive_size) {
                return new ArchivePackageResult($archive_path, $archive_size, count($site_files), count($database_files), $checksums);
            }

            $archive_size = (int) $new_archive_size;
        }

        throw new RuntimeException('Unable to stabilize backup archive size.');
    }

    /**
     * @return ScannedFile[]
     */
    private function databaseFiles(string $database_directory): array
    {
        if (!is_dir($database_directory)) {
            throw new RuntimeException('Database export directory does not exist.');
        }

        $root = rtrim(str_replace('\\', '/', $database_directory), '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $files = array();
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($absolute, strlen($root)), '/');
            $files[] = new ScannedFile($absolute, $relative, (int) $item->getSize(), $item->isLink());
        }

        usort($files, static function (ScannedFile $a, ScannedFile $b): int {
            return strcmp($a->relativePath(), $b->relativePath());
        });

        return $files;
    }

    /**
     * @param ScannedFile[] $files
     * @return array<string,string>
     */
    private function checksumsForFiles(string $prefix, array $files): array
    {
        $checksums = array();
        foreach ($files as $file) {
            if ($file->isSymlink()) {
                continue;
            }

            $checksum = hash_file('sha256', $file->absolutePath());
            if ($checksum === false) {
                throw new RuntimeException('Unable to calculate checksum for: ' . $file->relativePath());
            }

            $checksums[$prefix . '/' . $file->relativePath()] = $checksum;
        }

        return $checksums;
    }
}
```

- [x] **Step 6: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupArchivePackagerTest.php
```

Expected: PASS with `OK (2 tests`, or SKIPPED if `ZipArchive` is unavailable for the packaging test.

- [x] **Step 7: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 8: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/ArchivePackageResult.php super-sheep-copy/src/Backup/BackupArchivePackagerInterface.php super-sheep-copy/src/Backup/BackupArchivePackager.php super-sheep-copy/tests/Unit/BackupArchivePackagerTest.php
git commit -m "feat: package backup archives"
```

Expected: commit succeeds.

---

### Task 3: Backup Manager Archive Integration

**Files:**
- Modify: `super-sheep-copy/src/Backup/BackupOptions.php`
- Modify: `super-sheep-copy/src/Backup/BackupResult.php`
- Modify: `super-sheep-copy/src/Backup/BackupManager.php`
- Modify: `super-sheep-copy/src/Jobs/Job.php`
- Modify: `super-sheep-copy/tests/Unit/BackupOptionsTest.php`
- Modify: `super-sheep-copy/tests/Unit/BackupManagerTest.php`

- [x] **Step 1: Write the failing tests**

Update `testStoresBackupOptions()` in `super-sheep-copy/tests/Unit/BackupOptionsTest.php`:

```php
public function testStoresBackupOptions(): void
{
    $metadata = array('source_site_url' => 'https://example.com');
    $options = new BackupOptions('/site', '/backups', 'wp_', 'prefixed', 100, $metadata);

    self::assertSame('/site', $options->siteRoot());
    self::assertSame('/backups', $options->workingBaseDirectory());
    self::assertSame('wp_', $options->tablePrefix());
    self::assertSame('prefixed', $options->tableSelectionMode());
    self::assertSame(100, $options->databaseChunkSize());
    self::assertSame($metadata, $options->manifestMetadata());
}
```

Update `testStoresBackupResult()` in `super-sheep-copy/tests/Unit/BackupOptionsTest.php`:

```php
public function testStoresBackupResult(): void
{
    $result = new BackupResult('backup-123', '/backups/backup-123', '/backups/backup-123/database', '/backups/backup-123/backup-123.zip', 2048, 7, 3, Job::COMPLETED);

    self::assertSame('backup-123', $result->jobId());
    self::assertSame('/backups/backup-123', $result->workingDirectory());
    self::assertSame('/backups/backup-123/database', $result->databaseDirectory());
    self::assertSame('/backups/backup-123/backup-123.zip', $result->archivePath());
    self::assertSame(2048, $result->archiveSize());
    self::assertSame(7, $result->scannedFileCount());
    self::assertSame(3, $result->databaseFileCount());
    self::assertSame(Job::COMPLETED, $result->state());
}
```

Replace `testRunsBackupWorkflow()` in `super-sheep-copy/tests/Unit/BackupManagerTest.php`:

```php
public function testRunsBackupWorkflow(): void
{
    $jobs = new MemoryJobRepository();
    $database = new FakeDatabaseBackupCoordinator();
    $packager = new FakeBackupArchivePackager();
    $manager = new BackupManager($jobs, $database, new FileScanner(), $packager);

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

    $completed = $jobs->find($result->jobId());
    self::assertInstanceOf(Job::class, $completed);
    self::assertSame(2, $completed->payload()['scanned_file_count']);
    self::assertSame(1, $completed->payload()['database_file_count']);
    self::assertSame($result->archivePath(), $completed->payload()['archive_path']);
    self::assertSame(4096, $completed->payload()['archive_size']);
}
```

Add imports to `BackupManagerTest.php`:

```php
use SuperSheepCopy\Backup\ArchivePackageResult;
use SuperSheepCopy\Backup\BackupArchivePackagerInterface;
use SuperSheepCopy\Backup\ScannedFile;
```

Add this fake class before `MemoryJobRepository` in `BackupManagerTest.php`:

```php
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

        return new ArchivePackageResult($working_directory . '/backup.zip', 4096, count($site_files), 1, array('database/tables.json' => 'hash123'));
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
```

- [x] **Step 2: Run tests to verify they fail**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupOptionsTest.php tests/Unit/BackupManagerTest.php
```

Expected: FAIL because `BackupOptions::manifestMetadata()`, new `BackupResult` accessors, `Job::PACKAGING_ARCHIVE`, and the four-argument `BackupManager` constructor are not implemented yet.

- [x] **Step 3: Update `BackupOptions`**

Replace `super-sheep-copy/src/Backup/BackupOptions.php` with:

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
    /** @var array<string,mixed> */
    private array $manifest_metadata;

    /**
     * @param array<string,mixed> $manifest_metadata
     */
    public function __construct(
        string $site_root,
        string $working_base_directory,
        string $table_prefix,
        string $table_selection_mode,
        int $database_chunk_size,
        array $manifest_metadata = array()
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
        $this->manifest_metadata = $manifest_metadata;
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

    /**
     * @return array<string,mixed>
     */
    public function manifestMetadata(): array
    {
        return $this->manifest_metadata;
    }
}
```

- [x] **Step 4: Update `BackupResult`**

Replace `super-sheep-copy/src/Backup/BackupResult.php` with:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class BackupResult
{
    private string $job_id;
    private string $working_directory;
    private string $database_directory;
    private string $archive_path;
    private int $archive_size;
    private int $scanned_file_count;
    private int $database_file_count;
    private string $state;

    public function __construct(
        string $job_id,
        string $working_directory,
        string $database_directory,
        string $archive_path,
        int $archive_size,
        int $scanned_file_count,
        int $database_file_count,
        string $state
    ) {
        $this->job_id = $job_id;
        $this->working_directory = $working_directory;
        $this->database_directory = $database_directory;
        $this->archive_path = $archive_path;
        $this->archive_size = $archive_size;
        $this->scanned_file_count = $scanned_file_count;
        $this->database_file_count = $database_file_count;
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

    public function archivePath(): string
    {
        return $this->archive_path;
    }

    public function archiveSize(): int
    {
        return $this->archive_size;
    }

    public function scannedFileCount(): int
    {
        return $this->scanned_file_count;
    }

    public function databaseFileCount(): int
    {
        return $this->database_file_count;
    }

    public function state(): string
    {
        return $this->state;
    }
}
```

- [x] **Step 5: Add job state constant**

In `super-sheep-copy/src/Jobs/Job.php`, add this constant after `ARCHIVING_FILES`:

```php
public const PACKAGING_ARCHIVE = 'packaging_archive';
```

- [x] **Step 6: Update `BackupManager`**

Replace `super-sheep-copy/src/Backup/BackupManager.php` with:

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
    private BackupArchivePackagerInterface $packager;

    public function __construct(JobRepositoryInterface $jobs, DatabaseBackupCoordinatorInterface $database, FileScanner $files, BackupArchivePackagerInterface $packager)
    {
        $this->jobs = $jobs;
        $this->database = $database;
        $this->files = $files;
        $this->packager = $packager;
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

        $this->save($job_id, Job::PACKAGING_ARCHIVE, array('working_directory' => $working_directory));
        $archive = $this->packager->package($job_id, $working_directory, $database_directory, $files, $options->manifestMetadata());

        $payload = array(
            'working_directory' => $working_directory,
            'database_directory' => $database_directory,
            'archive_path' => $archive->archivePath(),
            'archive_size' => $archive->archiveSize(),
            'scanned_file_count' => $scanned_file_count,
            'database_file_count' => $archive->databaseFileCount(),
        );
        $this->save($job_id, Job::COMPLETED, $payload);

        return new BackupResult(
            $job_id,
            $working_directory,
            $database_directory,
            $archive->archivePath(),
            $archive->archiveSize(),
            $scanned_file_count,
            $archive->databaseFileCount(),
            Job::COMPLETED
        );
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

- [x] **Step 7: Run focused tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupOptionsTest.php tests/Unit/BackupManagerTest.php
```

Expected: PASS with `OK`.

- [x] **Step 8: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 9: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/BackupOptions.php super-sheep-copy/src/Backup/BackupResult.php super-sheep-copy/src/Backup/BackupManager.php super-sheep-copy/src/Jobs/Job.php super-sheep-copy/tests/Unit/BackupOptionsTest.php super-sheep-copy/tests/Unit/BackupManagerTest.php
git commit -m "feat: integrate archive packaging into backup manager"
```

Expected: commit succeeds.

---

### Task 4: Final Verification

**Files:**
- Verify: `super-sheep-copy/src/Backup/ArchivePackageResult.php`
- Verify: `super-sheep-copy/src/Backup/BackupArchivePackagerInterface.php`
- Verify: `super-sheep-copy/src/Backup/BackupArchivePackager.php`
- Verify: `super-sheep-copy/src/Backup/ArchiveWriter.php`
- Verify: `super-sheep-copy/src/Backup/BackupOptions.php`
- Verify: `super-sheep-copy/src/Backup/BackupResult.php`
- Verify: `super-sheep-copy/src/Backup/BackupManager.php`
- Verify: related unit tests.

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

- [x] **Step 3: Confirm packaging classes have no direct WordPress dependency**

Run:

```bash
rg "\\$wpdb|ABSPATH|wp-load|wp_" super-sheep-copy/src/Backup/BackupArchivePackager.php super-sheep-copy/src/Backup/ArchivePackageResult.php super-sheep-copy/src/Backup/BackupArchivePackagerInterface.php super-sheep-copy/src/Backup/BackupManager.php super-sheep-copy/src/Backup/BackupOptions.php super-sheep-copy/src/Backup/BackupResult.php
```

Expected: no matches. If matches appear only inside test fixtures, this command is scoped to production files and should still be clean.

- [x] **Step 4: Check git status**

Run:

```bash
git status --short
```

Expected: empty working tree after task commits.

## Self-Review

- Spec coverage: This plan covers archive package orchestration, database file discovery, checksums keyed by ZIP entry path, manifest metadata injection, `ArchiveWriter` database entry support, manager state transition, completed payload archive details, and result accessors.
- Placeholder scan: The plan has no TBD/TODO placeholders or unspecified implementation steps.
- Type consistency: `BackupManager` depends on `BackupArchivePackagerInterface`; `BackupArchivePackager` implements that interface and returns `ArchivePackageResult`; `ArchiveWriter::write()` accepts `Manifest`, site `ScannedFile[]`, database `ScannedFile[]`, checksum map, and log string in that order.
