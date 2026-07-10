# Package Checksum Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reject backup packages whose `files/` or `database/` content differs from declared SHA-256 checksums.

**Architecture:** Add `sha256()` to both package-reader interfaces. Validators require one valid hash for each content entry, reject all extra manifest keys, and compare each reader-produced digest. Existing structural checks stay unchanged.

**Tech Stack:** PHP 7.4, PHPUnit 9.6, ZipArchive, PharData, PclZip.

---

## File Structure

- `super-sheep-copy/shared/Archive/PackageReaderInterface.php` — shared digest API.
- `super-sheep-copy/shared/Archive/{Zip,Directory,TarGz,PclZip}PackageReader.php` — shared digest implementations.
- `super-sheep-copy/shared/Archive/PackageValidator.php` — strict checksum enforcement.
- `super-sheep-copy/installer/restore-engine/PackageReaderInterface.php` — standalone digest API.
- `super-sheep-copy/installer/restore-engine/{Zip,Directory,TarGz}PackageReader.php` — standalone digest implementations.
- `super-sheep-copy/installer/restore-engine/ArchiveValidator.php` — standalone strict enforcement.
- `super-sheep-copy/tests/Unit/{PackageReaderTest,ArchiveValidatorTest,InstallerArchiveValidatorTest}.php` — behavior tests.

### Task 1: Add shared reader digest API

**Files:**

- Modify: `super-sheep-copy/shared/Archive/PackageReaderInterface.php`
- Modify: `super-sheep-copy/shared/Archive/ZipPackageReader.php`
- Modify: `super-sheep-copy/shared/Archive/DirectoryPackageReader.php`
- Modify: `super-sheep-copy/shared/Archive/TarGzPackageReader.php`
- Modify: `super-sheep-copy/shared/Archive/PclZipPackageReader.php`
- Test: `super-sheep-copy/tests/Unit/PackageReaderTest.php`

- [ ] **Step 1: Write failing test**

```php
public function testReaderHashesSafeEntry(): void
{
    $reader = new ZipPackageReader($this->archiveWith('files/a.txt', 'body'));

    self::assertSame(hash('sha256', 'body'), $reader->sha256('files/a.txt'));
    self::assertNull($reader->sha256('../wp-config.php'));
}
```

- [ ] **Step 2: Verify RED**

Run: `composer test -- tests/Unit/PackageReaderTest.php`

Expected: FAIL — `sha256()` does not exist.

- [ ] **Step 3: Implement minimal reader operation**

Add `public function sha256(string $entry_path): ?string;` to interface and every reader. Zip reads 8192-byte chunks from `ZipArchive::getStream()` using `hash_init`, `hash_update`, `hash_final`; directory and TAR.GZ use streamed file handles; PclZip hashes existing `read()` result. All reject unsafe/unreadable entries with `null`.

- [ ] **Step 4: Verify GREEN**

Run: `composer test -- tests/Unit/PackageReaderTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add super-sheep-copy/shared/Archive super-sheep-copy/tests/Unit/PackageReaderTest.php
git commit -m "feat: add package entry checksum reader"
```

### Task 2: Enforce shared package checksums

**Files:**

- Modify: `super-sheep-copy/shared/Archive/PackageValidator.php`
- Test: `super-sheep-copy/tests/Unit/ArchiveValidatorTest.php`

- [ ] **Step 1: Write failing strict-validator tests**

```php
public function testRejectsChecksumMismatch(): void
{
    $entries = $this->validEntries();
    $entries['files/index.php'] = 'changed';

    $result = (new ArchiveValidator())->validatePackage($this->createArchive($entries));

    self::assertFalse($result->isValid());
    self::assertContains('Checksum mismatch for archive entry: files/index.php', $result->errors());
}
```

Add cases: matching hashes pass; missing hash; extra hash; out-of-root hash; malformed/empty manifest; invalid digest format.

- [ ] **Step 2: Verify RED**

Run: `composer test -- tests/Unit/ArchiveValidatorTest.php`

Expected: FAIL — current validator accepts `{}`.

- [ ] **Step 3: Implement strict validation**

Parse `checksums.json` as non-empty JSON object. Gather regular `files/` and `database/` entry names. Require lowercase 64-character hex value for each. Compare `$reader->sha256($entry)` to declared value. Append exactly these errors:

```php
'checksums.json must contain SHA-256 checksums.'
'Missing checksum for archive entry: ' . $entry
'Invalid SHA-256 checksum for archive entry: ' . $entry
'Checksum mismatch for archive entry: ' . $entry
'Unexpected checksum for archive entry: ' . $entry
```

Reject checksum keys absent from gathered content entries, including metadata/log/out-of-root paths. Preserve existing structural checks and manifest validation.

- [ ] **Step 4: Verify GREEN**

Run: `composer test -- tests/Unit/ArchiveValidatorTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add super-sheep-copy/shared/Archive/PackageValidator.php super-sheep-copy/tests/Unit/ArchiveValidatorTest.php
git commit -m "fix: verify backup package checksums"
```

### Task 3: Give standalone installer parity

**Files:**

- Modify: `super-sheep-copy/installer/restore-engine/PackageReaderInterface.php`
- Modify: `super-sheep-copy/installer/restore-engine/ZipPackageReader.php`
- Modify: `super-sheep-copy/installer/restore-engine/DirectoryPackageReader.php`
- Modify: `super-sheep-copy/installer/restore-engine/TarGzPackageReader.php`
- Modify: `super-sheep-copy/installer/restore-engine/ArchiveValidator.php`
- Test: `super-sheep-copy/tests/Unit/InstallerArchiveValidatorTest.php`

- [ ] **Step 1: Write failing parity test**

```php
public function testChecksumMismatchFails(): void
{
    $result = (new \SuperSheepCopyInstaller\ArchiveValidator())
        ->validatePackage($this->archive($this->tamperedValidEntries()));

    self::assertFalse($result->isValid());
    self::assertContains('Checksum mismatch for archive entry: files/index.php', $result->errors());
}
```

Add valid, missing, extra, malformed, invalid-format, and directory-package parity cases.

- [ ] **Step 2: Verify RED**

Run: `composer test -- tests/Unit/InstallerArchiveValidatorTest.php`

Expected: FAIL — installer only checks checksum entry presence.

- [ ] **Step 3: Implement matching API and rules**

Add same `sha256()` API, safe-path checks, streamed Zip/TAR behavior, manifest parsing, content collection, and exact Task 2 errors. Keep standalone structural checks intact.

- [ ] **Step 4: Verify GREEN**

Run: `composer test -- tests/Unit/InstallerArchiveValidatorTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add super-sheep-copy/installer/restore-engine super-sheep-copy/tests/Unit/InstallerArchiveValidatorTest.php
git commit -m "fix: enforce installer package checksums"
```

### Task 4: Full regression verification

**Files:**

- Modify: existing test fixtures only if strict validation exposes incomplete fixtures.

- [ ] **Step 1: Run full tests**

Run: `composer test`

Expected: PASS; every fixture intended to be valid has populated matching hashes.

- [ ] **Step 2: Run syntax lint**

Run: `composer lint`

Expected: every PHP file reports no syntax errors.

- [ ] **Step 3: Inspect final state**

Run: `git diff --check && git status --short`

Expected: no whitespace errors; only checksum implementation, tests, and plan changes.

- [ ] **Step 4: Commit fixture repairs if needed**

```bash
git add super-sheep-copy/tests
git commit -m "test: use verified package fixtures"
```

