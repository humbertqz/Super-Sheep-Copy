# Restore Preparation and Archive Validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a safe restore-preparation workflow that validates uploaded backup ZIP structure, stages the archive privately, records a restore-preparation job, and updates the Restore admin page with a nonce-protected upload form.

**Architecture:** Extend the shared archive validator to validate package structure without extracting. Add `RestorePreparationManagerInterface`, `RestorePreparationManager`, and `RestorePreparationResult` under `src/Restore/`, then wire the manager into `RestorePage`, `AdminMenu`, and `Plugin`.

**Tech Stack:** PHP 7.4+, WordPress admin APIs, `ZipArchive`, Composer PSR-4 autoloading, PHPUnit 9.6, local filesystem temp directories.

---

## Scope Check

This plan implements `docs/superpowers/specs/2026-05-16-restore-preparation-archive-validation-design.md`.

Included:
- ZIP package structure validation.
- Archive validation result object.
- Restore preparation result object.
- Restore preparation manager and interface.
- Restore page multipart upload form and nonce-protected POST handling.
- Restore manager wiring through `AdminMenu` and `Plugin`.

Excluded:
- Archive extraction.
- Checksum verification.
- Database import.
- File replacement.
- URL replacement during restore.
- Rollback creation.
- Installer token generation.
- Installer copy/launch.
- Restore progress UI.
- Displaying staged archive paths.

## File Structure

- Create `super-sheep-copy/shared/Archive/ArchiveValidationResult.php`
  - Immutable validation result value object.
- Modify `super-sheep-copy/shared/Archive/ArchiveValidator.php`
  - Add `validatePackage()`.
- Modify `super-sheep-copy/shared/Archive/ArchiveValidatorInterface.php`
  - Add package validation contract.
- Modify `super-sheep-copy/tests/Unit/ArchiveValidatorTest.php`
  - Add ZIP package validation tests.
- Create `super-sheep-copy/src/Restore/RestorePreparationResult.php`
  - Safe result returned after staging.
- Create `super-sheep-copy/src/Restore/RestorePreparationManagerInterface.php`
  - Contract consumed by `RestorePage`.
- Create `super-sheep-copy/src/Restore/RestorePreparationManager.php`
  - Validates upload metadata, stages archive, saves restore job states.
- Create `super-sheep-copy/tests/Unit/RestorePreparationManagerTest.php`
  - Covers valid staging and invalid upload states.
- Modify `super-sheep-copy/src/Admin/RestorePage.php`
  - Add POST handling, redirects, status, and manager dependency.
- Modify `super-sheep-copy/templates/restore-page.php`
  - Add multipart form and notices.
- Create `super-sheep-copy/tests/Unit/RestorePageTest.php`
  - Covers form rendering and POST success/failure.
- Modify `super-sheep-copy/src/Admin/AdminMenu.php`
  - Inject restore preparation manager into `RestorePage`.
- Modify `super-sheep-copy/src/Plugin.php`
  - Construct real restore preparation manager.

---

### Task 1: Archive Package Validation

**Files:**
- Create: `super-sheep-copy/shared/Archive/ArchiveValidationResult.php`
- Modify: `super-sheep-copy/shared/Archive/ArchiveValidatorInterface.php`
- Modify: `super-sheep-copy/shared/Archive/ArchiveValidator.php`
- Modify: `super-sheep-copy/tests/Unit/ArchiveValidatorTest.php`

- [x] **Step 1: Write the failing tests**

Append these tests and helper method to `super-sheep-copy/tests/Unit/ArchiveValidatorTest.php`:

```php
    public function testValidatesBackupPackageStructure(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'manifest.json' => json_encode(array(
                'project' => 'Super Sheep Copy',
                'source_site_url' => 'https://source.example',
                'source_home_url' => 'https://source.example',
            )),
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertTrue($result->isValid());
        self::assertSame(array(), $result->errors());
        self::assertSame('Super Sheep Copy', $result->manifest()['project']);
        self::assertSame(3, $result->entryCount());
        self::assertSame(1, $result->databaseEntryCount());
    }

    public function testRejectsPackageWithoutManifest(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertFalse($result->isValid());
        self::assertContains('Missing manifest.json.', $result->errors());
    }

    public function testRejectsPackageWithUnsafeEntry(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            '../wp-config.php' => 'bad',
            'database/tables.json' => '{}',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertFalse($result->isValid());
        self::assertContains('Unsafe archive entry: ../wp-config.php', $result->errors());
    }

    public function testRejectsPackageWithoutDatabaseEntries(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            'files/index.php' => '<?php echo "site";',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertFalse($result->isValid());
        self::assertContains('No database entries were found.', $result->errors());
    }

    /**
     * @param array<string,string|false> $entries
     */
    private function createArchive(array $entries): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'ssc-archive-validator-');
        self::assertIsString($archive);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents === false ? '' : $contents);
        }

        $zip->close();

        return $archive;
    }
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/ArchiveValidatorTest.php
```

Expected: FAIL with `Call to undefined method SuperSheepCopy\Shared\Archive\ArchiveValidator::validatePackage()`.

- [x] **Step 3: Add validation result**

Create `super-sheep-copy/shared/Archive/ArchiveValidationResult.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

final class ArchiveValidationResult
{
    private bool $valid;
    /** @var string[] */
    private array $errors;
    /** @var array<string,mixed> */
    private array $manifest;
    private int $entry_count;
    private int $database_entry_count;

    /**
     * @param string[] $errors
     * @param array<string,mixed> $manifest
     */
    public function __construct(bool $valid, array $errors, array $manifest, int $entry_count, int $database_entry_count)
    {
        $this->valid = $valid;
        $this->errors = $errors;
        $this->manifest = $manifest;
        $this->entry_count = $entry_count;
        $this->database_entry_count = $database_entry_count;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return $this->manifest;
    }

    public function entryCount(): int
    {
        return $this->entry_count;
    }

    public function databaseEntryCount(): int
    {
        return $this->database_entry_count;
    }
}
```

- [x] **Step 4: Update validator interface**

Replace `super-sheep-copy/shared/Archive/ArchiveValidatorInterface.php` with:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

interface ArchiveValidatorInterface
{
    public function isSafePath(string $path): bool;

    public function validatePackage(string $archive_path): ArchiveValidationResult;
}
```

- [x] **Step 5: Update archive validator**

Replace `super-sheep-copy/shared/Archive/ArchiveValidator.php` with:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

use ZipArchive;

final class ArchiveValidator implements ArchiveValidatorInterface
{
    public function isSafePath(string $path): bool
    {
        if ($path === '' || strpos($path, "\0") !== false) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);

        if ($normalized[0] === '/' || preg_match('#^[A-Za-z]:/#', $normalized) === 1) {
            return false;
        }

        foreach (explode('/', $normalized) as $part) {
            if ($part === '..') {
                return false;
            }
        }

        return true;
    }

    public function validatePackage(string $archive_path): ArchiveValidationResult
    {
        $errors = array();
        $manifest = array();
        $entry_count = 0;
        $database_entry_count = 0;
        $has_manifest = false;
        $has_checksums = false;

        if (!class_exists(ZipArchive::class)) {
            return new ArchiveValidationResult(false, array('ZipArchive is not available.'), array(), 0, 0);
        }

        $zip = new ZipArchive();
        if ($zip->open($archive_path) !== true) {
            return new ArchiveValidationResult(false, array('Unable to open archive.'), array(), 0, 0);
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name)) {
                $errors[] = 'Unable to read archive entry name.';
                continue;
            }

            $entry_count++;
            if (!$this->isSafePath($name)) {
                $errors[] = 'Unsafe archive entry: ' . $name;
            }

            if ($name === 'manifest.json') {
                $has_manifest = true;
            }

            if ($name === 'checksums.json') {
                $has_checksums = true;
            }

            if (strpos($name, 'database/') === 0 && substr($name, -1) !== '/') {
                $database_entry_count++;
            }
        }

        if (!$has_manifest) {
            $errors[] = 'Missing manifest.json.';
        }

        if (!$has_checksums) {
            $errors[] = 'Missing checksums.json.';
        }

        if ($database_entry_count === 0) {
            $errors[] = 'No database entries were found.';
        }

        if ($has_manifest) {
            $manifest_json = $zip->getFromName('manifest.json');
            $decoded = is_string($manifest_json) ? json_decode($manifest_json, true) : null;
            if (!is_array($decoded)) {
                $errors[] = 'manifest.json is not valid JSON.';
            } else {
                $manifest = $decoded;
                if (($manifest['project'] ?? null) !== 'Super Sheep Copy') {
                    $errors[] = 'Archive manifest project is not Super Sheep Copy.';
                }
            }
        }

        $zip->close();

        return new ArchiveValidationResult($errors === array(), $errors, $manifest, $entry_count, $database_entry_count);
    }
}
```

- [x] **Step 6: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/ArchiveValidatorTest.php
```

Expected: PASS, or SKIPPED for ZIP package tests only if `ZipArchive` is unavailable.

- [x] **Step 7: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 8: Commit**

Run:

```bash
git add super-sheep-copy/shared/Archive/ArchiveValidationResult.php super-sheep-copy/shared/Archive/ArchiveValidatorInterface.php super-sheep-copy/shared/Archive/ArchiveValidator.php super-sheep-copy/tests/Unit/ArchiveValidatorTest.php
git commit -m "feat: validate backup archive packages"
```

Expected: commit succeeds.

---

### Task 2: Restore Preparation Manager

**Files:**
- Create: `super-sheep-copy/src/Restore/RestorePreparationResult.php`
- Create: `super-sheep-copy/src/Restore/RestorePreparationManagerInterface.php`
- Create: `super-sheep-copy/src/Restore/RestorePreparationManager.php`
- Create: `super-sheep-copy/tests/Unit/RestorePreparationManagerTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/RestorePreparationManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Restore\RestorePreparationManager;
use SuperSheepCopy\Shared\Archive\ArchiveValidator;
use ZipArchive;

final class RestorePreparationManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-restore-prep-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPreparesValidRestoreArchive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $jobs = new MemoryRestoreJobRepository();
        $manager = new RestorePreparationManager(new ArchiveValidator(), $jobs, $this->root . '/restore');
        $upload = $this->upload('backup.zip', $this->validArchive());

        $result = $manager->prepare($upload);

        self::assertSame(Job::COMPLETED, $result->state());
        self::assertSame('https://source.example', $result->sourceSiteUrl());
        self::assertSame('https://source.example/home', $result->sourceHomeUrl());
        self::assertSame(1, $result->databaseEntryCount());
        self::assertSame(3, $result->archiveEntryCount());
        self::assertStringStartsWith('restore-', $result->stagedArchiveBasename());
        self::assertStringEndsWith('.zip', $result->stagedArchiveBasename());
        self::assertFileExists($this->root . '/restore/' . $result->stagedArchiveBasename());
        self::assertSame(array(Job::VALIDATING_RESTORE, Job::COMPLETED), $jobs->states());

        $completed = $jobs->find($result->jobId());
        self::assertInstanceOf(Job::class, $completed);
        self::assertSame('restore', $completed->type());
        self::assertSame($result->stagedArchiveBasename(), $completed->payload()['staged_archive']);
        self::assertSame('https://source.example', $completed->payload()['source_site_url']);
        self::assertSame(1, $completed->payload()['database_entry_count']);
    }

    public function testRejectsUploadError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore archive upload failed.');

        $manager = new RestorePreparationManager(new ArchiveValidator(), new MemoryRestoreJobRepository(), $this->root . '/restore');
        $manager->prepare(array('name' => 'backup.zip', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0));
    }

    public function testRejectsNonZipUpload(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore archive must be a .zip file.');

        $file = $this->root . '/backup.txt';
        file_put_contents($file, 'not a zip');

        $manager = new RestorePreparationManager(new ArchiveValidator(), new MemoryRestoreJobRepository(), $this->root . '/restore');
        $manager->prepare($this->upload('backup.txt', $file));
    }

    private function validArchive(): string
    {
        $archive = $this->root . '/backup.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', json_encode(array(
            'project' => 'Super Sheep Copy',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
        )));
        $zip->addFromString('checksums.json', '{}');
        $zip->addFromString('database/tables.json', '{}');
        $zip->close();

        return $archive;
    }

    /**
     * @return array{name:string,tmp_name:string,error:int,size:int}
     */
    private function upload(string $name, string $path): array
    {
        return array('name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path) ?: 0);
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

final class MemoryRestoreJobRepository implements JobRepositoryInterface
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
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RestorePreparationManagerTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Restore\RestorePreparationManager" not found`.

- [x] **Step 3: Add result object**

Create `super-sheep-copy/src/Restore/RestorePreparationResult.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

final class RestorePreparationResult
{
    private string $job_id;
    private string $staged_archive_basename;
    private string $source_site_url;
    private string $source_home_url;
    private int $database_entry_count;
    private int $archive_entry_count;
    private string $state;

    public function __construct(
        string $job_id,
        string $staged_archive_basename,
        string $source_site_url,
        string $source_home_url,
        int $database_entry_count,
        int $archive_entry_count,
        string $state
    ) {
        $this->job_id = $job_id;
        $this->staged_archive_basename = $staged_archive_basename;
        $this->source_site_url = $source_site_url;
        $this->source_home_url = $source_home_url;
        $this->database_entry_count = $database_entry_count;
        $this->archive_entry_count = $archive_entry_count;
        $this->state = $state;
    }

    public function jobId(): string
    {
        return $this->job_id;
    }

    public function stagedArchiveBasename(): string
    {
        return $this->staged_archive_basename;
    }

    public function sourceSiteUrl(): string
    {
        return $this->source_site_url;
    }

    public function sourceHomeUrl(): string
    {
        return $this->source_home_url;
    }

    public function databaseEntryCount(): int
    {
        return $this->database_entry_count;
    }

    public function archiveEntryCount(): int
    {
        return $this->archive_entry_count;
    }

    public function state(): string
    {
        return $this->state;
    }
}
```

- [x] **Step 4: Add manager interface**

Create `super-sheep-copy/src/Restore/RestorePreparationManagerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

interface RestorePreparationManagerInterface
{
    /**
     * @param array<string,mixed> $upload
     */
    public function prepare(array $upload): RestorePreparationResult;
}
```

- [x] **Step 5: Add manager implementation**

Create `super-sheep-copy/src/Restore/RestorePreparationManager.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

use RuntimeException;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Shared\Archive\ArchiveValidatorInterface;

final class RestorePreparationManager implements RestorePreparationManagerInterface
{
    private ArchiveValidatorInterface $archive_validator;
    private JobRepositoryInterface $jobs;
    private string $staging_directory;

    public function __construct(ArchiveValidatorInterface $archive_validator, JobRepositoryInterface $jobs, string $staging_directory)
    {
        $this->archive_validator = $archive_validator;
        $this->jobs = $jobs;
        $this->staging_directory = $staging_directory;
    }

    public function prepare(array $upload): RestorePreparationResult
    {
        $this->assertUpload($upload);

        $job_id = 'restore-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $this->save($job_id, Job::VALIDATING_RESTORE, array());

        $tmp_name = (string) $upload['tmp_name'];
        $validation = $this->archive_validator->validatePackage($tmp_name);
        if (!$validation->isValid()) {
            throw new RuntimeException('Restore archive is not a valid Super Sheep Copy backup.');
        }

        $this->ensureDirectory($this->staging_directory);
        $basename = $job_id . '.zip';
        $destination = rtrim($this->staging_directory, '/\\') . '/' . $basename;
        if (!copy($tmp_name, $destination)) {
            throw new RuntimeException('Unable to stage restore archive.');
        }

        $manifest = $validation->manifest();
        $source_site_url = isset($manifest['source_site_url']) ? (string) $manifest['source_site_url'] : '';
        $source_home_url = isset($manifest['source_home_url']) ? (string) $manifest['source_home_url'] : '';
        $payload = array(
            'staged_archive' => $basename,
            'source_site_url' => $source_site_url,
            'source_home_url' => $source_home_url,
            'database_entry_count' => $validation->databaseEntryCount(),
            'archive_entry_count' => $validation->entryCount(),
        );
        $this->save($job_id, Job::COMPLETED, $payload);

        return new RestorePreparationResult(
            $job_id,
            $basename,
            $source_site_url,
            $source_home_url,
            $validation->databaseEntryCount(),
            $validation->entryCount(),
            Job::COMPLETED
        );
    }

    private function assertUpload(array $upload): void
    {
        $error = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Restore archive upload failed.');
        }

        $name = isset($upload['name']) ? (string) $upload['name'] : '';
        if (strtolower(substr($name, -4)) !== '.zip') {
            throw new RuntimeException('Restore archive must be a .zip file.');
        }

        $tmp_name = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
        if ($tmp_name === '' || !is_readable($tmp_name)) {
            throw new RuntimeException('Restore archive upload is not readable.');
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create restore staging directory.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function save(string $job_id, string $state, array $payload): void
    {
        $this->jobs->save(new Job($job_id, 'restore', $state, $payload));
    }
}
```

- [x] **Step 6: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RestorePreparationManagerTest.php
```

Expected: PASS, or SKIPPED only for the ZIP-dependent valid archive test when `ZipArchive` is unavailable.

- [x] **Step 7: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 8: Commit**

Run:

```bash
git add super-sheep-copy/src/Restore/RestorePreparationResult.php super-sheep-copy/src/Restore/RestorePreparationManagerInterface.php super-sheep-copy/src/Restore/RestorePreparationManager.php super-sheep-copy/tests/Unit/RestorePreparationManagerTest.php
git commit -m "feat: prepare restore archives"
```

Expected: commit succeeds.

---

### Task 3: Restore Page Upload Action

**Files:**
- Modify: `super-sheep-copy/src/Admin/RestorePage.php`
- Modify: `super-sheep-copy/templates/restore-page.php`
- Create: `super-sheep-copy/tests/Unit/RestorePageTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/RestorePageTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Admin\RestorePage;
use SuperSheepCopy\Restore\RestorePreparationManagerInterface;
use SuperSheepCopy\Restore\RestorePreparationResult;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;
use SuperSheepCopy\Support\LoggerInterface;

final class RestorePageTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = array();
        $_REQUEST = array();
        $_FILES = array();
        $GLOBALS['ssc_test_redirect'] = null;
        $GLOBALS['ssc_test_current_user_can'] = true;
        $GLOBALS['ssc_test_nonce_valid'] = true;
    }

    public function testRenderShowsRestoreUploadForm(): void
    {
        $page = new RestorePage(
            new Capability(),
            new Nonce(),
            new RestorePageEnvironmentChecker(),
            new RestorePageLogger(),
            new RestorePagePreparationManager()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('name="super_sheep_copy_action"', $html);
        self::assertStringContainsString('value="prepare_restore"', $html);
        self::assertStringContainsString('name="super_sheep_copy_restore_archive"', $html);
        self::assertStringContainsString('Validate Backup', $html);
        self::assertStringNotContainsString('disabled', $html);
    }

    public function testPostPreparesRestoreAndRedirectsWithSuccess(): void
    {
        $_POST['super_sheep_copy_action'] = 'prepare_restore';
        $_REQUEST['super_sheep_copy_action'] = 'prepare_restore';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';
        $_FILES['super_sheep_copy_restore_archive'] = array('name' => 'backup.zip', 'tmp_name' => '/tmp/backup.zip', 'error' => UPLOAD_ERR_OK, 'size' => 123);

        $manager = new RestorePagePreparationManager();
        $page = new RestorePage(
            new Capability(),
            new Nonce(),
            new RestorePageEnvironmentChecker(),
            new RestorePageLogger(),
            $manager
        );

        $page->render();

        self::assertSame($_FILES['super_sheep_copy_restore_archive'], $manager->upload());
        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-restore&super_sheep_copy_status=restore_prepared', $GLOBALS['ssc_test_redirect']);
    }

    public function testPostRedirectsWithFailureWhenPreparationFails(): void
    {
        $_POST['super_sheep_copy_action'] = 'prepare_restore';
        $_REQUEST['super_sheep_copy_action'] = 'prepare_restore';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';
        $_FILES['super_sheep_copy_restore_archive'] = array('name' => 'backup.zip', 'tmp_name' => '/tmp/backup.zip', 'error' => UPLOAD_ERR_OK, 'size' => 123);

        $page = new RestorePage(
            new Capability(),
            new Nonce(),
            new RestorePageEnvironmentChecker(),
            new RestorePageLogger(),
            new RestorePagePreparationManager(true)
        );

        $page->render();

        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-restore&super_sheep_copy_status=restore_failed', $GLOBALS['ssc_test_redirect']);
    }
}

final class RestorePagePreparationManager implements RestorePreparationManagerInterface
{
    /** @var array<string,mixed>|null */
    private ?array $upload = null;
    private bool $throw;

    public function __construct(bool $throw = false)
    {
        $this->throw = $throw;
    }

    public function prepare(array $upload): RestorePreparationResult
    {
        if ($this->throw) {
            throw new \RuntimeException('restore failed');
        }

        $this->upload = $upload;

        return new RestorePreparationResult('restore-123', 'restore-123.zip', 'https://source.example', 'https://source.example', 1, 3, 'completed');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function upload(): ?array
    {
        return $this->upload;
    }
}

final class RestorePageEnvironmentChecker implements EnvironmentCheckerInterface
{
    public function check(): array
    {
        return array('zip' => array('label' => 'ZIP', 'value' => 'Available', 'status' => 'ok'));
    }
}

final class RestorePageLogger implements LoggerInterface
{
    public function info(string $message, array $context = array()): void
    {
    }

    public function warning(string $message, array $context = array()): void
    {
    }

    public function error(string $message, array $context = array()): void
    {
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RestorePageTest.php
```

Expected: FAIL because `RestorePage` does not accept a restore preparation manager and the template still has disabled buttons.

- [x] **Step 3: Update `RestorePage`**

Replace `super-sheep-copy/src/Admin/RestorePage.php` with:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Admin;

use SuperSheepCopy\Restore\RestorePreparationManagerInterface;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;
use SuperSheepCopy\Support\LoggerInterface;
use Throwable;

final class RestorePage
{
    private const ACTION_FIELD = 'super_sheep_copy_action';
    private const ACTION_PREPARE_RESTORE = 'prepare_restore';
    private const STATUS_FIELD = 'super_sheep_copy_status';
    private const FILE_FIELD = 'super_sheep_copy_restore_archive';

    private Capability $capability;
    private Nonce $nonce;
    private EnvironmentCheckerInterface $environment_checker;
    private LoggerInterface $logger;
    private RestorePreparationManagerInterface $restore_preparation;

    public function __construct(
        Capability $capability,
        Nonce $nonce,
        EnvironmentCheckerInterface $environment_checker,
        LoggerInterface $logger,
        RestorePreparationManagerInterface $restore_preparation
    ) {
        $this->capability = $capability;
        $this->nonce = $nonce;
        $this->environment_checker = $environment_checker;
        $this->logger = $logger;
        $this->restore_preparation = $restore_preparation;
    }

    public function render(): void
    {
        $this->capability->requireManageBackups();
        if ($this->handlePrepareRestore()) {
            return;
        }

        $environment = $this->environment_checker->check();
        $status = $this->status();
        $nonce_field = $this->nonce->field();
        include SUPER_SHEEP_COPY_DIR . 'templates/restore-page.php';
    }

    private function handlePrepareRestore(): bool
    {
        if (!$this->isPrepareRestoreRequest()) {
            return false;
        }

        $this->capability->assertManageBackups();
        $this->nonce->verifyRequest();

        try {
            $upload = isset($_FILES[self::FILE_FIELD]) && is_array($_FILES[self::FILE_FIELD]) ? $_FILES[self::FILE_FIELD] : array();
            $this->restore_preparation->prepare($upload);
            $this->redirect('restore_prepared');
        } catch (Throwable $throwable) {
            $this->logger->warning('Restore preparation failed.');
            $this->redirect('restore_failed');
        }

        return true;
    }

    private function isPrepareRestoreRequest(): bool
    {
        $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

        return $action === self::ACTION_PREPARE_RESTORE;
    }

    private function redirect(string $status): void
    {
        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'super-sheep-copy-restore',
                self::STATUS_FIELD => $status,
            ),
            admin_url('admin.php')
        ));
    }

    private function status(): string
    {
        return isset($_GET[self::STATUS_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::STATUS_FIELD])) : '';
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RestorePageTest.php
```

Expected: still FAIL because the template has no upload form.

- [x] **Step 5: Update restore template**

Replace `super-sheep-copy/templates/restore-page.php` with:

```php
<?php
/**
 * @var array<string, array{label:string,value:string,status:string}> $environment
 * @var string $nonce_field
 * @var string $status
 */
defined('ABSPATH') || exit;
?>
<div class="wrap super-sheep-copy">
    <h1><?php echo esc_html__('Super Sheep Copy Restore', 'super-sheep-copy'); ?></h1>
    <div class="notice notice-warning inline">
        <p><?php echo esc_html__('Restore preparation validates and stages a backup archive only. It does not modify files or the database in this milestone.', 'super-sheep-copy'); ?></p>
    </div>
    <?php if ($status === 'restore_prepared') : ?>
        <div class="notice notice-success">
            <p><?php echo esc_html__('Restore archive validated and staged successfully.', 'super-sheep-copy'); ?></p>
        </div>
    <?php elseif ($status === 'restore_failed') : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html__('Restore archive preparation failed. Confirm the backup file is a valid Super Sheep Copy ZIP.', 'super-sheep-copy'); ?></p>
        </div>
    <?php endif; ?>
    <div class="super-sheep-copy-panel">
        <h2><?php echo esc_html__('Restore Preparation', 'super-sheep-copy'); ?></h2>
        <p><?php echo esc_html__('Upload a Super Sheep Copy backup ZIP to validate and stage it for a future installer-driven restore.', 'super-sheep-copy'); ?></p>
        <form method="post" enctype="multipart/form-data">
            <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <input type="hidden" name="super_sheep_copy_action" value="prepare_restore" />
            <input type="file" name="super_sheep_copy_restore_archive" accept=".zip,application/zip" />
            <button class="button button-primary" type="submit"><?php echo esc_html__('Validate Backup', 'super-sheep-copy'); ?></button>
        </form>
    </div>
    <div class="super-sheep-copy-panel">
        <h2><?php echo esc_html__('Environment', 'super-sheep-copy'); ?></h2>
        <table class="widefat striped">
            <tbody>
            <?php foreach ($environment as $check) : ?>
                <tr>
                    <th scope="row"><?php echo esc_html($check['label']); ?></th>
                    <td><?php echo esc_html($check['value']); ?></td>
                    <td><?php echo esc_html($check['status']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
```

- [x] **Step 6: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RestorePageTest.php
```

Expected: PASS.

- [x] **Step 7: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 8: Commit**

Run:

```bash
git add super-sheep-copy/src/Admin/RestorePage.php super-sheep-copy/templates/restore-page.php super-sheep-copy/tests/Unit/RestorePageTest.php
git commit -m "feat: handle restore preparation upload"
```

Expected: commit succeeds.

---

### Task 4: Plugin Restore Preparation Wiring

**Files:**
- Modify: `super-sheep-copy/src/Admin/AdminMenu.php`
- Modify: `super-sheep-copy/src/Plugin.php`

- [x] **Step 1: Run full suite to expose wiring failures**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: may FAIL if `AdminMenu::restorePage()` still calls the old `RestorePage` constructor. If it passes because tests do not instantiate that path, continue.

- [x] **Step 2: Update `AdminMenu`**

Modify `super-sheep-copy/src/Admin/AdminMenu.php`:

Add import:

```php
use SuperSheepCopy\Restore\RestorePreparationManagerInterface;
```

Add property:

```php
private RestorePreparationManagerInterface $restore_preparation;
```

Add constructor parameter after `$metadata_collector`:

```php
RestorePreparationManagerInterface $restore_preparation
```

Assign it:

```php
$this->restore_preparation = $restore_preparation;
```

Update `restorePage()`:

```php
return new RestorePage(
    $this->capability,
    $this->nonce,
    $this->environment_checker,
    $this->logger,
    $this->restore_preparation
);
```

- [x] **Step 3: Update `Plugin`**

Modify `super-sheep-copy/src/Plugin.php`:

Add imports:

```php
use SuperSheepCopy\Restore\RestorePreparationManager;
use SuperSheepCopy\Shared\Archive\ArchiveValidator;
```

Add the new constructor argument to `new AdminMenu(...)` after `new BackupMetadataCollector($environment_checker)`:

```php
new RestorePreparationManager(
    new ArchiveValidator(),
    $jobs,
    trailingslashit(self::backupDirectory()) . 'restore'
)
```

- [x] **Step 4: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/src/Admin/AdminMenu.php super-sheep-copy/src/Plugin.php
git commit -m "feat: wire restore preparation services"
```

Expected: commit succeeds.

---

### Task 5: Final Verification

**Files:**
- Verify all files changed in this plan.

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

- [x] **Step 3: Confirm direct request/upload access is scoped**

Run:

```bash
rg '\$_POST|\$_REQUEST|\$_GET|\$_FILES' super-sheep-copy/src
```

Expected: matches are limited to:
- `src/Security/Nonce.php`
- `src/Admin/BackupPage.php`
- `src/Admin/RestorePage.php`

- [x] **Step 4: Check git status**

Run:

```bash
git status --short
```

Expected: empty working tree after task commits.

## Self-Review

- Spec coverage: This plan covers package structure validation, validation result fields, restore upload validation, private staging, restore job persistence, restore page POST handling, notices, service wiring, and final request-global scan.
- Placeholder scan: The plan has no TODO/TBD placeholders or vague implementation steps.
- Type consistency: `ArchiveValidatorInterface::validatePackage()` returns `ArchiveValidationResult`; `RestorePreparationManagerInterface::prepare()` returns `RestorePreparationResult`; `RestorePage` depends on `RestorePreparationManagerInterface`; `AdminMenu` and `Plugin` wire the same interface.
