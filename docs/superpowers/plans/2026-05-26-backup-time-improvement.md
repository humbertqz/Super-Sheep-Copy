# Backup Time Improvement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add backup throughput metrics and adaptive per-step sizing so 2-10 GB sites back up faster while keeping resumable AJAX steps.

**Architecture:** Keep the current `BackupStepRunner` and `BackupArchiveStepPackager` flow. Add small focused helpers for timing metrics and adaptive limits, then wire them into database export, file scan, archive packaging, and admin progress display.

**Tech Stack:** PHP 8.1+ style code, WordPress admin/AJAX APIs, PHPUnit 9 unit tests, `ZipArchive`.

---

## File Structure

- Create `super-sheep-copy/src/Backup/BackupPerformanceMetrics.php`
  - Formats durations, rates, MB/min labels, and bottleneck labels.
- Create `super-sheep-copy/src/Backup/AdaptiveBackupLimits.php`
  - Computes next DB chunk size, file scan batch size, and archive time budget from payload metrics.
- Modify `super-sheep-copy/src/Backup/BackupStepRunner.php`
  - Record database/file scan metrics and use adaptive DB/file limits.
- Modify `super-sheep-copy/src/Backup/BackupArchiveStepPackager.php`
  - Record byte throughput and use adaptive archive time budget.
- Modify `super-sheep-copy/templates/backup-page.php`
  - Render throughput/bottleneck text from payload metrics.
- Modify tests:
  - `super-sheep-copy/tests/Unit/BackupStepRunnerTest.php`
  - `super-sheep-copy/tests/Unit/BackupArchiveStepPackagerTest.php`
  - `super-sheep-copy/tests/Unit/BackupPageTest.php`
  - Add `super-sheep-copy/tests/Unit/AdaptiveBackupLimitsTest.php`

---

### Task 1: Add Adaptive Limit Helper

**Files:**
- Create: `super-sheep-copy/src/Backup/AdaptiveBackupLimits.php`
- Test: `super-sheep-copy/tests/Unit/AdaptiveBackupLimitsTest.php`

- [ ] **Step 1: Write failing tests**

Add `super-sheep-copy/tests/Unit/AdaptiveBackupLimitsTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\AdaptiveBackupLimits;

final class AdaptiveBackupLimitsTest extends TestCase
{
    public function testDatabaseChunkSizeGrowsAfterFastStepWithinCap(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(10000, $limits->databaseChunkSize(array(
            'database_adaptive_chunk_size' => 5000,
            'database_last_step_seconds' => 2.0,
        )));
    }

    public function testDatabaseChunkSizeShrinksAfterSlowStep(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(10000, $limits->databaseChunkSize(array(
            'database_adaptive_chunk_size' => 20000,
            'database_last_step_seconds' => 20.0,
        )));
    }

    public function testFileScanBatchSizeGrowsAfterFastStepWithinCap(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(2000, $limits->fileScanBatchSize(array(
            'file_scan_adaptive_batch_size' => 1000,
            'file_scan_last_step_seconds' => 1.0,
        )));
    }

    public function testArchiveTimeBudgetGrowsAfterFastStepWithinCap(): void
    {
        $limits = new AdaptiveBackupLimits();

        self::assertSame(30.0, $limits->archiveTimeBudgetSeconds(array(
            'archive_adaptive_time_budget_seconds' => 20.0,
            'archive_last_step_seconds' => 5.0,
        )));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/phpunit tests/Unit/AdaptiveBackupLimitsTest.php
```

Expected: fails because `SuperSheepCopy\Backup\AdaptiveBackupLimits` does not exist.

- [ ] **Step 3: Implement helper**

Create `super-sheep-copy/src/Backup/AdaptiveBackupLimits.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class AdaptiveBackupLimits
{
    private const DATABASE_MIN = 5000;
    private const DATABASE_MAX = 50000;
    private const FILE_SCAN_MIN = 1000;
    private const FILE_SCAN_MAX = 5000;
    private const ARCHIVE_MIN_SECONDS = 20.0;
    private const ARCHIVE_MAX_SECONDS = 45.0;

    /**
     * @param array<string,mixed> $payload
     */
    public function databaseChunkSize(array $payload): int
    {
        $current = $this->intPayload($payload, 'database_adaptive_chunk_size', self::DATABASE_MIN);
        $seconds = $this->floatPayload($payload, 'database_last_step_seconds', 0.0);

        if ($seconds > 0.0 && $seconds < 5.0) {
            return min(self::DATABASE_MAX, $current * 2);
        }

        if ($seconds > 15.0) {
            return max(self::DATABASE_MIN, (int) floor($current / 2));
        }

        return max(self::DATABASE_MIN, min(self::DATABASE_MAX, $current));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function fileScanBatchSize(array $payload): int
    {
        $current = $this->intPayload($payload, 'file_scan_adaptive_batch_size', self::FILE_SCAN_MIN);
        $seconds = $this->floatPayload($payload, 'file_scan_last_step_seconds', 0.0);

        if ($seconds > 0.0 && $seconds < 3.0) {
            return min(self::FILE_SCAN_MAX, $current * 2);
        }

        if ($seconds > 10.0) {
            return max(self::FILE_SCAN_MIN, (int) floor($current / 2));
        }

        return max(self::FILE_SCAN_MIN, min(self::FILE_SCAN_MAX, $current));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function archiveTimeBudgetSeconds(array $payload): float
    {
        $current = $this->floatPayload($payload, 'archive_adaptive_time_budget_seconds', self::ARCHIVE_MIN_SECONDS);
        $seconds = $this->floatPayload($payload, 'archive_last_step_seconds', 0.0);

        if ($seconds > 0.0 && $seconds < 10.0) {
            return min(self::ARCHIVE_MAX_SECONDS, $current + 10.0);
        }

        if ($seconds > 45.0) {
            return max(self::ARCHIVE_MIN_SECONDS, $current - 10.0);
        }

        return max(self::ARCHIVE_MIN_SECONDS, min(self::ARCHIVE_MAX_SECONDS, $current));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function intPayload(array $payload, string $key, int $default): int
    {
        return isset($payload[$key]) && is_numeric($payload[$key]) ? (int) $payload[$key] : $default;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function floatPayload(array $payload, string $key, float $default): float
    {
        return isset($payload[$key]) && is_numeric($payload[$key]) ? (float) $payload[$key] : $default;
    }
}
```

- [ ] **Step 4: Run tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/AdaptiveBackupLimitsTest.php
```

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add super-sheep-copy/src/Backup/AdaptiveBackupLimits.php super-sheep-copy/tests/Unit/AdaptiveBackupLimitsTest.php
git commit -m "feat: add adaptive backup limits"
```

---

### Task 2: Add Database Export Metrics And Adaptive Chunk Size

**Files:**
- Modify: `super-sheep-copy/src/Backup/BackupStepRunner.php`
- Modify: `super-sheep-copy/tests/Unit/BackupStepRunnerTest.php`

- [ ] **Step 1: Write failing test**

Add to `BackupStepRunnerTest`:

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupStepRunnerTest.php --filter testDatabaseExportRecordsThroughputAndAdaptiveChunkSize
```

Expected: fails because metric keys are missing.

- [ ] **Step 3: Update runner imports and property**

Modify `BackupStepRunner.php`:

```php
private AdaptiveBackupLimits $adaptive_limits;
```

In constructor after `$this->file_scan_batch_size = max(1, $file_scan_batch_size);`:

```php
$this->adaptive_limits = new AdaptiveBackupLimits();
```

- [ ] **Step 4: Use adaptive chunk size and record metrics**

In `exportNextDatabaseChunk()`, replace:

```php
$chunk_size = (int) $this->intPayload($payload, 'database_chunk_size');
```

with:

```php
$chunk_size = $this->adaptive_limits->databaseChunkSize($payload);
$payload['database_adaptive_chunk_size'] = $chunk_size;
```

Immediately before `$rows = $this->database->fetchRows($plan, $columns);` add:

```php
$step_start = microtime(true);
```

Immediately after `$this->writeChunk(...)` add:

```php
$step_seconds = max(0.001, microtime(true) - $step_start);
$step_rows = count($rows->rows());
$payload['database_last_step_seconds'] = $step_seconds;
$payload['database_last_step_rows'] = $step_rows;
$payload['database_rows_per_second'] = $step_rows / $step_seconds;
```

- [ ] **Step 5: Run target test**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupStepRunnerTest.php --filter testDatabaseExportRecordsThroughputAndAdaptiveChunkSize
```

Expected: pass.

- [ ] **Step 6: Run full runner tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupStepRunnerTest.php
```

Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add super-sheep-copy/src/Backup/BackupStepRunner.php super-sheep-copy/tests/Unit/BackupStepRunnerTest.php
git commit -m "feat: record database backup throughput"
```

---

### Task 3: Add File Scan Metrics And Adaptive Batch Size

**Files:**
- Modify: `super-sheep-copy/src/Backup/BackupStepRunner.php`
- Modify: `super-sheep-copy/tests/Unit/BackupStepRunnerTest.php`

- [ ] **Step 1: Write failing test**

Add to `BackupStepRunnerTest`:

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupStepRunnerTest.php --filter testFileScanRecordsThroughputAndAdaptiveBatchSize
```

Expected: fails because metric keys are missing.

- [ ] **Step 3: Update scan step**

In `BackupStepRunner::scanFiles()`, replace:

```php
$payload = $this->files->scanStep($this->stringPayload($payload, 'site_root'), $payload, $this->file_scan_batch_size);
```

with:

```php
$batch_size = max($this->file_scan_batch_size, $this->adaptive_limits->fileScanBatchSize($payload));
$payload['file_scan_adaptive_batch_size'] = $batch_size;
$before_count = isset($payload['scanned_file_count']) ? (int) $payload['scanned_file_count'] : 0;
$step_start = microtime(true);
$payload = $this->files->scanStep($this->stringPayload($payload, 'site_root'), $payload, $batch_size);
$step_seconds = max(0.001, microtime(true) - $step_start);
$after_count = isset($payload['scanned_file_count']) ? (int) $payload['scanned_file_count'] : $before_count;
$step_entries = max(0, $after_count - $before_count);
$payload['file_scan_last_step_seconds'] = $step_seconds;
$payload['file_scan_last_step_entries'] = $step_entries;
$payload['file_scan_entries_per_second'] = $step_entries / $step_seconds;
```

- [ ] **Step 4: Run tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupStepRunnerTest.php
```

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add super-sheep-copy/src/Backup/BackupStepRunner.php super-sheep-copy/tests/Unit/BackupStepRunnerTest.php
git commit -m "feat: record file scan throughput"
```

---

### Task 4: Add Archive Byte Throughput And Adaptive Time Budget

**Files:**
- Modify: `super-sheep-copy/src/Backup/BackupArchiveStepPackager.php`
- Modify: `super-sheep-copy/tests/Unit/BackupArchiveStepPackagerTest.php`

- [ ] **Step 1: Write failing test**

Add to `BackupArchiveStepPackagerTest`:

```php
public function testPackagingRecordsByteThroughputAndAdaptiveBudget(): void
{
    if (!class_exists(ZipArchive::class)) {
        self::markTestSkipped('ZipArchive is not available.');
    }

    $packager = new BackupArchiveStepPackager(new ManifestBuilder('0.1.0', '1'), 2);
    $site_files = array(
        new ScannedFile($this->root . '/site/uploads/a.txt', 'uploads/a.txt', 1, false),
        new ScannedFile($this->root . '/site/uploads/b.txt', 'uploads/b.txt', 1, false),
    );

    $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), array());

    self::assertArrayHasKey('archive_last_step_bytes', $payload);
    self::assertGreaterThan(0, $payload['archive_last_step_bytes']);
    self::assertArrayHasKey('archive_mb_per_second', $payload);
    self::assertArrayHasKey('archive_adaptive_time_budget_seconds', $payload);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupArchiveStepPackagerTest.php --filter testPackagingRecordsByteThroughputAndAdaptiveBudget
```

Expected: fails because byte metrics are missing.

- [ ] **Step 3: Add adaptive limits to packager**

In `BackupArchiveStepPackager`, add property:

```php
private AdaptiveBackupLimits $adaptive_limits;
```

In constructor after `$this->time_budget_seconds = max(0.0, $time_budget_seconds);`:

```php
$this->adaptive_limits = new AdaptiveBackupLimits();
```

After `$step_start_time = microtime(true);`, add:

```php
$effective_time_budget = max($this->time_budget_seconds, $this->adaptive_limits->archiveTimeBudgetSeconds($payload));
$payload['archive_adaptive_time_budget_seconds'] = $effective_time_budget;
$step_bytes = 0;
```

Inside the loop after `$zip->addFile($absolute_path, $archive_name);`, add:

```php
$entry_size = filesize($absolute_path);
if ($entry_size !== false) {
    $step_bytes += (int) $entry_size;
}
```

Replace:

```php
microtime(true) - $step_start_time >= $this->time_budget_seconds
```

with:

```php
microtime(true) - $step_start_time >= $effective_time_budget
```

Replace `addProgressMetrics(...)` signature and call with a bytes argument:

```php
$payload = $this->addProgressMetrics($payload, count($entries), $step_start_index, $step_start_time, $step_bytes);
```

Update method signature:

```php
private function addProgressMetrics(array $payload, int $total_entries, int $step_start_index, float $step_start_time, int $step_bytes): array
```

Inside `addProgressMetrics()`, before return:

```php
$payload['archive_last_step_bytes'] = $step_bytes;
$payload['archive_mb_per_second'] = ($step_bytes / 1048576) / $step_elapsed;
```

- [ ] **Step 4: Update progress message with MB/min**

In `progressMessage()`, add:

```php
$mb_per_second = isset($payload['archive_mb_per_second']) ? (float) $payload['archive_mb_per_second'] : 0.0;
```

After entries/min append:

```php
if ($mb_per_second > 0.0) {
    $message .= ' ' . number_format($mb_per_second * 60, 1) . ' MB/min.';
}
```

- [ ] **Step 5: Run tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupArchiveStepPackagerTest.php
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add super-sheep-copy/src/Backup/BackupArchiveStepPackager.php super-sheep-copy/tests/Unit/BackupArchiveStepPackagerTest.php
git commit -m "feat: record archive backup throughput"
```

---

### Task 5: Show Bottleneck And Throughput In Admin Jobs Table

**Files:**
- Create: `super-sheep-copy/src/Backup/BackupPerformanceMetrics.php`
- Modify: `super-sheep-copy/templates/backup-page.php`
- Modify: `super-sheep-copy/tests/Unit/BackupPageTest.php`

- [ ] **Step 1: Write failing admin render test**

Add to `BackupPageTest`:

```php
public function testRenderShowsBackupThroughputAndBottleneck(): void
{
    $page = new BackupPage(
        new Capability(),
        new Nonce(),
        new BackupPageEnvironmentChecker(),
        new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::PACKAGING_ARCHIVE, array(
            'message' => 'Packaged 500 of 1000 archive entries.',
            'archive_entries_per_second' => 10.0,
            'archive_mb_per_second' => 2.0,
            'archive_eta_seconds' => 60,
            'backup_bottleneck' => 'archive',
        )))),
        new BackupPageFactory(new BackupPageRunner()),
        new BackupPageMetadataCollector()
    );

    ob_start();
    $page->render();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('600 entries/min', $html);
    self::assertStringContainsString('120.0 MB/min', $html);
    self::assertStringContainsString('ETA 1m', $html);
    self::assertStringContainsString('Bottleneck: archive', $html);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupPageTest.php --filter testRenderShowsBackupThroughputAndBottleneck
```

Expected: fails because throughput text is not rendered.

- [ ] **Step 3: Create performance metric formatter**

Create `super-sheep-copy/src/Backup/BackupPerformanceMetrics.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class BackupPerformanceMetrics
{
    /**
     * @param array<string,mixed> $payload
     */
    public function summary(array $payload): string
    {
        $parts = array();
        $entries_per_second = isset($payload['archive_entries_per_second']) ? (float) $payload['archive_entries_per_second'] : 0.0;
        $mb_per_second = isset($payload['archive_mb_per_second']) ? (float) $payload['archive_mb_per_second'] : 0.0;
        $eta = isset($payload['archive_eta_seconds']) && $payload['archive_eta_seconds'] !== null ? (int) $payload['archive_eta_seconds'] : null;
        $bottleneck = isset($payload['backup_bottleneck']) && is_scalar($payload['backup_bottleneck']) ? (string) $payload['backup_bottleneck'] : '';

        if ($entries_per_second > 0.0) {
            $parts[] = number_format($entries_per_second * 60, 0) . ' entries/min';
        }
        if ($mb_per_second > 0.0) {
            $parts[] = number_format($mb_per_second * 60, 1) . ' MB/min';
        }
        if ($eta !== null) {
            $parts[] = 'ETA ' . $this->durationLabel($eta);
        }
        if ($bottleneck !== '') {
            $parts[] = 'Bottleneck: ' . $bottleneck;
        }

        return implode(' · ', $parts);
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remaining_seconds = $seconds % 60;

        return $remaining_seconds > 0 ? $minutes . 'm ' . $remaining_seconds . 's' : $minutes . 'm';
    }
}
```

- [ ] **Step 4: Render summary in backup page**

Near the top of `templates/backup-page.php`, after `$running_states`, add:

```php
$performance_metrics = new \SuperSheepCopy\Backup\BackupPerformanceMetrics();
```

Inside job row payload block, after `$progress_message`, add:

```php
$performance_summary = $performance_metrics->summary($payload);
```

Inside progress stack after progress message span, add:

```php
<?php if ($performance_summary !== '') : ?>
    <small><?php echo esc_html($performance_summary); ?></small>
<?php endif; ?>
```

- [ ] **Step 5: Run tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupPageTest.php
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add super-sheep-copy/src/Backup/BackupPerformanceMetrics.php super-sheep-copy/templates/backup-page.php super-sheep-copy/tests/Unit/BackupPageTest.php
git commit -m "feat: show backup performance metrics"
```

---

### Task 6: Compute Bottleneck Label

**Files:**
- Modify: `super-sheep-copy/src/Backup/BackupPerformanceMetrics.php`
- Modify: `super-sheep-copy/src/Backup/BackupStepRunner.php`
- Modify: `super-sheep-copy/src/Backup/BackupArchiveStepPackager.php`
- Test: existing affected unit tests

- [ ] **Step 1: Write failing formatter test**

Add `BackupPerformanceMetricsTest.php` or extend the admin test with direct formatter coverage:

```php
public function testBottleneckReturnsSlowestPhase(): void
{
    $metrics = new \SuperSheepCopy\Backup\BackupPerformanceMetrics();

    self::assertSame('archive', $metrics->bottleneck(array(
        'database_last_step_seconds' => 2.0,
        'file_scan_last_step_seconds' => 1.0,
        'archive_last_step_seconds' => 20.0,
    )));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupPerformanceMetricsTest.php
```

Expected: fails because `bottleneck()` does not exist.

- [ ] **Step 3: Add bottleneck method**

In `BackupPerformanceMetrics.php`:

```php
/**
 * @param array<string,mixed> $payload
 */
public function bottleneck(array $payload): string
{
    $seconds = array(
        'database' => isset($payload['database_last_step_seconds']) ? (float) $payload['database_last_step_seconds'] : 0.0,
        'file scan' => isset($payload['file_scan_last_step_seconds']) ? (float) $payload['file_scan_last_step_seconds'] : 0.0,
        'archive' => isset($payload['archive_last_step_seconds']) ? (float) $payload['archive_last_step_seconds'] : 0.0,
    );

    arsort($seconds);
    $name = (string) array_key_first($seconds);

    return $seconds[$name] > 0.0 ? $name : '';
}
```

- [ ] **Step 4: Store bottleneck after metric writes**

In `BackupStepRunner`, after writing database metrics and after writing file scan metrics:

```php
$payload['backup_bottleneck'] = (new BackupPerformanceMetrics())->bottleneck($payload);
```

In `BackupArchiveStepPackager::addProgressMetrics()`, after byte metrics:

```php
$payload['backup_bottleneck'] = (new BackupPerformanceMetrics())->bottleneck($payload);
```

- [ ] **Step 5: Run tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/BackupPerformanceMetricsTest.php tests/Unit/BackupStepRunnerTest.php tests/Unit/BackupArchiveStepPackagerTest.php tests/Unit/BackupPageTest.php
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add super-sheep-copy/src/Backup/BackupPerformanceMetrics.php super-sheep-copy/src/Backup/BackupStepRunner.php super-sheep-copy/src/Backup/BackupArchiveStepPackager.php super-sheep-copy/tests/Unit/BackupPerformanceMetricsTest.php
git commit -m "feat: detect backup bottleneck phase"
```

---

### Task 7: Full Verification

**Files:**
- No code files unless tests reveal defects.

- [ ] **Step 1: Run full test suite**

Run:

```bash
vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 2: Inspect git status**

Run:

```bash
git status --short
```

Expected: clean except intentional committed changes.

- [ ] **Step 3: Manual admin smoke check**

On a local or staging WordPress install:

1. Start a backup.
2. Confirm Jobs table updates progress without JS errors.
3. Confirm progress text includes rows/min, entries/min, MB/min, or ETA once those phases run.
4. Confirm backup completes and archive validates.

- [ ] **Step 4: Final commit if smoke check needed fixes**

If smoke check required changes:

```bash
git add super-sheep-copy
git commit -m "fix backup performance metric display"
```

If no changes:

```bash
git status --short
```

Expected: clean.

---

## Self-Review Notes

- Spec coverage: metrics, adaptive DB chunk sizing, adaptive file scan sizing, adaptive archive time budget, admin display, and test coverage are all mapped to tasks.
- Completion scan: no incomplete markers or open-ended test instructions.
- Type consistency: payload keys match the design spec and code snippets.
