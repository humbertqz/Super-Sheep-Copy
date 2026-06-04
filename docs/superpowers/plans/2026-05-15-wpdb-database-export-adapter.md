# WPDB Database Export Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `$wpdb` adapter layer that feeds real WordPress database metadata and row chunks into the existing database export core.

**Architecture:** Keep the pure export core WordPress-free by adding a narrow `WpdbClientInterface` data-access port and a runtime `WpdbClient` wrapper around a `$wpdb`-like object. Put query construction and conversion to `TableSchema`/`TableRows` in `WpdbDatabaseExporter`, with unit tests using fakes instead of WordPress or MySQL.

**Tech Stack:** PHP 7.4+, WordPress `$wpdb` runtime conventions, Composer PSR-4 autoloading, PHPUnit 9.6.

---

## Scope Check

This plan implements `docs/superpowers/specs/2026-05-15-wpdb-database-export-adapter-design.md`.

Included:
- `WpdbClientInterface`.
- Runtime `WpdbClient` wrapper around a `$wpdb`-like object.
- `WpdbDatabaseExporter` for table discovery, schema construction, chunk query construction, and row fetching.
- Unit tests with fake clients only.

Excluded:
- Disk writes for SQL chunks.
- `database/tables.json` file writing.
- Backup job orchestration.
- Admin UI.
- WP-CLI.
- Import/restore.

## File Structure

- Create `super-sheep-copy/src/Backup/Database/WpdbClientInterface.php`
  - Narrow interface for database operations required by the exporter.
- Create `super-sheep-copy/src/Backup/Database/WpdbClient.php`
  - Runtime adapter around a `$wpdb`-like object.
- Create `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php`
  - Uses `WpdbClientInterface`, `TableSelector`, `TableSchema`, `ChunkPlan`, and `TableRows`.
- Create tests:
  - `super-sheep-copy/tests/Unit/WpdbClientTest.php`
  - `super-sheep-copy/tests/Unit/WpdbDatabaseExporterSchemaTest.php`
  - `super-sheep-copy/tests/Unit/WpdbDatabaseExporterRowsTest.php`

---

### Task 1: WPDB Client Port and Runtime Wrapper

**Files:**
- Create: `super-sheep-copy/src/Backup/Database/WpdbClientInterface.php`
- Create: `super-sheep-copy/src/Backup/Database/WpdbClient.php`
- Test: `super-sheep-copy/tests/Unit/WpdbClientTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/WpdbClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\WpdbClient;

final class WpdbClientTest extends TestCase
{
    public function testWrapsWpdbOperations(): void
    {
        $wpdb = new FakeWpdb();
        $client = new WpdbClient($wpdb);

        self::assertSame(array('wp_posts', 'wp_options'), $client->getTables());
        self::assertSame('CREATE TABLE `wp_posts` (`ID` bigint)', $client->getCreateTableSql('wp_posts'));
        self::assertSame('ID', $client->getPrimaryKey('wp_posts'));
        self::assertSame(12, $client->getRowCount('wp_posts'));
        self::assertSame(array('Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4'), $client->getTableStatus('wp_posts'));
        self::assertSame(array(array('ID' => 1)), $client->getRows('SELECT * FROM `wp_posts`'));
        self::assertSame('SELECT * FROM `wp_posts` LIMIT 10', $client->prepare('SELECT * FROM `wp_posts` LIMIT %d', array(10)));
    }
}

final class FakeWpdb
{
    public function get_col(string $sql): array
    {
        if ($sql === 'SHOW TABLES') {
            return array('wp_posts', 'wp_options');
        }

        if ($sql === "SHOW KEYS FROM `wp_posts` WHERE Key_name = 'PRIMARY'") {
            return array('ID');
        }

        return array();
    }

    public function get_var(string $sql)
    {
        if ($sql === 'SHOW CREATE TABLE `wp_posts`') {
            return 'CREATE TABLE `wp_posts` (`ID` bigint)';
        }

        if ($sql === 'SELECT COUNT(*) FROM `wp_posts`') {
            return '12';
        }

        return null;
    }

    public function get_row(string $sql, string $output): array
    {
        return array('Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4');
    }

    public function get_results(string $sql, string $output): array
    {
        return array(array('ID' => 1));
    }

    public function prepare(string $sql, ...$args): string
    {
        foreach ($args as $arg) {
            $sql = preg_replace('/%d/', (string) $arg, $sql, 1);
            $sql = preg_replace('/%s/', "'" . (string) $arg . "'", $sql, 1);
        }

        return $sql;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/WpdbClientTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\Database\WpdbClient" not found`.

- [ ] **Step 3: Add client interface**

Create `super-sheep-copy/src/Backup/Database/WpdbClientInterface.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

interface WpdbClientInterface
{
    /**
     * @return string[]
     */
    public function getTables(): array;

    public function getCreateTableSql(string $table): string;

    public function getPrimaryKey(string $table): ?string;

    public function getRowCount(string $table): int;

    /**
     * @return array<string, mixed>
     */
    public function getTableStatus(string $table): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRows(string $sql): array;

    /**
     * @param array<int, mixed> $args
     */
    public function prepare(string $sql, array $args): string;
}
```

- [ ] **Step 4: Add runtime WPDB wrapper**

Create `super-sheep-copy/src/Backup/Database/WpdbClient.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

final class WpdbClient implements WpdbClientInterface
{
    /** @var object */
    private $wpdb;

    /**
     * @param object $wpdb
     */
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function getTables(): array
    {
        return array_values((array) $this->wpdb->get_col('SHOW TABLES'));
    }

    public function getCreateTableSql(string $table): string
    {
        return (string) $this->wpdb->get_var('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
    }

    public function getPrimaryKey(string $table): ?string
    {
        $columns = (array) $this->wpdb->get_col("SHOW KEYS FROM `" . str_replace('`', '``', $table) . "` WHERE Key_name = 'PRIMARY'");

        return isset($columns[0]) && is_string($columns[0]) && $columns[0] !== '' ? $columns[0] : null;
    }

    public function getRowCount(string $table): int
    {
        return (int) $this->wpdb->get_var('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
    }

    public function getTableStatus(string $table): array
    {
        $sql = $this->prepare('SHOW TABLE STATUS LIKE %s', array($table));

        return (array) $this->wpdb->get_row($sql, 'ARRAY_A');
    }

    public function getRows(string $sql): array
    {
        return array_values((array) $this->wpdb->get_results($sql, 'ARRAY_A'));
    }

    public function prepare(string $sql, array $args): string
    {
        return (string) $this->wpdb->prepare($sql, ...$args);
    }
}
```

- [ ] **Step 5: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/WpdbClientTest.php
```

Expected: PASS with `OK (1 test`.

- [ ] **Step 6: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 7: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/Database/WpdbClientInterface.php super-sheep-copy/src/Backup/Database/WpdbClient.php super-sheep-copy/tests/Unit/WpdbClientTest.php
git commit -m "feat: add wpdb database client"
```

Expected: commit succeeds.

---

### Task 2: Table Discovery and Schema Exporter

**Files:**
- Create: `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php`
- Test: `super-sheep-copy/tests/Unit/WpdbDatabaseExporterSchemaTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/WpdbDatabaseExporterSchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClientInterface;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;

final class WpdbDatabaseExporterSchemaTest extends TestCase
{
    public function testDiscoversSelectedTables(): void
    {
        $exporter = new WpdbDatabaseExporter(new SchemaFakeClient(), new TableSelector());

        self::assertSame(
            array('wp_posts', 'wp_options'),
            $exporter->selectTables('wp_', TableSelector::MODE_PREFIXED)
        );
    }

    public function testBuildsTableSchemaFromClientMetadata(): void
    {
        $exporter = new WpdbDatabaseExporter(new SchemaFakeClient(), new TableSelector());

        $schema = $exporter->getSchema('wp_posts');

        self::assertSame('wp_posts', $schema->name());
        self::assertSame('CREATE TABLE `wp_posts` (`ID` bigint)', $schema->createSql());
        self::assertSame('ID', $schema->primaryKey());
        self::assertSame(12, $schema->rowCount());
        self::assertSame('utf8mb4', $schema->charset());
        self::assertSame('utf8mb4_unicode_ci', $schema->collation());
    }

    public function testRejectsUnsafeTableIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier: wp_posts;DROP');

        (new WpdbDatabaseExporter(new SchemaFakeClient(), new TableSelector()))->getSchema('wp_posts;DROP');
    }

    public function testThrowsWhenCreateSqlIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Create table SQL was not found for table: wp_missing');

        (new WpdbDatabaseExporter(new SchemaFakeClient(), new TableSelector()))->getSchema('wp_missing');
    }
}

final class SchemaFakeClient implements WpdbClientInterface
{
    public function getTables(): array
    {
        return array('wp_posts', 'wp_options', 'other_table');
    }

    public function getCreateTableSql(string $table): string
    {
        return $table === 'wp_missing' ? '' : 'CREATE TABLE `' . $table . '` (`ID` bigint)';
    }

    public function getPrimaryKey(string $table): ?string
    {
        return $table === 'wp_options' ? null : 'ID';
    }

    public function getRowCount(string $table): int
    {
        return 12;
    }

    public function getTableStatus(string $table): array
    {
        return array('Charset' => 'utf8mb4', 'Collation' => 'utf8mb4_unicode_ci');
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
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/WpdbDatabaseExporterSchemaTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\Database\WpdbDatabaseExporter" not found`.

- [ ] **Step 3: Add exporter schema methods**

Create `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;
use RuntimeException;

final class WpdbDatabaseExporter
{
    private WpdbClientInterface $client;
    private TableSelector $selector;

    public function __construct(WpdbClientInterface $client, TableSelector $selector)
    {
        $this->client = $client;
        $this->selector = $selector;
    }

    /**
     * @return string[]
     */
    public function selectTables(string $prefix, string $mode): array
    {
        return $this->selector->select($this->client->getTables(), $prefix, $mode);
    }

    public function getSchema(string $table): TableSchema
    {
        $this->assertIdentifier($table);

        $create_sql = $this->client->getCreateTableSql($table);
        if ($create_sql === '') {
            throw new RuntimeException('Create table SQL was not found for table: ' . $table);
        }

        $status = $this->client->getTableStatus($table);

        return new TableSchema(
            $table,
            $create_sql,
            $this->client->getPrimaryKey($table),
            $this->client->getRowCount($table),
            isset($status['Charset']) && is_string($status['Charset']) ? $status['Charset'] : null,
            isset($status['Collation']) && is_string($status['Collation']) ? $status['Collation'] : null
        );
    }

    private function assertIdentifier(string $identifier): void
    {
        if ($identifier === '' || preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }
    }
}
```

- [ ] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/WpdbDatabaseExporterSchemaTest.php
```

Expected: PASS with `OK (4 tests`.

- [ ] **Step 5: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php super-sheep-copy/tests/Unit/WpdbDatabaseExporterSchemaTest.php
git commit -m "feat: discover wpdb export schemas"
```

Expected: commit succeeds.

---

### Task 3: Chunk Query Building and Row Fetching

**Files:**
- Modify: `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php`
- Test: `super-sheep-copy/tests/Unit/WpdbDatabaseExporterRowsTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/WpdbDatabaseExporterRowsTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\ChunkPlan;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClientInterface;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;

final class WpdbDatabaseExporterRowsTest extends TestCase
{
    public function testBuildsPrimaryKeyQueryForFirstChunk(): void
    {
        $client = new RowsFakeClient();
        $exporter = new WpdbDatabaseExporter($client, new TableSelector());
        $plan = new ChunkPlan('wp_posts', 'wp_posts.part001.sql', ChunkPlan::STRATEGY_PRIMARY_KEY, 'ID', null, 100, null, 1);

        self::assertSame('SELECT * FROM `wp_posts` ORDER BY `ID` ASC LIMIT 100', $exporter->buildChunkQuery($plan));
        self::assertSame(array('SELECT * FROM `wp_posts` ORDER BY `ID` ASC LIMIT %d', array(100)), $client->prepared[0]);
    }

    public function testBuildsPrimaryKeyQueryAfterLastSeenId(): void
    {
        $client = new RowsFakeClient();
        $exporter = new WpdbDatabaseExporter($client, new TableSelector());
        $plan = new ChunkPlan('wp_posts', 'wp_posts.part002.sql', ChunkPlan::STRATEGY_PRIMARY_KEY, 'ID', 100, 100, null, 2);

        self::assertSame('SELECT * FROM `wp_posts` WHERE `ID` > 100 ORDER BY `ID` ASC LIMIT 100', $exporter->buildChunkQuery($plan));
        self::assertSame(array('SELECT * FROM `wp_posts` WHERE `ID` > %d ORDER BY `ID` ASC LIMIT %d', array(100, 100)), $client->prepared[0]);
    }

    public function testBuildsOffsetQuery(): void
    {
        $client = new RowsFakeClient();
        $exporter = new WpdbDatabaseExporter($client, new TableSelector());
        $plan = new ChunkPlan('wp_options', 'wp_options.part003.sql', ChunkPlan::STRATEGY_OFFSET, null, null, 50, 100, 3);

        self::assertSame('SELECT * FROM `wp_options` LIMIT 50 OFFSET 100', $exporter->buildChunkQuery($plan));
        self::assertSame(array('SELECT * FROM `wp_options` LIMIT %d OFFSET %d', array(50, 100)), $client->prepared[0]);
    }

    public function testFetchesRowsIntoTableRows(): void
    {
        $client = new RowsFakeClient();
        $exporter = new WpdbDatabaseExporter($client, new TableSelector());
        $plan = new ChunkPlan('wp_posts', 'wp_posts.part001.sql', ChunkPlan::STRATEGY_PRIMARY_KEY, 'ID', null, 100, null, 1);

        $rows = $exporter->fetchRows($plan, array('ID', 'post_title'));

        self::assertSame('wp_posts', $rows->tableName());
        self::assertSame(array('ID', 'post_title'), $rows->columns());
        self::assertSame(array(array('ID' => 1, 'post_title' => 'Hello')), $rows->rows());
    }

    public function testRejectsUnsafeColumnIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier: ID;DROP');

        $plan = new ChunkPlan('wp_posts', 'wp_posts.part001.sql', ChunkPlan::STRATEGY_PRIMARY_KEY, 'ID;DROP', null, 100, null, 1);
        (new WpdbDatabaseExporter(new RowsFakeClient(), new TableSelector()))->buildChunkQuery($plan);
    }
}

final class RowsFakeClient implements WpdbClientInterface
{
    /** @var array<int, array{0:string,1:array<int,mixed>}> */
    public array $prepared = array();

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

    public function getRows(string $sql): array
    {
        return array(array('ID' => 1, 'post_title' => 'Hello'));
    }

    public function prepare(string $sql, array $args): string
    {
        $this->prepared[] = array($sql, $args);
        foreach ($args as $arg) {
            $sql = preg_replace('/%d/', (string) $arg, $sql, 1);
        }

        return $sql;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/WpdbDatabaseExporterRowsTest.php
```

Expected: FAIL with `Call to undefined method SuperSheepCopy\Backup\Database\WpdbDatabaseExporter::buildChunkQuery()`.

- [ ] **Step 3: Add query and fetch methods**

Update `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php` so the final class is:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;
use RuntimeException;

final class WpdbDatabaseExporter
{
    private WpdbClientInterface $client;
    private TableSelector $selector;

    public function __construct(WpdbClientInterface $client, TableSelector $selector)
    {
        $this->client = $client;
        $this->selector = $selector;
    }

    /**
     * @return string[]
     */
    public function selectTables(string $prefix, string $mode): array
    {
        return $this->selector->select($this->client->getTables(), $prefix, $mode);
    }

    public function getSchema(string $table): TableSchema
    {
        $this->assertIdentifier($table);

        $create_sql = $this->client->getCreateTableSql($table);
        if ($create_sql === '') {
            throw new RuntimeException('Create table SQL was not found for table: ' . $table);
        }

        $status = $this->client->getTableStatus($table);

        return new TableSchema(
            $table,
            $create_sql,
            $this->client->getPrimaryKey($table),
            $this->client->getRowCount($table),
            isset($status['Charset']) && is_string($status['Charset']) ? $status['Charset'] : null,
            isset($status['Collation']) && is_string($status['Collation']) ? $status['Collation'] : null
        );
    }

    public function buildChunkQuery(ChunkPlan $plan): string
    {
        $this->assertIdentifier($plan->tableName());

        if ($plan->strategy() === ChunkPlan::STRATEGY_PRIMARY_KEY) {
            $primary_key = $plan->primaryKey();
            $this->assertIdentifier((string) $primary_key);

            if ($plan->lastSeenId() === null) {
                return $this->client->prepare(
                    sprintf('SELECT * FROM `%s` ORDER BY `%s` ASC LIMIT %%d', $plan->tableName(), $primary_key),
                    array($plan->limit())
                );
            }

            return $this->client->prepare(
                sprintf('SELECT * FROM `%s` WHERE `%s` > %%d ORDER BY `%s` ASC LIMIT %%d', $plan->tableName(), $primary_key, $primary_key),
                array($plan->lastSeenId(), $plan->limit())
            );
        }

        return $this->client->prepare(
            sprintf('SELECT * FROM `%s` LIMIT %%d OFFSET %%d', $plan->tableName()),
            array($plan->limit(), (int) $plan->offset())
        );
    }

    /**
     * @param string[] $columns
     */
    public function fetchRows(ChunkPlan $plan, array $columns): TableRows
    {
        foreach ($columns as $column) {
            $this->assertIdentifier($column);
        }

        return new TableRows($plan->tableName(), $columns, $this->client->getRows($this->buildChunkQuery($plan)));
    }

    private function assertIdentifier(string $identifier): void
    {
        if ($identifier === '' || preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }
    }
}
```

- [ ] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/WpdbDatabaseExporterRowsTest.php
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
git add super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php super-sheep-copy/tests/Unit/WpdbDatabaseExporterRowsTest.php
git commit -m "feat: fetch wpdb export rows"
```

Expected: commit succeeds.

---

### Task 4: Final Verification

**Files:**
- Verify: `super-sheep-copy/src/Backup/Database/WpdbClientInterface.php`
- Verify: `super-sheep-copy/src/Backup/Database/WpdbClient.php`
- Verify: `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php`
- Verify: `super-sheep-copy/tests/Unit/Wpdb*.php`

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

- [ ] **Step 3: Confirm no direct WordPress bootstrap in tests**

Run:

```bash
rg "ABSPATH|wp-load|require_once.+wp" super-sheep-copy/tests/Unit/Wpdb*.php
```

Expected: no matches.

- [ ] **Step 4: Check git status**

Run:

```bash
git status --short
```

Expected: empty working tree after task commits.

## Self-Review

- Spec coverage: The plan covers `WpdbClientInterface`, `WpdbClient`, `WpdbDatabaseExporter`, table discovery, schema building, primary-key and offset query construction, row fetching, fake-client unit tests, and unsafe identifier rejection.
- Placeholder scan: No step relies on unspecified implementation details. Every new class and test has concrete code.
- Type consistency: The new classes live in `SuperSheepCopy\Backup\Database` and use the existing `TableSelector`, `TableSchema`, `TableRows`, and `ChunkPlan` types.

