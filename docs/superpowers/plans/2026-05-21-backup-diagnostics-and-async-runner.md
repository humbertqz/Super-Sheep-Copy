# Backup Diagnostics And Async Runner Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add precise backup progress diagnostics and start a resumable async backup execution path.

**Architecture:** Slice 1 adds a `BackupProgressReporter` interface and job-backed implementation, then wires it into the synchronous backup manager and database coordinator. Slice 2 adds a secured admin AJAX step endpoint and starter job flow while keeping the existing synchronous runner available.

**Tech Stack:** PHP 7.2-compatible WordPress plugin code, PHPUnit 9 unit tests, existing `JobRepositoryInterface`, WordPress admin AJAX/nonces/capabilities.

---

## File Structure

- Create `super-sheep-copy/src/Backup/BackupProgressReporterInterface.php`: small reporting contract.
- Create `super-sheep-copy/src/Backup/JobBackupProgressReporter.php`: saves progress markers to job payload.
- Modify `super-sheep-copy/src/Backup/BackupManager.php`: report phases, completion, and failures.
- Modify `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php`: report table/chunk progress.
- Modify `super-sheep-copy/src/Backup/BackupManagerFactory.php`: construct reporter.
- Modify `super-sheep-copy/templates/backup-page.php`: show latest job progress message.
- Modify `super-sheep-copy/src/Admin/BackupPage.php`: create async job path and provide nonce data for the AJAX handler in Task 5.
- Create `super-sheep-copy/src/Admin/BackupStepAjaxHandler.php`: secured AJAX endpoint for bounded steps.
- Modify tests under `super-sheep-copy/tests/Unit/`.

## Task 1: Progress Reporter Contract

**Files:**
- Create: `super-sheep-copy/src/Backup/BackupProgressReporterInterface.php`
- Create: `super-sheep-copy/src/Backup/JobBackupProgressReporter.php`
- Test: `super-sheep-copy/tests/Unit/JobBackupProgressReporterTest.php`

- [ ] **Step 1: Write failing test**

```php
public function testReportsProgressIntoExistingJobPayload(): void
{
    $jobs = new ProgressReporterJobRepository();
    $jobs->save(new Job('backup-123', 'backup', Job::EXPORTING_DATABASE, array('working_directory' => '/tmp/work')));
    $reporter = new JobBackupProgressReporter($jobs);

    $reporter->report('backup-123', Job::EXPORTING_DATABASE, array(
        'phase' => 'database',
        'step' => 'table_started',
        'table' => 'wp_posts',
        'message' => 'Exporting table wp_posts',
    ));

    $job = $jobs->find('backup-123');
    self::assertSame('database', $job->payload()['phase']);
    self::assertSame('table_started', $job->payload()['step']);
    self::assertSame('wp_posts', $job->payload()['table']);
    self::assertSame('Exporting table wp_posts', $job->payload()['message']);
    self::assertArrayHasKey('updated_at', $job->payload());
}
```

- [ ] **Step 2: Verify red**

Run: `vendor/bin/phpunit tests/Unit/JobBackupProgressReporterTest.php`

Expected: fail because classes do not exist.

- [ ] **Step 3: Implement reporter**

```php
interface BackupProgressReporterInterface
{
    /** @param array<string,mixed> $payload */
    public function report(string $job_id, string $state, array $payload): void;
}
```

`JobBackupProgressReporter` loads existing job, merges payload, adds `updated_at` with `gmdate('c')`, and saves same job type/state.

- [ ] **Step 4: Verify green**

Run: `vendor/bin/phpunit tests/Unit/JobBackupProgressReporterTest.php`

Expected: pass.

## Task 2: Sync Backup Manager Markers

**Files:**
- Modify: `super-sheep-copy/src/Backup/BackupManager.php`
- Test: `super-sheep-copy/tests/Unit/BackupManagerTest.php`

- [ ] **Step 1: Write failing test**

Add assertion that fake reporter receives:

```php
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
```

- [ ] **Step 2: Verify red**

Run: `vendor/bin/phpunit tests/Unit/BackupManagerTest.php`

Expected: fail because `BackupManager` does not accept reporter.

- [ ] **Step 3: Implement minimal wiring**

Add optional `BackupProgressReporterInterface $progress = null` constructor arg. Report before and after database export, file scan, archive packaging, and completion. Preserve current `save()` states.

- [ ] **Step 4: Verify green**

Run: `vendor/bin/phpunit tests/Unit/BackupManagerTest.php`

Expected: pass.

## Task 3: Database Table And Chunk Markers

**Files:**
- Modify: `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseBackupCoordinatorTest.php`

- [ ] **Step 1: Write failing test**

Add reporter fake and assert marker sequence includes `table_started`, `chunk_started`, `chunk_finished`, `table_finished` with table `wp_posts`.

- [ ] **Step 2: Verify red**

Run: `vendor/bin/phpunit tests/Unit/DatabaseBackupCoordinatorTest.php`

Expected: fail because coordinator does not accept reporter/job id.

- [ ] **Step 3: Implement minimal wiring**

Allow `export()` to accept optional `$job_id` and reporter, or inject optional reporter and pass job id from `BackupManager`. Report table/chunk markers without changing SQL output.

- [ ] **Step 4: Verify green**

Run: `vendor/bin/phpunit tests/Unit/DatabaseBackupCoordinatorTest.php`

Expected: pass.

## Task 4: Backup Page Shows Latest Progress

**Files:**
- Modify: `super-sheep-copy/templates/backup-page.php`
- Test: `super-sheep-copy/tests/Unit/BackupPageTest.php`

- [ ] **Step 1: Write failing test**

Create job payload with `message => 'Exporting table wp_posts'`; assert rendered Jobs table includes message.

- [ ] **Step 2: Verify red**

Run: `vendor/bin/phpunit tests/Unit/BackupPageTest.php`

Expected: fail because template has no progress column.

- [ ] **Step 3: Implement column**

Add `Progress` column. Escape payload `message` if present; otherwise render empty string.

- [ ] **Step 4: Verify green**

Run: `vendor/bin/phpunit tests/Unit/BackupPageTest.php`

Expected: pass.

## Task 5: Async Job Starter Skeleton

**Files:**
- Create: `super-sheep-copy/src/Admin/BackupStepAjaxHandler.php`
- Modify: `super-sheep-copy/src/Admin/AdminMenu.php`
- Test: `super-sheep-copy/tests/Unit/BackupStepAjaxHandlerTest.php`

- [ ] **Step 1: Write failing tests**

Test capability and nonce required. Test handler reports `queued`/`running` response for known job without doing long work.

- [ ] **Step 2: Verify red**

Run: `vendor/bin/phpunit tests/Unit/BackupStepAjaxHandlerTest.php`

Expected: fail because handler does not exist.

- [ ] **Step 3: Implement secured skeleton**

Register `wp_ajax_super_sheep_copy_run_backup_step`. Handler verifies capability and nonce, reads `job_id`, loads job, returns JSON shape with state/message. Do not run destructive or long work in this task.

- [ ] **Step 4: Verify green**

Run: `vendor/bin/phpunit tests/Unit/BackupStepAjaxHandlerTest.php`

Expected: pass.

## Task 6: Full Verification

- [ ] Run: `vendor/bin/phpunit`

Expected: all tests pass.

- [ ] Run: `git status --short`

Expected: only intended files modified, plus any pre-existing uncommitted `WpdbClient` fix if not already committed.
