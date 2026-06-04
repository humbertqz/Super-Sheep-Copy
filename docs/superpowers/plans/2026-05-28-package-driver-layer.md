# Package Driver Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add ZIP, TAR.GZ, and directory backup package support so backups can still run when PHP `ZipArchive` is unavailable.

**Architecture:** Introduce package writer and package reader abstractions, then route backup packaging, validation, restore preparation, and installer file restore through those abstractions. ZIP remains first choice and existing ZIP backups remain valid; TAR.GZ uses `PharData`; directory packages use the same internal layout on disk.

**Tech Stack:** PHP 7.4, WordPress plugin conventions, PHPUnit 9.6, `ZipArchive`, `PharData`, PSR-4 autoloading.

---

## File Structure

Create backup package writer files:

- `super-sheep-copy/src/Backup/Package/PackagePathGuard.php`: validates internal package entry paths before writing.
- `super-sheep-copy/src/Backup/Package/PackageWriterInterface.php`: low-level writer contract.
- `super-sheep-copy/src/Backup/Package/ZipPackageWriter.php`: ZIP writer using `ZipArchive`.
- `super-sheep-copy/src/Backup/Package/DirectoryPackageWriter.php`: uncompressed directory writer.
- `super-sheep-copy/src/Backup/Package/TarGzPackageWriter.php`: TAR.GZ writer using a staging directory and `PharData`.
- `super-sheep-copy/src/Backup/Package/PackageWriterFactory.php`: selects the first available writer.

Modify backup files:

- `super-sheep-copy/src/Backup/ArchivePackageResult.php`: add `package_format` and `package_extension`.
- `super-sheep-copy/src/Backup/ArchiveWriter.php`: delegate to `PackageWriterInterface`.
- `super-sheep-copy/src/Backup/BackupArchivePackager.php`: select package writer and use selected extension.
- `super-sheep-copy/src/Backup/BackupArchiveStepPackager.php`: remove direct `ZipArchive` usage and store selected format in payload.
- `super-sheep-copy/src/Backup/BackupManager.php`: persist package format and extension in completed payload.
- `super-sheep-copy/src/Backup/BackupManagerFactory.php`: wire `PackageWriterFactory`.
- `super-sheep-copy/src/Backup/ManifestBuilder.php`: preserve package metadata passed in from packagers.

Create shared package reader/validator files:

- `super-sheep-copy/shared/Archive/PackageReaderInterface.php`: lists entries, reads strings, streams files.
- `super-sheep-copy/shared/Archive/ZipPackageReader.php`: ZIP reader.
- `super-sheep-copy/shared/Archive/TarGzPackageReader.php`: TAR.GZ reader.
- `super-sheep-copy/shared/Archive/DirectoryPackageReader.php`: directory reader.
- `super-sheep-copy/shared/Archive/PackageReaderFactory.php`: chooses reader by extension/path.
- `super-sheep-copy/shared/Archive/PackageValidator.php`: format-agnostic validation rules.
- `super-sheep-copy/shared/Archive/ArchiveValidator.php`: compatibility wrapper around `PackageValidator`.

Modify restore/plugin files:

- `super-sheep-copy/src/Restore/RestorePreparationManager.php`: accept `.zip`, `.tar.gz`, `.tar`; stage uploaded package with matching extension.
- `super-sheep-copy/src/Restore/InstallerPreparationManager.php`: allow staged package basenames for `.zip`, `.tar.gz`, `.tar`, and package directories.
- `super-sheep-copy/src/Admin/RestorePage.php`: list staged package files and staged package directories.
- `super-sheep-copy/templates/restore-page.php`: change wording from ZIP/archive to backup package and update upload accept list.
- `super-sheep-copy/src/Support/EnvironmentChecker.php`: add TAR/GZIP and folder fallback diagnostics.

Modify installer files:

- `super-sheep-copy/installer/restore-engine/ArchiveValidator.php`: mirror shared package validation.
- `super-sheep-copy/installer/restore-engine/FileRestoreManager.php`: read files through package reader instead of `ZipArchive`.
- `super-sheep-copy/installer/restore-engine/PreflightChecker.php`: wording only, from archive to package where visible.
- `super-sheep-copy/installer/restore-engine/DatabaseImportManifestReader.php`: replace direct `ZipArchive` reads of `database/tables.json` and `database/chunks/*.sql` with package reader reads.
- `super-sheep-copy/installer/restore-engine/Bootstrap.php`: wire new installer readers if constructor signatures change.

Modify tests:

- Add `super-sheep-copy/tests/Unit/PackagePathGuardTest.php`.
- Add `super-sheep-copy/tests/Unit/PackageWriterFactoryTest.php`.
- Add `super-sheep-copy/tests/Unit/PackageWriterTest.php`.
- Add `super-sheep-copy/tests/Unit/PackageReaderTest.php`.
- Extend `BackupArchivePackagerTest.php`, `BackupArchiveStepPackagerTest.php`, `BackupManagerTest.php`, `ArchiveValidatorTest.php`, `InstallerArchiveValidatorTest.php`, `FileRestoreManagerTest.php`, `RestorePreparationManagerTest.php`, `RestorePageTest.php`, and `EnvironmentCheckerTest.php`.

## Task 1: Add Package Path Guard

**Files:**
- Create: `super-sheep-copy/src/Backup/Package/PackagePathGuard.php`
- Test: `super-sheep-copy/tests/Unit/PackagePathGuardTest.php`

- [ ] **Step 1: Write failing path guard tests**

Add `super-sheep-copy/tests/Unit/PackagePathGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Package\PackagePathGuard;

final class PackagePathGuardTest extends TestCase
{
    public function testAcceptsSafeRelativePackagePaths(): void
    {
        self::assertTrue(PackagePathGuard::isSafeEntryPath('manifest.json'));
        self::assertTrue(PackagePathGuard::isSafeEntryPath('files/wp-content/uploads/a.jpg'));
        self::assertTrue(PackagePathGuard::isSafeEntryPath('database/chunks/wp_posts.part001.sql'));
    }

    public function testRejectsUnsafePackagePaths(): void
    {
        self::assertFalse(PackagePathGuard::isSafeEntryPath(''));
        self::assertFalse(PackagePathGuard::isSafeEntryPath("../wp-config.php"));
        self::assertFalse(PackagePathGuard::isSafeEntryPath("files/../../wp-config.php"));
        self::assertFalse(PackagePathGuard::isSafeEntryPath('/absolute/path.php'));
        self::assertFalse(PackagePathGuard::isSafeEntryPath('C:/site/wp-config.php'));
        self::assertFalse(PackagePathGuard::isSafeEntryPath("files/bad\0name.php"));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/PackagePathGuardTest.php
```

Expected: FAIL because `PackagePathGuard` does not exist.

- [ ] **Step 3: Implement path guard**

Create `super-sheep-copy/src/Backup/Package/PackagePathGuard.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

final class PackagePathGuard
{
    public static function isSafeEntryPath(string $path): bool
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

    public static function assertSafeEntryPath(string $path): void
    {
        if (!self::isSafeEntryPath($path)) {
            throw new \RuntimeException('Unsafe package entry path: ' . $path);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/PackagePathGuardTest.php
```

Expected: OK.

- [ ] **Step 5: Commit**

```bash
git add super-sheep-copy/src/Backup/Package/PackagePathGuard.php super-sheep-copy/tests/Unit/PackagePathGuardTest.php
git commit -m "Add package path guard"
```

## Task 2: Add Package Writers And Factory

**Files:**
- Create: `super-sheep-copy/src/Backup/Package/PackageWriterInterface.php`
- Create: `super-sheep-copy/src/Backup/Package/ZipPackageWriter.php`
- Create: `super-sheep-copy/src/Backup/Package/DirectoryPackageWriter.php`
- Create: `super-sheep-copy/src/Backup/Package/TarGzPackageWriter.php`
- Create: `super-sheep-copy/src/Backup/Package/PackageWriterFactory.php`
- Test: `super-sheep-copy/tests/Unit/PackageWriterFactoryTest.php`
- Test: `super-sheep-copy/tests/Unit/PackageWriterTest.php`

- [ ] **Step 1: Write failing factory tests**

Create `super-sheep-copy/tests/Unit/PackageWriterFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperSheepCopy\Backup\Package\PackageWriterFactory;
use SuperSheepCopy\Backup\Package\PackageWriterInterface;

final class PackageWriterFactoryTest extends TestCase
{
    public function testSelectsFirstAvailableWriter(): void
    {
        $factory = new PackageWriterFactory(array(
            new FakePackageWriter('zip', '.zip', false),
            new FakePackageWriter('tar.gz', '.tar.gz', true),
            new FakePackageWriter('directory', '', true),
        ));

        self::assertSame('tar.gz', $factory->bestAvailable()->format());
    }

    public function testThrowsWhenNoWriterIsAvailable(): void
    {
        $factory = new PackageWriterFactory(array(new FakePackageWriter('zip', '.zip', false)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No backup package writer is available.');

        $factory->bestAvailable();
    }
}

final class FakePackageWriter implements PackageWriterInterface
{
    private string $format;
    private string $extension;
    private bool $available;

    public function __construct(string $format, string $extension, bool $available)
    {
        $this->format = $format;
        $this->extension = $extension;
        $this->available = $available;
    }

    public function format(): string { return $this->format; }
    public function extension(): string { return $this->extension; }
    public function isAvailable(): bool { return $this->available; }
    public function open(string $package_path): void {}
    public function addFile(string $source_path, string $entry_path): void {}
    public function addString(string $entry_path, string $contents): void {}
    public function close(): void {}
}
```

- [ ] **Step 2: Write failing writer tests**

Create `super-sheep-copy/tests/Unit/PackageWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PharData;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Package\DirectoryPackageWriter;
use SuperSheepCopy\Backup\Package\TarGzPackageWriter;
use SuperSheepCopy\Backup\Package\ZipPackageWriter;
use ZipArchive;

final class PackageWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-package-writer-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/source.txt', 'file body');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDirectoryWriterCreatesPackageLayout(): void
    {
        $writer = new DirectoryPackageWriter();
        $writer->open($this->root . '/package');
        $writer->addString('manifest.json', '{"project":"Super Sheep Copy"}');
        $writer->addFile($this->root . '/source.txt', 'files/source.txt');
        $writer->close();

        self::assertSame('{"project":"Super Sheep Copy"}', file_get_contents($this->root . '/package/manifest.json'));
        self::assertSame('file body', file_get_contents($this->root . '/package/files/source.txt'));
    }

    public function testZipWriterCreatesPackageEntries(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $writer = new ZipPackageWriter();
        $writer->open($this->root . '/package.zip');
        $writer->addString('manifest.json', '{"project":"Super Sheep Copy"}');
        $writer->addFile($this->root . '/source.txt', 'files/source.txt');
        $writer->close();

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->root . '/package.zip'));
        self::assertSame('{"project":"Super Sheep Copy"}', $zip->getFromName('manifest.json'));
        self::assertSame('file body', $zip->getFromName('files/source.txt'));
        $zip->close();
    }

    public function testTarGzWriterCreatesPackageEntries(): void
    {
        if (!class_exists(PharData::class)) {
            self::markTestSkipped('PharData is not available.');
        }

        $writer = new TarGzPackageWriter();
        $writer->open($this->root . '/package.tar.gz');
        $writer->addString('manifest.json', '{"project":"Super Sheep Copy"}');
        $writer->addFile($this->root . '/source.txt', 'files/source.txt');
        $writer->close();

        $tar = new PharData($this->root . '/package.tar.gz');
        self::assertSame('{"project":"Super Sheep Copy"}', file_get_contents('phar://' . $this->root . '/package.tar.gz/manifest.json'));
        self::assertSame('file body', file_get_contents('phar://' . $this->root . '/package.tar.gz/files/source.txt'));
        unset($tar);
    }

    public function testWritersRejectUnsafeEntryPaths(): void
    {
        $writer = new DirectoryPackageWriter();
        $writer->open($this->root . '/package');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsafe package entry path');

        $writer->addString('../wp-config.php', 'bad');
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
```

- [ ] **Step 3: Run tests to verify they fail**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/PackageWriterFactoryTest.php tests/Unit/PackageWriterTest.php
```

Expected: FAIL because package writer classes do not exist.

- [ ] **Step 4: Implement writer interface and factory**

Create `PackageWriterInterface` and `PackageWriterFactory`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

interface PackageWriterInterface
{
    public function format(): string;
    public function extension(): string;
    public function isAvailable(): bool;
    public function open(string $package_path): void;
    public function addFile(string $source_path, string $entry_path): void;
    public function addString(string $entry_path, string $contents): void;
    public function close(): void;
}
```

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

use RuntimeException;

final class PackageWriterFactory
{
    /** @var PackageWriterInterface[] */
    private array $writers;

    /**
     * @param PackageWriterInterface[]|null $writers
     */
    public function __construct(?array $writers = null)
    {
        $this->writers = $writers ?? array(
            new ZipPackageWriter(),
            new TarGzPackageWriter(),
            new DirectoryPackageWriter(),
        );
    }

    public function bestAvailable(): PackageWriterInterface
    {
        foreach ($this->writers as $writer) {
            if ($writer->isAvailable()) {
                return $writer;
            }
        }

        throw new RuntimeException('No backup package writer is available.');
    }
}
```

- [ ] **Step 5: Implement writer classes**

Implement:

```php
final class DirectoryPackageWriter implements PackageWriterInterface
{
    private string $package_path = '';

    public function format(): string { return 'directory'; }
    public function extension(): string { return ''; }
    public function isAvailable(): bool { return true; }

    public function open(string $package_path): void
    {
        $this->package_path = rtrim($package_path, '/\\');
        if (!is_dir($this->package_path) && !mkdir($this->package_path, 0777, true) && !is_dir($this->package_path)) {
            throw new \RuntimeException('Unable to create package directory.');
        }
    }

    public function addFile(string $source_path, string $entry_path): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $destination = $this->package_path . '/' . str_replace('\\', '/', $entry_path);
        $this->ensureDirectory(dirname($destination));
        if (!copy($source_path, $destination)) {
            throw new \RuntimeException('Unable to copy package file.');
        }
    }

    public function addString(string $entry_path, string $contents): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $destination = $this->package_path . '/' . str_replace('\\', '/', $entry_path);
        $this->ensureDirectory(dirname($destination));
        if (file_put_contents($destination, $contents) === false) {
            throw new \RuntimeException('Unable to write package entry.');
        }
    }

    public function close(): void {}

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create package entry directory.');
        }
    }
}
```

Create `ZipPackageWriter` with the same `PackageWriterInterface` methods:

```php
final class ZipPackageWriter implements PackageWriterInterface
{
    private ?\ZipArchive $zip = null;

    public function format(): string { return 'zip'; }
    public function extension(): string { return '.zip'; }
    public function isAvailable(): bool { return class_exists(\ZipArchive::class); }

    public function open(string $package_path): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('ZipArchive is not available.');
        }
        $this->zip = new \ZipArchive();
        $flags = file_exists($package_path) ? 0 : \ZipArchive::CREATE;
        if ($this->zip->open($package_path, $flags) !== true) {
            throw new \RuntimeException('Unable to create ZIP package.');
        }
    }

    public function addFile(string $source_path, string $entry_path): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        if ($this->zip === null || !$this->zip->addFile($source_path, $entry_path)) {
            throw new \RuntimeException('Unable to add ZIP package file.');
        }
    }

    public function addString(string $entry_path, string $contents): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        if ($this->zip === null) {
            throw new \RuntimeException('ZIP package is not open.');
        }
        if ($this->zip->locateName($entry_path) !== false) {
            $this->zip->deleteName($entry_path);
        }
        if (!$this->zip->addFromString($entry_path, $contents)) {
            throw new \RuntimeException('Unable to add ZIP package entry.');
        }
    }

    public function close(): void
    {
        if ($this->zip !== null) {
            $this->zip->close();
            $this->zip = null;
        }
    }
}
```

Create `TarGzPackageWriter` by reusing `DirectoryPackageWriter` into `$package_path . '.staging'`, then on `close()` create `$package_path . '.tmp.tar'` with `PharData`, call `compress(\Phar::GZ)`, rename `$package_path . '.tmp.tar.gz'` to `$package_path`, and remove staging plus temporary tar.

- [ ] **Step 6: Run writer tests**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/PackageWriterFactoryTest.php tests/Unit/PackageWriterTest.php
```

Expected: OK, with TAR.GZ skipped only when `PharData` is unavailable.

- [ ] **Step 7: Commit**

```bash
git add super-sheep-copy/src/Backup/Package super-sheep-copy/tests/Unit/PackageWriterFactoryTest.php super-sheep-copy/tests/Unit/PackageWriterTest.php
git commit -m "Add backup package writers"
```

## Task 3: Route Full Backup Packager Through Package Writers

**Files:**
- Modify: `super-sheep-copy/src/Backup/ArchivePackageResult.php`
- Modify: `super-sheep-copy/src/Backup/ArchiveWriter.php`
- Modify: `super-sheep-copy/src/Backup/BackupArchivePackager.php`
- Modify: `super-sheep-copy/src/Backup/BackupManager.php`
- Modify: `super-sheep-copy/src/Backup/BackupManagerFactory.php`
- Test: `super-sheep-copy/tests/Unit/BackupArchivePackagerTest.php`
- Test: `super-sheep-copy/tests/Unit/BackupManagerTest.php`

- [ ] **Step 1: Add failing packager assertions**

Extend `BackupArchivePackagerTest` with a fake package writer factory that returns `DirectoryPackageWriter`, then assert:

```php
self::assertSame($this->root . '/working/backup-123', $result->archivePath());
self::assertSame('directory', $result->packageFormat());
self::assertSame('', $result->packageExtension());
self::assertFileExists($this->root . '/working/backup-123/manifest.json');
self::assertFileExists($this->root . '/working/backup-123/files/uploads/a.txt');
```

Also assert manifest fields:

```php
$manifest = json_decode((string) file_get_contents($this->root . '/working/backup-123/manifest.json'), true);
self::assertSame('directory', $manifest['package_format']);
self::assertSame('', $manifest['package_extension']);
self::assertSame(1, $manifest['package_schema_version']);
```

- [ ] **Step 2: Run focused test to verify failure**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/BackupArchivePackagerTest.php
```

Expected: FAIL because result has no package format and packager still writes `.zip`.

- [ ] **Step 3: Update `ArchivePackageResult`**

Change constructor to:

```php
public function __construct(
    string $archive_path,
    int $archive_size,
    int $site_file_count,
    int $database_file_count,
    array $checksums,
    string $package_format = 'zip',
    string $package_extension = '.zip'
) {
    $this->archive_path = $archive_path;
    $this->archive_size = $archive_size;
    $this->site_file_count = $site_file_count;
    $this->database_file_count = $database_file_count;
    $this->checksums = $checksums;
    $this->package_format = $package_format;
    $this->package_extension = $package_extension;
}
```

Add properties and accessors:

```php
private string $package_format;
private string $package_extension;

public function packageFormat(): string
{
    return $this->package_format;
}

public function packageExtension(): string
{
    return $this->package_extension;
}
```

- [ ] **Step 4: Update `ArchiveWriter`**

Replace direct `ZipArchive` implementation with:

```php
public function write(string $archive_path, Manifest $manifest, array $site_files, array $database_files, array $checksums, string $log, ?PackageWriterInterface $writer = null): void
{
    $writer = $writer ?? new ZipPackageWriter();
    $writer->open($archive_path);
    $writer->addString('manifest.json', $manifest->toJson());
    $writer->addString('checksums.json', (string) json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $writer->addString('logs/backup.log', $log);

    foreach ($site_files as $file) {
        if (!$file->isSymlink()) {
            $writer->addFile($file->absolutePath(), 'files/' . $file->relativePath());
        }
    }

    foreach ($database_files as $file) {
        if (!$file->isSymlink()) {
            $writer->addFile($file->absolutePath(), 'database/' . $file->relativePath());
        }
    }

    $writer->close();
}
```

- [ ] **Step 5: Update `BackupArchivePackager` constructor and package path**

Inject `PackageWriterFactory` and set metadata:

```php
private PackageWriterFactory $package_writer_factory;

public function __construct(ArchiveWriter $archive_writer, ManifestBuilder $manifest_builder, ?PackageWriterFactory $package_writer_factory = null)
{
    $this->archive_writer = $archive_writer;
    $this->manifest_builder = $manifest_builder;
    $this->package_writer_factory = $package_writer_factory ?? new PackageWriterFactory();
}
```

Inside `package()`:

```php
$writer = $this->package_writer_factory->bestAvailable();
$archive_path = rtrim($working_directory, '/\\') . '/' . $job_id . $writer->extension();
$metadata['package_format'] = $writer->format();
$metadata['package_extension'] = $writer->extension();
$metadata['package_schema_version'] = 1;
```

Pass `$writer` into `ArchiveWriter::write()` and return:

```php
return new ArchivePackageResult($archive_path, $archive_size, count($site_files), count($database_files), $checksums, $writer->format(), $writer->extension());
```

- [ ] **Step 6: Persist package metadata from backup manager**

Add to completed payload in `BackupManager`:

```php
'package_format' => $archive->packageFormat(),
'package_extension' => $archive->packageExtension(),
```

- [ ] **Step 7: Wire factory**

In `BackupManagerFactory`, construct:

```php
$packager = new BackupArchivePackager(
    new ArchiveWriter(),
    new ManifestBuilder(defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : '0.1.0', '1'),
    new \SuperSheepCopy\Backup\Package\PackageWriterFactory()
);
```

- [ ] **Step 8: Run tests**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/BackupArchivePackagerTest.php tests/Unit/BackupManagerTest.php
```

Expected: OK.

- [ ] **Step 9: Commit**

```bash
git add super-sheep-copy/src/Backup/ArchivePackageResult.php super-sheep-copy/src/Backup/ArchiveWriter.php super-sheep-copy/src/Backup/BackupArchivePackager.php super-sheep-copy/src/Backup/BackupManager.php super-sheep-copy/src/Backup/BackupManagerFactory.php super-sheep-copy/tests/Unit/BackupArchivePackagerTest.php super-sheep-copy/tests/Unit/BackupManagerTest.php
git commit -m "Route backup packaging through package writers"
```

## Task 4: Route Step Packager Through Package Writers

**Files:**
- Modify: `super-sheep-copy/src/Backup/BackupArchiveStepPackager.php`
- Test: `super-sheep-copy/tests/Unit/BackupArchiveStepPackagerTest.php`

- [ ] **Step 1: Add failing step-packager tests**

Add a test that constructs `BackupArchiveStepPackager` with a `PackageWriterFactory` whose first available writer is `DirectoryPackageWriter`, then assert:

```php
self::assertSame('directory', $payload['package_format']);
self::assertSame('', $payload['package_extension']);
self::assertSame($this->root . '/working/backup-123', $payload['archive_path']);
self::assertFileExists($this->root . '/working/backup-123/files/uploads/a.txt');
```

Add a ZIP regression assertion to existing ZIP test:

```php
self::assertSame('zip', $payload['package_format']);
self::assertSame('.zip', $payload['package_extension']);
```

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/BackupArchiveStepPackagerTest.php
```

Expected: FAIL because step packager hard-codes ZIP.

- [ ] **Step 3: Add factory dependency**

Change constructor:

```php
private PackageWriterFactory $package_writer_factory;

public function __construct(ManifestBuilder $manifest_builder, int $batch_size = self::DEFAULT_BATCH_SIZE, float $time_budget_seconds = self::DEFAULT_TIME_BUDGET_SECONDS, ?PackageWriterFactory $package_writer_factory = null)
{
    $this->manifest_builder = $manifest_builder;
    $this->batch_size = max(1, $batch_size);
    $this->time_budget_seconds = max(0.0, $time_budget_seconds);
    $this->adaptive_limits = new AdaptiveBackupLimits();
    $this->package_writer_factory = $package_writer_factory ?? new PackageWriterFactory();
}
```

- [ ] **Step 4: Store selected format in payload**

In `preparePayload()`:

```php
$writer = $this->package_writer_factory->bestAvailable();
$payload['package_format'] = $writer->format();
$payload['package_extension'] = $writer->extension();
$payload['archive_path'] = rtrim($working_directory, '/\\') . '/' . $job_id . $writer->extension();
```

- [ ] **Step 5: Replace ZIP add-file loop**

Use selected writer each step:

```php
$writer = $this->writerForPayload($payload);
$writer->open($archive_path);
...
$writer->addFile($absolute_path, $archive_name);
...
$writer->close();
```

Add helper:

```php
private function writerForPayload(array $payload): PackageWriterInterface
{
    $format = isset($payload['package_format']) ? (string) $payload['package_format'] : '';
    foreach ($this->package_writer_factory->availableWriters() as $writer) {
        if ($writer->format() === $format) {
            return $writer;
        }
    }

    return $this->package_writer_factory->bestAvailable();
}
```

Also add `availableWriters(): array` to `PackageWriterFactory`.

- [ ] **Step 6: Replace metadata writes**

Refactor `writeMetadata()` to use package writer `addString()` for `manifest.json`, `checksums.json`, and `logs/backup.log`. For ZIP this overwrites entries by deleting existing names inside `ZipPackageWriter::addString()`. For directory this overwrites files.

Metadata array must include:

```php
$metadata['package_format'] = isset($payload['package_format']) ? (string) $payload['package_format'] : 'zip';
$metadata['package_extension'] = isset($payload['package_extension']) ? (string) $payload['package_extension'] : '.zip';
$metadata['package_schema_version'] = 1;
```

- [ ] **Step 7: Run step packager tests**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/BackupArchiveStepPackagerTest.php
```

Expected: OK.

- [ ] **Step 8: Commit**

```bash
git add super-sheep-copy/src/Backup/BackupArchiveStepPackager.php super-sheep-copy/src/Backup/Package/PackageWriterFactory.php super-sheep-copy/tests/Unit/BackupArchiveStepPackagerTest.php
git commit -m "Route step packaging through package writers"
```

## Task 5: Add Shared Package Readers And Validator

**Files:**
- Create: `super-sheep-copy/shared/Archive/PackageReaderInterface.php`
- Create: `super-sheep-copy/shared/Archive/ZipPackageReader.php`
- Create: `super-sheep-copy/shared/Archive/TarGzPackageReader.php`
- Create: `super-sheep-copy/shared/Archive/DirectoryPackageReader.php`
- Create: `super-sheep-copy/shared/Archive/PackageReaderFactory.php`
- Create: `super-sheep-copy/shared/Archive/PackageValidator.php`
- Modify: `super-sheep-copy/shared/Archive/ArchiveValidator.php`
- Test: `super-sheep-copy/tests/Unit/PackageReaderTest.php`
- Test: `super-sheep-copy/tests/Unit/ArchiveValidatorTest.php`

- [ ] **Step 1: Write failing reader tests**

Create `PackageReaderTest` asserting directory reader lists entries and reads strings:

```php
$reader = new DirectoryPackageReader($this->root . '/package');
self::assertContains('manifest.json', $reader->entries());
self::assertSame('body', $reader->read('files/a.txt'));
```

Add ZIP and TAR.GZ reader tests gated by class availability.

- [ ] **Step 2: Write failing validator tests**

Extend `ArchiveValidatorTest`:

```php
public function testValidatesDirectoryPackageStructure(): void
{
    $directory = $this->createDirectoryPackage(array(
        'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
        'checksums.json' => '{}',
        'database/tables.json' => '{}',
        'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
        'files/index.php' => '<?php echo "site";',
    ));

    $result = (new ArchiveValidator())->validatePackage($directory);

    self::assertTrue($result->isValid());
    self::assertSame(5, $result->entryCount());
}
```

Add TAR.GZ equivalent if `PharData` exists.

- [ ] **Step 3: Run tests to verify failure**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/PackageReaderTest.php tests/Unit/ArchiveValidatorTest.php
```

Expected: FAIL because reader classes do not exist and validator is ZIP-only.

- [ ] **Step 4: Implement reader interface**

Use:

```php
interface PackageReaderInterface
{
    /** @return list<string> */
    public function entries(): array;
    public function read(string $entry_path): ?string;
    public function copyToFile(string $entry_path, string $destination_path): bool;
}
```

- [ ] **Step 5: Implement readers and factory**

Factory rules:

```php
if (is_dir($path)) {
    return new DirectoryPackageReader($path);
}
if (substr(strtolower($path), -7) === '.tar.gz' || substr(strtolower($path), -4) === '.tar') {
    return new TarGzPackageReader($path);
}
return new ZipPackageReader($path);
```

Each reader must use `PackagePathGuard`-equivalent safe path logic before reading or copying. Shared code can duplicate safe-path logic in `ArchiveValidator::isSafePath()` or move it to shared namespace.

- [ ] **Step 6: Implement format-agnostic validator**

`PackageValidator` implementation:

```php
$entries = $reader->entries();
foreach ($entries as $name) {
    // same checks as current ArchiveValidator
}
$manifest_json = $reader->read('manifest.json');
```

Then make `ArchiveValidator::validatePackage()` delegate to `PackageValidator`.

- [ ] **Step 7: Run tests**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/PackageReaderTest.php tests/Unit/ArchiveValidatorTest.php
```

Expected: OK.

- [ ] **Step 8: Commit**

```bash
git add super-sheep-copy/shared/Archive super-sheep-copy/tests/Unit/PackageReaderTest.php super-sheep-copy/tests/Unit/ArchiveValidatorTest.php
git commit -m "Add shared package readers and validator"
```

## Task 6: Update Restore Preparation And Restore UI

**Files:**
- Modify: `super-sheep-copy/src/Restore/RestorePreparationManager.php`
- Modify: `super-sheep-copy/src/Admin/RestorePage.php`
- Modify: `super-sheep-copy/templates/restore-page.php`
- Test: `super-sheep-copy/tests/Unit/RestorePreparationManagerTest.php`
- Test: `super-sheep-copy/tests/Unit/RestorePageTest.php`

- [ ] **Step 1: Add failing restore preparation tests**

Add tests:

```php
public function testAcceptsTarGzUploadName(): void
{
    $_FILES['super_sheep_copy_restore_archive'] = array(
        'name' => 'backup.tar.gz',
        'tmp_name' => $this->validPackagePath(),
        'error' => UPLOAD_ERR_OK,
        'size' => 123,
    );
    // assert staged basename ends with .tar.gz
}
```

And:

```php
$this->expectExceptionMessage('Restore package must be a .zip, .tar, or .tar.gz file.');
```

for invalid extension.

- [ ] **Step 2: Add failing UI tests**

Update `RestorePageTest` expected strings:

```php
self::assertStringContainsString('Upload backup package', $html);
self::assertStringContainsString('accept=".zip,.tar,.tar.gz,application/zip,application/gzip,application/x-tar"', $html);
self::assertStringContainsString('No FTP/SFTP uploaded backup packages found yet.', $html);
```

- [ ] **Step 3: Run tests to verify failure**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/RestorePreparationManagerTest.php tests/Unit/RestorePageTest.php
```

Expected: FAIL on ZIP-only validation and UI strings.

- [ ] **Step 4: Update upload extension detection**

In `RestorePreparationManager`, replace ZIP-only check with:

```php
private function packageExtension(string $name): string
{
    $lower = strtolower($name);
    if (substr($lower, -7) === '.tar.gz') {
        return '.tar.gz';
    }
    if (substr($lower, -4) === '.tar') {
        return '.tar';
    }
    if (substr($lower, -4) === '.zip') {
        return '.zip';
    }

    return '';
}
```

Use:

```php
$extension = $this->packageExtension($name);
if ($extension === '') {
    throw new RuntimeException('Restore package must be a .zip, .tar, or .tar.gz file.');
}
...
$basename = $job_id . $extension;
```

- [ ] **Step 5: Update restore template copy**

Change visible strings from ZIP/archive-specific copy to package copy and use:

```php
<input type="file" name="super_sheep_copy_restore_archive" accept=".zip,.tar,.tar.gz,application/zip,application/gzip,application/x-tar" />
```

- [ ] **Step 6: Run tests**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/RestorePreparationManagerTest.php tests/Unit/RestorePageTest.php
```

Expected: OK.

- [ ] **Step 7: Commit**

```bash
git add super-sheep-copy/src/Restore/RestorePreparationManager.php super-sheep-copy/src/Admin/RestorePage.php super-sheep-copy/templates/restore-page.php super-sheep-copy/tests/Unit/RestorePreparationManagerTest.php super-sheep-copy/tests/Unit/RestorePageTest.php
git commit -m "Accept backup packages in restore preparation"
```

## Task 7: Update Installer Package Validation And File Restore

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/ArchiveValidator.php`
- Modify: `super-sheep-copy/installer/restore-engine/FileRestoreManager.php`
- Modify: `super-sheep-copy/installer/restore-engine/DatabaseImportManifestReader.php`
- Test: `super-sheep-copy/tests/Unit/InstallerArchiveValidatorTest.php`
- Test: `super-sheep-copy/tests/Unit/FileRestoreManagerTest.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseImportManifestReaderTest.php`

- [ ] **Step 1: Add failing installer validator tests**

Mirror shared validator directory test in `InstallerArchiveValidatorTest`:

```php
$result = (new \SuperSheepCopyInstaller\ArchiveValidator())->validatePackage($directory);
self::assertTrue($result->isValid());
```

- [ ] **Step 2: Add failing file restore directory package test**

Create a directory package with:

```text
manifest.json
checksums.json
database/tables.json
database/chunks/wp_posts.part001.sql
files/wp-content/themes/theme/style.css
```

Run `FileRestoreManager::restore()` and assert file copied to WordPress root.

- [ ] **Step 3: Run tests to verify failure**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/InstallerArchiveValidatorTest.php tests/Unit/FileRestoreManagerTest.php tests/Unit/DatabaseImportManifestReaderTest.php
```

Expected: FAIL because installer is ZIP-only.

- [ ] **Step 4: Add installer reader support**

Because installer namespace is standalone, add small local reader classes or include shared readers in installer build. Preferred implementation: create installer-local `PackageReaderInterface`, `ZipPackageReader`, `TarGzPackageReader`, `DirectoryPackageReader`, and `PackageReaderFactory` under `installer/restore-engine/`.

Use same interface as shared:

```php
interface PackageReaderInterface
{
    public function entries(): array;
    public function read(string $entry_path): ?string;
    public function copyToFile(string $entry_path, string $destination_path): bool;
}
```

- [ ] **Step 5: Refactor installer validator**

Make `ArchiveValidator::validatePackage()` use `PackageReaderFactory` and same validation rules as shared validator. Preserve current public method names.

- [ ] **Step 6: Refactor file restore**

Replace `ZipArchive` loop with:

```php
$reader = (new PackageReaderFactory())->create($archive_path);
$files = array();
foreach ($reader->entries() as $name) {
    if (substr($name, -1) === '/' || strpos($name, 'files/') !== 0) {
        continue;
    }
    // same safety checks
    if (!$reader->copyToFile($name, $stage_path)) {
        return $this->result(false, 0, array('Unable to stage restore file.'));
    }
    $files[$relative] = $stage_path;
}
```

- [ ] **Step 7: Refactor manifest reader**

`DatabaseImportManifestReader` currently uses `ZipArchive`. Replace `$zip->getFromName('database/tables.json')` with `$reader->read('database/tables.json')`, replace each chunk read with `$reader->read($entry)`, remove `ZipArchive` availability checks, and return the existing error strings for unreadable/missing package entries.

- [ ] **Step 8: Run tests**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/InstallerArchiveValidatorTest.php tests/Unit/FileRestoreManagerTest.php tests/Unit/DatabaseImportManifestReaderTest.php
```

Expected: OK.

- [ ] **Step 9: Commit**

```bash
git add super-sheep-copy/installer/restore-engine super-sheep-copy/tests/Unit/InstallerArchiveValidatorTest.php super-sheep-copy/tests/Unit/FileRestoreManagerTest.php super-sheep-copy/tests/Unit/DatabaseImportManifestReaderTest.php
git commit -m "Support backup packages in installer restore"
```

## Task 8: Update Diagnostics And User-Facing Wording

**Files:**
- Modify: `super-sheep-copy/src/Support/EnvironmentChecker.php`
- Modify: `super-sheep-copy/templates/backup-page.php`
- Modify: `super-sheep-copy/templates/restore-page.php`
- Test: `super-sheep-copy/tests/Unit/EnvironmentCheckerTest.php`
- Test: `super-sheep-copy/tests/Unit/BackupPageTest.php`
- Test: `super-sheep-copy/tests/Unit/RestorePageTest.php`

- [ ] **Step 1: Add failing diagnostics tests**

In `EnvironmentCheckerTest`, assert keys:

```php
$checks = (new EnvironmentChecker())->check();
self::assertArrayHasKey('zip', $checks);
self::assertArrayHasKey('tar_gzip', $checks);
self::assertArrayHasKey('folder_package', $checks);
self::assertSame('TAR/GZIP package support', $checks['tar_gzip']['label']);
self::assertSame('Folder package fallback', $checks['folder_package']['label']);
```

- [ ] **Step 2: Add failing copy tests**

Update expected strings:

```php
self::assertStringContainsString('Create and monitor full-site backup packages.', $html);
self::assertStringContainsString('Latest completed packages are ready in Jobs.', $html);
```

- [ ] **Step 3: Run tests to verify failure**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/EnvironmentCheckerTest.php tests/Unit/BackupPageTest.php tests/Unit/RestorePageTest.php
```

Expected: FAIL on missing diagnostic keys and old strings.

- [ ] **Step 4: Update environment checker**

Add:

```php
'tar_gzip' => array(
    'label' => 'TAR/GZIP package support',
    'value' => class_exists(\PharData::class) ? 'Available' : 'Missing',
    'status' => class_exists(\PharData::class) ? 'ok' : 'warning',
),
'folder_package' => array(
    'label' => 'Folder package fallback',
    'value' => 'Available',
    'status' => 'ok',
),
```

- [ ] **Step 5: Update copy**

Change "archive" to "package" where package format may vary. Do not change internal variable names in this task.

- [ ] **Step 6: Run tests**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/EnvironmentCheckerTest.php tests/Unit/BackupPageTest.php tests/Unit/RestorePageTest.php
```

Expected: OK.

- [ ] **Step 7: Commit**

```bash
git add super-sheep-copy/src/Support/EnvironmentChecker.php super-sheep-copy/templates/backup-page.php super-sheep-copy/templates/restore-page.php super-sheep-copy/tests/Unit/EnvironmentCheckerTest.php super-sheep-copy/tests/Unit/BackupPageTest.php super-sheep-copy/tests/Unit/RestorePageTest.php
git commit -m "Update backup package diagnostics and copy"
```

## Task 9: Final Verification And Regression Pass

**Files:**
- Verify all modified PHP files.

- [ ] **Step 1: Run focused package tests**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/PackagePathGuardTest.php tests/Unit/PackageWriterFactoryTest.php tests/Unit/PackageWriterTest.php tests/Unit/PackageReaderTest.php tests/Unit/ArchiveValidatorTest.php tests/Unit/InstallerArchiveValidatorTest.php
```

Expected: OK. TAR.GZ tests skip only when `PharData` is unavailable.

- [ ] **Step 2: Run focused backup/restore tests**

Run:

```bash
cd super-sheep-copy
./vendor/bin/phpunit tests/Unit/BackupArchivePackagerTest.php tests/Unit/BackupArchiveStepPackagerTest.php tests/Unit/BackupManagerTest.php tests/Unit/RestorePreparationManagerTest.php tests/Unit/FileRestoreManagerTest.php tests/Unit/DatabaseImportManifestReaderTest.php tests/Unit/RestorePageTest.php
```

Expected: OK.

- [ ] **Step 3: Run lint**

Run:

```bash
cd super-sheep-copy
composer run lint
```

Expected: every PHP file reports `No syntax errors detected`.

- [ ] **Step 4: Run full test suite**

Run:

```bash
cd super-sheep-copy
composer test
```

Expected: OK.

- [ ] **Step 5: Search for direct ZIP-only dependencies**

Run:

```bash
cd super-sheep-copy
rg -n "ZipArchive|backup ZIP|ZIP files|\\.zip" src shared installer templates tests
```

Expected: remaining `ZipArchive` references are only inside ZIP-specific writer/reader/validator classes and ZIP-specific tests. Remaining `.zip` text appears only where listing supported formats or testing legacy ZIP behavior.

- [ ] **Step 6: Commit verification fixes**

If any verification step required code or test changes, commit those changes:

```bash
git add super-sheep-copy
git commit -m "Clean up package driver integration"
```

If no files changed after verification, skip this step.
