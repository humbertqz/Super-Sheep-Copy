# Streaming Backup Manifests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Package large backups without loading complete manifests into PHP memory.

**Architecture:** Pass the scanner JSONL path to the packager. Store byte offsets and counts in the job payload. Read only the next batch and append checksum records; reject incomplete legacy packaging jobs.

**Tech Stack:** PHP 7.4, PHPUnit 9.6, WordPress plugin classes, JSONL manifests.

---

### Task 1: Pass manifests instead of arrays

**Files:** `src/Backup/BackupArchiveStepPackagerInterface.php`, `src/Backup/BackupStepRunner.php`, `tests/Unit/BackupStepRunnerTest.php`

- [ ] Write a test that a `PACKAGING_ARCHIVE` job with `scanned_files_path` passes that string to its packager and never reads all scanned files.
- [ ] Run `vendor/bin/phpunit tests/Unit/BackupStepRunnerTest.php --filter testPackagingPassesTheScannedFileManifestPathWithoutMaterializingFiles`; confirm failure.
- [ ] Change `packageStep()` to accept `string $site_files_manifest_path`; replace `scannedFilesFromPayload()` with a strict path accessor that throws `Restart this backup to use streaming packaging.` for old/incomplete payloads. Update fakes.
- [ ] Re-run the focused test; confirm pass.
- [ ] Commit: `refactor: pass backup file manifest to packager`.

### Task 2: Stream archive sources and checksums

**Files:** `src/Backup/BackupArchiveStepPackager.php`, `tests/Unit/BackupArchiveStepPackagerTest.php`

- [ ] Write tests that a one-entry batch saves a non-zero source byte offset, resumes at the next source record, appends a single checksum JSONL record, and rejects legacy `archive_entries` payloads.
- [ ] Run the focused tests; confirm failures.
- [ ] Add a bounded `fopen`/`fseek`/`fgets` JSONL reader returning records, next offset, and EOF. Initialize new payloads with a streaming-version marker, site/database manifest paths and offsets, total count, and a checksum JSONL path.
- [ ] Generate database archive-entry records incrementally; package batches directly from the reader, append each checksum record after `addFile()`, and retain the current time-budget/progress behavior. Do not use `file()` or complete checksum JSON while packaging.
- [ ] Re-run focused tests; confirm pass.
- [ ] Commit: `fix: stream backup package manifests`.

### Task 3: Build the public archive metadata from checksum JSONL

**Files:** `src/Backup/BackupArchiveStepPackager.php`, `tests/Unit/BackupArchiveStepPackagerTest.php`

- [ ] Write a test that a completed archive contains both checksums in `checksums.json` and `manifest.json` after a multi-step package.
- [ ] Run it; confirm it fails.
- [ ] Serialize checksum JSONL to plugin-owned temporary JSON files using `fgets()`. Add those files to the archive and generate final metadata from the same streamed records; close and remove temporary files safely.
- [ ] Run `vendor/bin/phpunit tests/Unit/BackupArchiveStepPackagerTest.php`; confirm pass.
- [ ] Commit: `fix: build backup checksums from streamed records`.

### Task 4: Verify

**Files:** all changed PHP and PHPUnit files.

- [ ] Run `composer lint`; expect no PHP syntax errors.
- [ ] Run `composer test`; expect all tests pass.
- [ ] Run `git diff --check` and inspect `git status --short`.
