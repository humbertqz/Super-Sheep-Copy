# Database Backup Coordinator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Compose the database exporter, chunk planner, SQL formatter, and file writer into an end-to-end database backup coordinator that writes selected tables to a backup working directory.

**Architecture:** Add column discovery to the `$wpdb` adapter, then add a WordPress-free `DatabaseBackupCoordinator` that orchestrates existing database services. The coordinator depends on abstractions/classes already built in `src/Backup/Database/` and remains testable with fake clients and temp directories.

**Tech Stack:** PHP 7.4+, WordPress `$wpdb` runtime conventions behind `WpdbClientInterface`, Composer PSR-4 autoloading, PHPUnit 9.6.

---

## Scope Check

This plan implements `docs/superpowers/specs/2026-05-15-database-backup-coordinator-design.md`.

Included:
- `WpdbClientInterface::getColumns()`.
- `WpdbClient::getColumns()` using `SHOW COLUMNS`.
- `WpdbDatabaseExporter::getColumns()`.
- `DatabaseBackupCoordinator`.
- Unit tests with fake database clients and temporary directories.

Excluded:
- Resume state.
- Admin UI.
- Job runner integration.
- ZIP archive integration.
- Progress display.
- Restore/import.
- Live MySQL integration tests.

## File Structure

- Modify `super-sheep-copy/src/Backup/Database/WpdbClientInterface.php`
  - Add `getColumns(string $table): array`.
- Modify `super-sheep-copy/src/Backup/Database/WpdbClient.php`
  - Implement `getColumns()` through `SHOW COLUMNS FROM`.
- Modify `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php`
  - Add `getColumns()` with identifier validation.
- Create `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php`
  - Orchestrates database export into a working directory.
- Modify existing fake clients in tests to implement the new interface method.
- Create tests:
  - `super-sheep-copy/tests/Unit/WpdbDatabaseExporterColumnsTest.php`
  - `super-sheep-copy/tests/Unit/DatabaseBackupCoordinatorTest.php`

---

### Task 1: Column Discovery

**Files:**
- Modify: `super-sheep-copy/src/Backup/Database/WpdbClientInterface.php`
- Modify: `super-sheep-copy/src/Backup/Database/WpdbClient.php`
- Modify: `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php`
- Modify: existing fake clients in `super-sheep-copy/tests/Unit/Wpdb*.php`
- Test: `super-sheep-copy/tests/Unit/WpdbDatabaseExporterColumnsTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/WpdbDatabaseExporterColumnsTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClientInterface;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;

final class WpdbDatabaseExporterColumnsTest extends TestCase
{
    public function testGetsColumnsFromClient(): void
    {
        $exporter = new WpdbDatabaseExporter(new ColumnsFakeClient(), new TableSelector());

        self::assertSame(array('ID', 'post_title'), $exporter->getColumns('wp_posts'));
    }

    public function testRejectsUnsafeTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier: wp_posts;DROP');

        (new WpdbDatabaseExporter(new ColumnsFakeClient(), new TableSelector()))->getColumns('wp_posts;DROP');
    }
}

final class ColumnsFakeClient implements WpdbClientInterface
{
    public function getTables(): array
    {
        return array();
    }

    public function getCreateTableSql(string $table): string
    {
        return '';
    }

    public function getPrimaryKey(string $table): ?string
    {
        return null;
    }

    public function getRowCount(string $table): int
    {
        return 0;
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
        return array();
    }

    public function prepare(string $sql, array $args): string
    {
        return $sql;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/WpdbDatabaseExporterColumnsTest.php
```

Expected: FAIL with `Call to undefined method SuperSheepCopy\Backup\Database\WpdbDatabaseExporter::getColumns()`.

- [ ] **Step 3: Update the client interface**

Add this method to `super-sheep-copy/src/Backup/Database/WpdbClientInterface.php` after `getTableStatus()`:

```php
    /**
     * @return string[]
     */
    public function getColumns(string $table): array;
```

- [ ] **Step 4: Update runtime `WpdbClient`**

Add this method to `super-sheep-copy/src/Backup/Database/WpdbClient.php` after `getTableStatus()`:

```php
    public function getColumns(string $table): array
    {
        $rows = (array) $this->wpdb->get_results('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`', 'ARRAY_A');
        $columns = array();

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Field']) && is_string($row['Field'])) {
                $columns[] = $row['Field'];
            }
        }

        return $columns;
    }
```

- [ ] **Step 5: Update exporter**

Add this method to `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php` after `getSchema()`:

```php
    /**
     * @return string[]
     */
    public function getColumns(string $table): array
    {
        $this->assertIdentifier($table);

        return $this->client->getColumns($table);
    }
```

- [ ] **Step 6: Update existing fake clients**

For every class in `super-sheep-copy/tests/Unit/Wpdb*.php` that implements `WpdbClientInterface`, add:

```php
    public function getColumns(string $table): array
    {
        return array();
    }
```

In `FakeWpdb` inside `super-sheep-copy/tests/Unit/WpdbClientTest.php`, update `get_results()` to support column discovery:

```php
    public function get_results(string $sql, string $output): array
    {
        if ($sql === 'SHOW COLUMNS FROM `wp_posts`') {
            return array(array('Field' => 'ID'), array('Field' => 'post_title'));
        }

        return array(array('ID' => 1));
    }
```

Then add this assertion in `testWrapsWpdbOperations()` after `getTableStatus()`:

```php
        self::assertSame(array('ID', 'post_title'), $client->getColumns('wp_posts'));
```

- [ ] **Step 7: Run focused tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/WpdbDatabaseExporterColumnsTest.php tests/Unit/WpdbClientTest.php tests/Unit/WpdbDatabaseExporterSchemaTest.php tests/Unit/WpdbDatabaseExporterRowsTest.php
```

Expected: PASS.

- [ ] **Step 8: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 9: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/Database/WpdbClientInterface.php super-sheep-copy/src/Backup/Database/WpdbClient.php super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php super-sheep-copy/tests/Unit/Wpdb*.php
git commit -m "feat: discover wpdb table columns"
```

Expected: commit succeeds.

---

### Task 2: Database Backup Coordinator

**Files:**
- Create: `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseBackupCoordinatorTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/DatabaseBackupCoordinatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseBackupCoordinator;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\DatabaseExportWriter;
use SuperSheepCopy\Backup\Database\SqlDumpFormatter;
use SuperSheepCopy\Backup\Database\TableRows;
use SuperSheepCopy\Backup\Database\TableSchema;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClientInterface;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;

final class DatabaseBackupCoordinatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-db-coordinator-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExportsPrimaryKeyTableInMultipleChunks(): void
    {
        $this->coordinator(new CoordinatorFakeClient())->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 2);

        self::assertStringContainsString('CREATE TABLE `wp_posts` (`ID` bigint);', (string) file_get_contents($this->root . '/database/chunks/wp_posts.part001.sql'));
        self::assertStringContainsString("INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES\n(1, 'Hello'),\n(2, 'World');", (string) file_get_contents($this->root . '/database/chunks/wp_posts.part001.sql'));
        self::assertSame("INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES\n(3, 'Again');\n", file_get_contents($this->root . '/database/chunks/wp_posts.part002.sql'));

        $manifest = json_decode((string) file_get_contents($this->root . '/database/tables.json'), true);
        self::assertSame(array('wp_posts.part001.sql', 'wp_posts.part002.sql'), $manifest['tables'][0]['chunks']);
    }

    public function testExportsEmptyTableWithSchemaOnlyChunk(): void
    {
        $this->coordinator(new EmptyTableFakeClient())->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 100);

        self::assertSame("DROP TABLE IF EXISTS `wp_empty`;\nCREATE TABLE `wp_empty` (`ID` bigint);\n", file_get_contents($this->root . '/database/chunks/wp_empty.part001.sql'));
    }

    public function testExportsOffsetPaginatedTable(): void
    {
        $this->coordinator(new OffsetFakeClient())->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 1);

        self::assertStringContainsString("INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES\n('siteurl', 'https://website.com');", (string) file_get_contents($this->root . '/database/chunks/wp_options.part001.sql'));
        self::assertSame("INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES\n('home', 'https://website.com');\n", file_get_contents($this->root . '/database/chunks/wp_options.part002.sql'));
    }

    public function testEmptySelectionWritesEmptyManifest(): void
    {
        $this->coordinator(new NoTablesFakeClient())->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 100);

        $manifest = json_decode((string) file_get_contents($this->root . '/database/tables.json'), true);
        self::assertSame(0, $manifest['table_count']);
        self::assertSame(array(), $manifest['tables']);
    }

    public function testRejectsInvalidChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be greater than zero.');

        $this->coordinator(new CoordinatorFakeClient())->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 0);
    }

    private function coordinator(WpdbClientInterface $client): DatabaseBackupCoordinator
    {
        return new DatabaseBackupCoordinator(
            new WpdbDatabaseExporter($client, new TableSelector()),
            new ChunkPlanner(),
            new SqlDumpFormatter(),
            new DatabaseExportWriter(new DatabaseExportManifestBuilder())
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

final class CoordinatorFakeClient implements WpdbClientInterface
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

final class EmptyTableFakeClient extends CoordinatorFakeClient
{
    public function getTables(): array
    {
        return array('wp_empty');
    }

    public function getCreateTableSql(string $table): string
    {
        return 'CREATE TABLE `wp_empty` (`ID` bigint)';
    }

    public function getRowCount(string $table): int
    {
        return 0;
    }

    public function getColumns(string $table): array
    {
        return array('ID');
    }

    public function getRows(string $sql): array
    {
        return array();
    }
}

final class OffsetFakeClient extends CoordinatorFakeClient
{
    public function getTables(): array
    {
        return array('wp_options');
    }

    public function getCreateTableSql(string $table): string
    {
        return 'CREATE TABLE `wp_options` (`option_name` varchar(191))';
    }

    public function getPrimaryKey(string $table): ?string
    {
        return null;
    }

    public function getRowCount(string $table): int
    {
        return 2;
    }

    public function getColumns(string $table): array
    {
        return array('option_name', 'option_value');
    }

    public function getRows(string $sql): array
    {
        if (strpos($sql, 'OFFSET 1') !== false) {
            return array(array('option_name' => 'home', 'option_value' => 'https://website.com'));
        }

        return array(array('option_name' => 'siteurl', 'option_value' => 'https://website.com'));
    }
}

final class NoTablesFakeClient extends CoordinatorFakeClient
{
    public function getTables(): array
    {
        return array();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseBackupCoordinatorTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\Database\DatabaseBackupCoordinator" not found`.

- [ ] **Step 3: Add coordinator implementation**

Create `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;

final class DatabaseBackupCoordinator
{
    private WpdbDatabaseExporter $exporter;
    private ChunkPlanner $chunk_planner;
    private SqlDumpFormatter $formatter;
    private DatabaseExportWriter $writer;

    public function __construct(
        WpdbDatabaseExporter $exporter,
        ChunkPlanner $chunk_planner,
        SqlDumpFormatter $formatter,
        DatabaseExportWriter $writer
    ) {
        $this->exporter = $exporter;
        $this->chunk_planner = $chunk_planner;
        $this->formatter = $formatter;
        $this->writer = $writer;
    }

    public function export(string $working_directory, string $table_prefix, string $selection_mode, int $chunk_size): void
    {
        if ($chunk_size < 1) {
            throw new InvalidArgumentException('Chunk size must be greater than zero.');
        }

        $schemas = array();
        $plans_by_table = array();
        $sql_by_chunk = array();

        foreach ($this->exporter->selectTables($table_prefix, $selection_mode) as $table) {
            $schema = $this->exporter->getSchema($table);
            $columns = $this->exporter->getColumns($table);
            $schemas[] = $schema;

            $chunk_count = max(1, (int) ceil($schema->rowCount() / $chunk_size));
            $plans_by_table[$table] = array();
            $last_seen_id = null;

            for ($chunk_number = 1; $chunk_number <= $chunk_count; $chunk_number++) {
                $plan = $this->chunk_planner->plan($schema, $chunk_size, $chunk_number, $last_seen_id);
                $rows = $this->exporter->fetchRows($plan, $columns);
                $plans_by_table[$table][] = $plan;

                $sql = $chunk_number === 1 ? $this->formatter->formatSchema($schema) : '';
                $sql .= $this->formatter->formatRows($rows);
                $sql_by_chunk[$plan->fileName()] = $sql;

                if ($plan->strategy() === ChunkPlan::STRATEGY_PRIMARY_KEY && $schema->primaryKey() !== null) {
                    $last_seen_id = $this->lastSeenId($rows, $schema->primaryKey(), $last_seen_id);
                }
            }
        }

        $this->writer->write($working_directory, $schemas, $plans_by_table, $sql_by_chunk);
    }

    private function lastSeenId(TableRows $rows, string $primary_key, ?int $current): ?int
    {
        foreach ($rows->rows() as $row) {
            if (isset($row[$primary_key])) {
                $current = (int) $row[$primary_key];
            }
        }

        return $current;
    }
}
```

- [ ] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseBackupCoordinatorTest.php
```

Expected: PASS with `OK (5 tests`.

- [ ] **Step 5: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php super-sheep-copy/tests/Unit/DatabaseBackupCoordinatorTest.php
git commit -m "feat: coordinate database backups"
```

Expected: commit succeeds.

---

### Task 3: Final Verification

**Files:**
- Verify: `super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php`
- Verify: `super-sheep-copy/src/Backup/Database/WpdbClientInterface.php`
- Verify: `super-sheep-copy/src/Backup/Database/WpdbClient.php`
- Verify: `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php`
- Verify: `super-sheep-copy/tests/Unit/DatabaseBackupCoordinatorTest.php`

- [ ] **Step 1: Run lint**

Run:

```bash
cd super-sheep-copy && composer run lint
```

Expected: every PHP file reports `No syntax errors detected`.

- [ ] **Step 2: Run full PHPUnit suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 3: Confirm coordinator has no direct WordPress dependency**

Run:

```bash
rg "\\$wpdb|ABSPATH|wp-load" super-sheep-copy/src/Backup/Database/DatabaseBackupCoordinator.php
```

Expected: no matches.

- [ ] **Step 4: Check git status**

Run:

```bash
git status --short
```

Expected: empty working tree after task commits.

## Self-Review

- Spec coverage: The plan covers column discovery, primary-key and offset chunk export, schema in first chunks, empty table schema-only export, empty selection manifest writing, invalid chunk-size rejection, and temp-directory assertions.
- Placeholder scan: No step relies on unspecified implementation details. New tests and implementation code are concrete.
- Type consistency: `DatabaseBackupCoordinator` uses existing `WpdbDatabaseExporter`, `ChunkPlanner`, `SqlDumpFormatter`, `DatabaseExportWriter`, `TableRows`, and `ChunkPlan` classes in `SuperSheepCopy\Backup\Database`.

