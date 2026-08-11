# Restore Database Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore legacy WordPress databases without global MySQL changes and prevent ordinary large-content imports from exceeding MySQL packet limits.

**Architecture:** Add a small installer-only detector for legacy zero-date defaults and configure only the current import connection when that compatibility is required. Update the SQL dump formatter to generate multiple byte-bounded `INSERT` statements per existing chunk file; the importer continues to resume at statement boundaries.

**Tech Stack:** PHP 7.4, mysqli, PHPUnit 9.6, WordPress plugin code.

---

## File structure

- Create `super-sheep-copy/installer/restore-engine/LegacyZeroDateDefaultDetector.php`: recognizes a zero-date default in `CREATE TABLE` SQL.
- Modify `super-sheep-copy/installer/restore-engine/DatabaseChunkImporter.php`: applies session-local compatibility and adds packet-aware failure context.
- Modify `super-sheep-copy/tests/Unit/DatabaseChunkImporterTest.php`: verifies detector-driven setup, resume behavior, and diagnostics with the existing fake connection.
- Modify `super-sheep-copy/src/Backup/Database/SqlDumpFormatter.php`: emits INSERT statements capped at 8 MiB.
- Modify `super-sheep-copy/tests/Unit/SqlDumpFormatterTest.php`: covers statement splitting and single-row limit failures.

### Task 1: Detect legacy zero-date schema defaults

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/LegacyZeroDateDefaultDetector.php`
- Test: `super-sheep-copy/tests/Unit/LegacyZeroDateDefaultDetectorTest.php`

- [ ] **Step 1: Write the failing detector tests**

```php
public function testDetectsZeroDateDefaultOnlyInCreateTableStatement(): void
{
    $detector = new \SuperSheepCopyInstaller\LegacyZeroDateDefaultDetector();

    self::assertTrue($detector->requiresCompatibility(array(
        'actions.sql' => "CREATE TABLE `wp_actionscheduler_actions` (`scheduled_date_gmt` datetime DEFAULT '0000-00-00 00:00:00');",
    )));
    self::assertFalse($detector->requiresCompatibility(array(
        'posts.sql' => "INSERT INTO `wp_posts` (`post_content`) VALUES ('DEFAULT \\'0000-00-00 00:00:00\\'');",
    )));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd super-sheep-copy && vendor/bin/phpunit tests/Unit/LegacyZeroDateDefaultDetectorTest.php`

Expected: FAIL because `LegacyZeroDateDefaultDetector` does not exist.

- [ ] **Step 3: Implement the detector**

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class LegacyZeroDateDefaultDetector
{
    /** @param array<string,string> $chunks */
    public function requiresCompatibility(array $chunks): bool
    {
        foreach ($chunks as $sql) {
            if (preg_match('/CREATE\\s+TABLE\\b.*?\\bDEFAULT\\s+\\'0000-00-00(?: 00:00:00)?\\'/is', $sql) === 1) {
                return true;
            }
        }

        return false;
    }
}
```

Keep the detector read-only: it must not rewrite schemas or row values.

- [ ] **Step 4: Run the detector test to verify it passes**

Run: `cd super-sheep-copy && vendor/bin/phpunit tests/Unit/LegacyZeroDateDefaultDetectorTest.php`

Expected: PASS.

- [ ] **Step 5: Commit the detector**

```bash
git add super-sheep-copy/installer/restore-engine/LegacyZeroDateDefaultDetector.php super-sheep-copy/tests/Unit/LegacyZeroDateDefaultDetectorTest.php
git commit -m "feat: detect legacy zero-date restore schemas"
```

### Task 2: Apply legacy compatibility to importer connections

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Modify: `super-sheep-copy/installer/restore-engine/DatabaseChunkImporter.php`
- Modify: `super-sheep-copy/tests/Unit/DatabaseChunkImporterTest.php`

- [ ] **Step 1: Write failing importer tests**

Add tests asserting all of the following:

```php
self::assertSame(
    array(
        'set_charset:utf8mb4',
        'query:SELECT @@SESSION.sql_mode',
        "query:SET SESSION sql_mode = 'STRICT_TRANS_TABLES'",
        'query:DROP TABLE IF EXISTS `ssc_tmp_hash_wp_actionscheduler_actions`',
    ),
    $connection->events
);
```

Use an Action Scheduler `CREATE TABLE` statement containing `DEFAULT '0000-00-00 00:00:00'`, set the fake's session mode to `STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE`, and assert that neither removed mode appears in the `SET SESSION` statement. Add a normal `wp_posts` schema test that asserts no `SELECT @@SESSION.sql_mode` occurs. Extend the resumable-import test with the legacy schema and assert setup is run on both newly opened connections.

- [ ] **Step 2: Run the importer tests to verify they fail**

Run: `cd super-sheep-copy && vendor/bin/phpunit tests/Unit/DatabaseChunkImporterTest.php`

Expected: FAIL because the importer does not query or set session SQL mode.

- [ ] **Step 3: Wire and implement session-only compatibility**

Require the new detector from `installer/restore-engine/Bootstrap.php` before `DatabaseChunkImporter.php`. Add a `LegacyZeroDateDefaultDetector` dependency with a default instance to `DatabaseChunkImporter`.

Before the import loops in both `importStep()` and `import()`, call one private setup method. Its behavior is:

```php
if (!$this->zero_date_detector->requiresCompatibility($chunks)) {
    return null;
}

$result = $mysqli->query('SELECT @@SESSION.sql_mode');
// Read the first scalar value, split it on commas, remove exactly NO_ZERO_DATE
// and NO_ZERO_IN_DATE, then issue SET SESSION sql_mode = '<remaining modes>'.
// Return a credential-free warning if SELECT or SET SESSION fails.
```

Use `SET SESSION`, never `SET GLOBAL`. Preserve all modes other than the two zero-date modes. On setup failure, close the connection and return the standard result shape with the warning before executing dump SQL. Update the fake connection so `query('SELECT @@SESSION.sql_mode')` returns a tiny object exposing `fetch_row()` and so events retain the exact SQL text.

- [ ] **Step 4: Add packet-aware failure context**

In `failedStatementWarning()`, add the byte count with `strlen($statement)` for MySQL error 2006 only:

```php
if ((int) $mysqli->errno === 2006) {
    $details[] = 'The failed statement is ' . strlen($statement) . ' bytes; check max_allowed_packet or the MySQL error log if the server cannot reconnect.';
}
```

Add a failing test with fake errno `2006`, then assert the warning contains the expected byte count and neither test credential.

- [ ] **Step 5: Run the importer tests to verify they pass**

Run: `cd super-sheep-copy && vendor/bin/phpunit tests/Unit/DatabaseChunkImporterTest.php`

Expected: PASS.

- [ ] **Step 6: Commit importer compatibility**

```bash
git add super-sheep-copy/installer/restore-engine/Bootstrap.php super-sheep-copy/installer/restore-engine/DatabaseChunkImporter.php super-sheep-copy/tests/Unit/DatabaseChunkImporterTest.php
git commit -m "feat: add restore sql-mode compatibility"
```

### Task 3: Bound generated INSERT statements by bytes

**Files:**
- Modify: `super-sheep-copy/src/Backup/Database/SqlDumpFormatter.php`
- Modify: `super-sheep-copy/tests/Unit/SqlDumpFormatterTest.php`

- [ ] **Step 1: Write failing formatter tests**

Create a formatter with a small test-only limit and two rows that cannot fit in one statement. Assert the resulting SQL contains two complete `INSERT INTO `wp_posts`` statements and both row values. Add a separate test where the only row cannot fit and assert `InvalidArgumentException` with `Single row for table wp_posts exceeds the maximum INSERT statement size.`

```php
$formatter = new SqlDumpFormatter(100);
$sql = $formatter->formatRows(new TableRows('wp_posts', array('ID', 'post_content'), array(
    array('ID' => 1, 'post_content' => str_repeat('a', 30)),
    array('ID' => 2, 'post_content' => str_repeat('b', 30)),
)));
self::assertSame(2, substr_count($sql, 'INSERT INTO `wp_posts`'));
```

- [ ] **Step 2: Run the formatter test to verify it fails**

Run: `cd super-sheep-copy && vendor/bin/phpunit tests/Unit/SqlDumpFormatterTest.php`

Expected: FAIL because the constructor accepts no limit and the formatter emits one INSERT.

- [ ] **Step 3: Implement byte-bounded formatting**

Add `private const DEFAULT_MAX_INSERT_BYTES = 8388608;`, a constructor that rejects limits below one byte, and helpers that build the unchanged INSERT prefix and terminate a statement with `;\n`.

For each encoded row, compute the completed candidate size including comma/newline separators and `;\n`. Append it when it fits. Otherwise append the completed current statement to output and start a new statement with that row. If the new single-row statement exceeds the limit, throw `InvalidArgumentException('Single row for table ' . $rows->tableName() . ' exceeds the maximum INSERT statement size.')`.

Do not split inside a row or change escaping rules. Existing no-row behavior must still return an empty string.

- [ ] **Step 4: Run formatter tests to verify they pass**

Run: `cd super-sheep-copy && vendor/bin/phpunit tests/Unit/SqlDumpFormatterTest.php`

Expected: PASS.

- [ ] **Step 5: Run the complete verification suite**

Run:

```bash
cd super-sheep-copy
composer test
composer lint
```

Expected: PHPUnit passes and PHP lint reports no syntax errors.

- [ ] **Step 6: Commit byte-bounded SQL export**

```bash
git add super-sheep-copy/src/Backup/Database/SqlDumpFormatter.php super-sheep-copy/tests/Unit/SqlDumpFormatterTest.php
git commit -m "fix: bound database insert statement size"
```

### Task 4: Build and inspect the installer artifact

**Files:**
- Generated: `super-sheep-copy/dist/` (only if tracked by the project build)

- [ ] **Step 1: Build the plugin artifact**

Run: `cd super-sheep-copy && composer build`

Expected: the build completes without PHP errors.

- [ ] **Step 2: Confirm the installer includes the detector**

Run: `rg -n "LegacyZeroDateDefaultDetector|SET SESSION sql_mode" super-sheep-copy/installer/restore-engine super-sheep-copy/dist`

Expected: the detector is required by Bootstrap and its session-only compatibility logic is present in the build output when `dist` is produced.

- [ ] **Step 3: Commit tracked build output only when changed**

```bash
git status --short
git add super-sheep-copy/dist
git commit -m "build: package restore compatibility"
```

Skip the final commit if `dist` is untracked or unchanged.
