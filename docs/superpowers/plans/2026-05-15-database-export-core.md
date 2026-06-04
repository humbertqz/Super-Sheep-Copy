# Database Export Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WordPress-free database export core that selects tables, plans chunked reads, formats SQL dump chunks, and produces `database/tables.json` metadata.

**Architecture:** Add focused classes under `super-sheep-copy/src/Backup/Database/` with no `$wpdb`, WordPress globals, or filesystem writes. Tests feed arrays and value objects into the core so the later `$wpdb` adapter can be added without changing export rules.

**Tech Stack:** PHP 7.4+, Composer PSR-4 autoloading, PHPUnit 9.6.

---

## Scope Check

This plan implements the approved spec in `docs/superpowers/specs/2026-05-15-database-export-core-design.md`.

Included:
- Table selection modes: `prefixed`, `core`, `all`.
- `TableSchema`, `TableRows`, and `ChunkPlan` value objects.
- `ChunkPlanner` with primary-key and offset pagination.
- `SqlDumpFormatter` for schema and insert SQL strings.
- `DatabaseExportManifestBuilder` for metadata arrays matching `database/tables.json`.

Excluded:
- `$wpdb` adapters.
- Live database queries.
- Disk writes for database chunks.
- Resume state persistence.
- Admin UI.
- Restore/import.

## File Structure

- Create `super-sheep-copy/src/Backup/Database/TableSelector.php`
  - Selects table names for `prefixed`, `core`, and `all` modes.
- Create `super-sheep-copy/src/Backup/Database/TableSchema.php`
  - Stores table name, create SQL, primary key, row count, charset, and collation.
- Create `super-sheep-copy/src/Backup/Database/TableRows.php`
  - Stores table name, ordered columns, and row arrays; rejects rows missing expected columns.
- Create `super-sheep-copy/src/Backup/Database/ChunkPlan.php`
  - Stores chunk filename, pagination strategy, table name, primary key, last seen ID, limit, offset, and chunk number.
- Create `super-sheep-copy/src/Backup/Database/ChunkPlanner.php`
  - Creates deterministic `ChunkPlan` objects.
- Create `super-sheep-copy/src/Backup/Database/SqlDumpFormatter.php`
  - Formats schema SQL and row insert SQL.
- Create `super-sheep-copy/src/Backup/Database/DatabaseExportManifestBuilder.php`
  - Creates database export metadata arrays.
- Create tests:
  - `super-sheep-copy/tests/Unit/DatabaseTableSelectorTest.php`
  - `super-sheep-copy/tests/Unit/DatabaseValueObjectsTest.php`
  - `super-sheep-copy/tests/Unit/DatabaseChunkPlannerTest.php`
  - `super-sheep-copy/tests/Unit/SqlDumpFormatterTest.php`
  - `super-sheep-copy/tests/Unit/DatabaseExportManifestBuilderTest.php`

---

### Task 1: Table Selection

**Files:**
- Create: `super-sheep-copy/src/Backup/Database/TableSelector.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseTableSelectorTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/DatabaseTableSelectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\TableSelector;

final class DatabaseTableSelectorTest extends TestCase
{
    public function testSelectsPrefixedTablesInInputOrder(): void
    {
        $selector = new TableSelector();

        self::assertSame(
            array('wp_posts', 'wp_options', 'wp_woocommerce_orders'),
            $selector->select(
                array('wp_posts', 'other_table', 'wp_options', 'custom_logs', 'wp_woocommerce_orders'),
                'wp_',
                TableSelector::MODE_PREFIXED
            )
        );
    }

    public function testSelectsCoreTablesOnly(): void
    {
        $selector = new TableSelector();

        self::assertSame(
            array('wp_options', 'wp_posts', 'wp_postmeta', 'wp_users'),
            $selector->select(
                array('wp_options', 'wp_posts', 'wp_woocommerce_orders', 'wp_postmeta', 'wp_users'),
                'wp_',
                TableSelector::MODE_CORE
            )
        );
    }

    public function testSelectsAllTablesInInputOrder(): void
    {
        $selector = new TableSelector();

        self::assertSame(
            array('wp_posts', 'custom_logs', 'other_table'),
            $selector->select(array('wp_posts', 'custom_logs', 'other_table'), 'wp_', TableSelector::MODE_ALL)
        );
    }

    public function testRejectsEmptyPrefixForPrefixedMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Table prefix is required.');

        (new TableSelector())->select(array('wp_posts'), '', TableSelector::MODE_PREFIXED);
    }

    public function testRejectsUnknownMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown table selection mode.');

        (new TableSelector())->select(array('wp_posts'), 'wp_', 'recent');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseTableSelectorTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\Database\TableSelector" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `super-sheep-copy/src/Backup/Database/TableSelector.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;

final class TableSelector
{
    public const MODE_PREFIXED = 'prefixed';
    public const MODE_CORE = 'core';
    public const MODE_ALL = 'all';

    /**
     * @var string[]
     */
    private array $core_suffixes = array(
        'commentmeta',
        'comments',
        'links',
        'options',
        'postmeta',
        'posts',
        'term_relationships',
        'term_taxonomy',
        'termmeta',
        'terms',
        'usermeta',
        'users',
    );

    /**
     * @param string[] $tables
     * @return string[]
     */
    public function select(array $tables, string $prefix, string $mode): array
    {
        if (!in_array($mode, array(self::MODE_PREFIXED, self::MODE_CORE, self::MODE_ALL), true)) {
            throw new InvalidArgumentException('Unknown table selection mode.');
        }

        if (($mode === self::MODE_PREFIXED || $mode === self::MODE_CORE) && $prefix === '') {
            throw new InvalidArgumentException('Table prefix is required.');
        }

        if ($mode === self::MODE_ALL) {
            return array_values($tables);
        }

        $selected = array();
        $core_tables = array();
        foreach ($this->core_suffixes as $suffix) {
            $core_tables[] = $prefix . $suffix;
        }

        foreach ($tables as $table) {
            if ($mode === self::MODE_PREFIXED && strpos($table, $prefix) === 0) {
                $selected[] = $table;
                continue;
            }

            if ($mode === self::MODE_CORE && in_array($table, $core_tables, true)) {
                $selected[] = $table;
            }
        }

        return $selected;
    }
}
```

- [ ] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseTableSelectorTest.php
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
git add super-sheep-copy/src/Backup/Database/TableSelector.php super-sheep-copy/tests/Unit/DatabaseTableSelectorTest.php
git commit -m "feat: select database export tables"
```

Expected: commit succeeds.

---

### Task 2: Database Value Objects

**Files:**
- Create: `super-sheep-copy/src/Backup/Database/TableSchema.php`
- Create: `super-sheep-copy/src/Backup/Database/TableRows.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseValueObjectsTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/DatabaseValueObjectsTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\TableRows;
use SuperSheepCopy\Backup\Database\TableSchema;

final class DatabaseValueObjectsTest extends TestCase
{
    public function testTableSchemaStoresMetadata(): void
    {
        $schema = new TableSchema(
            'wp_posts',
            'CREATE TABLE `wp_posts` (`ID` bigint unsigned NOT NULL)',
            'ID',
            125,
            'utf8mb4',
            'utf8mb4_unicode_ci'
        );

        self::assertSame('wp_posts', $schema->name());
        self::assertSame('CREATE TABLE `wp_posts` (`ID` bigint unsigned NOT NULL)', $schema->createSql());
        self::assertSame('ID', $schema->primaryKey());
        self::assertSame(125, $schema->rowCount());
        self::assertSame('utf8mb4', $schema->charset());
        self::assertSame('utf8mb4_unicode_ci', $schema->collation());
    }

    public function testTableRowsStoresOrderedColumnsAndRows(): void
    {
        $rows = new TableRows(
            'wp_options',
            array('option_id', 'option_name', 'option_value'),
            array(
                array('option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://website.com'),
                array('option_id' => 2, 'option_name' => 'home', 'option_value' => 'https://website.com'),
            )
        );

        self::assertSame('wp_options', $rows->tableName());
        self::assertSame(array('option_id', 'option_name', 'option_value'), $rows->columns());
        self::assertCount(2, $rows->rows());
    }

    public function testTableSchemaRejectsEmptyTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name is required.');

        new TableSchema('', 'CREATE TABLE `wp_posts` (`ID` bigint)', null, 0, null, null);
    }

    public function testTableRowsRejectRowsMissingExpectedColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Row is missing expected column: option_value');

        new TableRows(
            'wp_options',
            array('option_id', 'option_name', 'option_value'),
            array(array('option_id' => 1, 'option_name' => 'siteurl'))
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseValueObjectsTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\Database\TableSchema" not found`.

- [ ] **Step 3: Add `TableSchema`**

Create `super-sheep-copy/src/Backup/Database/TableSchema.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;

final class TableSchema
{
    private string $name;
    private string $create_sql;
    private ?string $primary_key;
    private int $row_count;
    private ?string $charset;
    private ?string $collation;

    public function __construct(
        string $name,
        string $create_sql,
        ?string $primary_key,
        int $row_count,
        ?string $charset,
        ?string $collation
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Table name is required.');
        }

        if ($create_sql === '') {
            throw new InvalidArgumentException('Create table SQL is required.');
        }

        if ($row_count < 0) {
            throw new InvalidArgumentException('Row count cannot be negative.');
        }

        $this->name = $name;
        $this->create_sql = $create_sql;
        $this->primary_key = $primary_key;
        $this->row_count = $row_count;
        $this->charset = $charset;
        $this->collation = $collation;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function createSql(): string
    {
        return $this->create_sql;
    }

    public function primaryKey(): ?string
    {
        return $this->primary_key;
    }

    public function rowCount(): int
    {
        return $this->row_count;
    }

    public function charset(): ?string
    {
        return $this->charset;
    }

    public function collation(): ?string
    {
        return $this->collation;
    }
}
```

- [ ] **Step 4: Add `TableRows`**

Create `super-sheep-copy/src/Backup/Database/TableRows.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;

final class TableRows
{
    private string $table_name;
    /** @var string[] */
    private array $columns;
    /** @var array<int, array<string, mixed>> */
    private array $rows;

    /**
     * @param string[] $columns
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(string $table_name, array $columns, array $rows)
    {
        if ($table_name === '') {
            throw new InvalidArgumentException('Table name is required.');
        }

        if ($columns === array()) {
            throw new InvalidArgumentException('At least one column is required.');
        }

        foreach ($columns as $column) {
            if (!is_string($column) || $column === '') {
                throw new InvalidArgumentException('Column names must be non-empty strings.');
            }
        }

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if (!array_key_exists($column, $row)) {
                    throw new InvalidArgumentException('Row is missing expected column: ' . $column);
                }
            }
        }

        $this->table_name = $table_name;
        $this->columns = array_values($columns);
        $this->rows = array_values($rows);
    }

    public function tableName(): string
    {
        return $this->table_name;
    }

    /**
     * @return string[]
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }
}
```

- [ ] **Step 5: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseValueObjectsTest.php
```

Expected: PASS with `OK (4 tests`.

- [ ] **Step 6: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 7: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/Database/TableSchema.php super-sheep-copy/src/Backup/Database/TableRows.php super-sheep-copy/tests/Unit/DatabaseValueObjectsTest.php
git commit -m "feat: add database export value objects"
```

Expected: commit succeeds.

---

### Task 3: Chunk Planning

**Files:**
- Create: `super-sheep-copy/src/Backup/Database/ChunkPlan.php`
- Create: `super-sheep-copy/src/Backup/Database/ChunkPlanner.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseChunkPlannerTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/DatabaseChunkPlannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\ChunkPlan;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\TableSchema;

final class DatabaseChunkPlannerTest extends TestCase
{
    public function testPlansPrimaryKeyPagination(): void
    {
        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 250, null, null);
        $plan = (new ChunkPlanner())->plan($schema, 100, 3, 200);

        self::assertSame('wp_posts', $plan->tableName());
        self::assertSame('wp_posts.part003.sql', $plan->fileName());
        self::assertSame(ChunkPlan::STRATEGY_PRIMARY_KEY, $plan->strategy());
        self::assertSame('ID', $plan->primaryKey());
        self::assertSame(200, $plan->lastSeenId());
        self::assertSame(100, $plan->limit());
        self::assertNull($plan->offset());
    }

    public function testPlansOffsetPaginationWhenPrimaryKeyIsMissing(): void
    {
        $schema = new TableSchema('wp_options', 'CREATE TABLE `wp_options` (`option_name` varchar(191))', null, 250, null, null);
        $plan = (new ChunkPlanner())->plan($schema, 100, 3, null);

        self::assertSame('wp_options.part003.sql', $plan->fileName());
        self::assertSame(ChunkPlan::STRATEGY_OFFSET, $plan->strategy());
        self::assertNull($plan->primaryKey());
        self::assertNull($plan->lastSeenId());
        self::assertSame(100, $plan->limit());
        self::assertSame(200, $plan->offset());
    }

    public function testRejectsInvalidChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be greater than zero.');

        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 1, null, null);
        (new ChunkPlanner())->plan($schema, 0, 1, null);
    }

    public function testRejectsInvalidChunkNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk number must be greater than zero.');

        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 1, null, null);
        (new ChunkPlanner())->plan($schema, 100, 0, null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseChunkPlannerTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\Database\ChunkPlan" not found`.

- [ ] **Step 3: Add `ChunkPlan`**

Create `super-sheep-copy/src/Backup/Database/ChunkPlan.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

final class ChunkPlan
{
    public const STRATEGY_PRIMARY_KEY = 'primary_key';
    public const STRATEGY_OFFSET = 'offset';

    private string $table_name;
    private string $file_name;
    private string $strategy;
    private ?string $primary_key;
    private ?int $last_seen_id;
    private int $limit;
    private ?int $offset;
    private int $chunk_number;

    public function __construct(
        string $table_name,
        string $file_name,
        string $strategy,
        ?string $primary_key,
        ?int $last_seen_id,
        int $limit,
        ?int $offset,
        int $chunk_number
    ) {
        $this->table_name = $table_name;
        $this->file_name = $file_name;
        $this->strategy = $strategy;
        $this->primary_key = $primary_key;
        $this->last_seen_id = $last_seen_id;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->chunk_number = $chunk_number;
    }

    public function tableName(): string
    {
        return $this->table_name;
    }

    public function fileName(): string
    {
        return $this->file_name;
    }

    public function strategy(): string
    {
        return $this->strategy;
    }

    public function primaryKey(): ?string
    {
        return $this->primary_key;
    }

    public function lastSeenId(): ?int
    {
        return $this->last_seen_id;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function offset(): ?int
    {
        return $this->offset;
    }

    public function chunkNumber(): int
    {
        return $this->chunk_number;
    }
}
```

- [ ] **Step 4: Add `ChunkPlanner`**

Create `super-sheep-copy/src/Backup/Database/ChunkPlanner.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;

final class ChunkPlanner
{
    public function plan(TableSchema $schema, int $chunk_size, int $chunk_number, ?int $last_seen_id): ChunkPlan
    {
        if ($chunk_size < 1) {
            throw new InvalidArgumentException('Chunk size must be greater than zero.');
        }

        if ($chunk_number < 1) {
            throw new InvalidArgumentException('Chunk number must be greater than zero.');
        }

        $file_name = sprintf('%s.part%03d.sql', $schema->name(), $chunk_number);

        if ($schema->primaryKey() !== null && $schema->primaryKey() !== '') {
            return new ChunkPlan(
                $schema->name(),
                $file_name,
                ChunkPlan::STRATEGY_PRIMARY_KEY,
                $schema->primaryKey(),
                $last_seen_id,
                $chunk_size,
                null,
                $chunk_number
            );
        }

        return new ChunkPlan(
            $schema->name(),
            $file_name,
            ChunkPlan::STRATEGY_OFFSET,
            null,
            null,
            $chunk_size,
            ($chunk_number - 1) * $chunk_size,
            $chunk_number
        );
    }
}
```

- [ ] **Step 5: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseChunkPlannerTest.php
```

Expected: PASS with `OK (4 tests`.

- [ ] **Step 6: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 7: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/Database/ChunkPlan.php super-sheep-copy/src/Backup/Database/ChunkPlanner.php super-sheep-copy/tests/Unit/DatabaseChunkPlannerTest.php
git commit -m "feat: plan database export chunks"
```

Expected: commit succeeds.

---

### Task 4: SQL Dump Formatting

**Files:**
- Create: `super-sheep-copy/src/Backup/Database/SqlDumpFormatter.php`
- Test: `super-sheep-copy/tests/Unit/SqlDumpFormatterTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/SqlDumpFormatterTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\SqlDumpFormatter;
use SuperSheepCopy\Backup\Database\TableRows;
use SuperSheepCopy\Backup\Database\TableSchema;

final class SqlDumpFormatterTest extends TestCase
{
    public function testFormatsSchemaSql(): void
    {
        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 1, 'utf8mb4', 'utf8mb4_unicode_ci');

        self::assertSame(
            "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (`ID` bigint);\n",
            (new SqlDumpFormatter())->formatSchema($schema)
        );
    }

    public function testFormatsInsertSqlWithEscapedValues(): void
    {
        $rows = new TableRows(
            'wp_options',
            array('option_id', 'option_name', 'option_value', 'autoload', 'enabled', 'ratio', 'missing'),
            array(
                array(
                    'option_id' => 1,
                    'option_name' => "site\\url",
                    'option_value' => "Bob's Site",
                    'autoload' => 'yes',
                    'enabled' => true,
                    'ratio' => 1.25,
                    'missing' => null,
                ),
            )
        );

        self::assertSame(
            "INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`, `enabled`, `ratio`, `missing`) VALUES\n" .
            "(1, 'site\\\\url', 'Bob\\'s Site', 'yes', 1, 1.25, NULL);\n",
            (new SqlDumpFormatter())->formatRows($rows)
        );
    }

    public function testReturnsEmptyStringForNoRows(): void
    {
        $rows = new TableRows('wp_options', array('option_id'), array());

        self::assertSame('', (new SqlDumpFormatter())->formatRows($rows));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/SqlDumpFormatterTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\Database\SqlDumpFormatter" not found`.

- [ ] **Step 3: Add formatter**

Create `super-sheep-copy/src/Backup/Database/SqlDumpFormatter.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

final class SqlDumpFormatter
{
    public function formatSchema(TableSchema $schema): string
    {
        return sprintf("DROP TABLE IF EXISTS `%s`;\n%s;\n", $this->escapeIdentifier($schema->name()), rtrim($schema->createSql(), ";\n\r\t "));
    }

    public function formatRows(TableRows $rows): string
    {
        if ($rows->rows() === array()) {
            return '';
        }

        $columns = array_map(function (string $column): string {
            return '`' . $this->escapeIdentifier($column) . '`';
        }, $rows->columns());

        $values = array();
        foreach ($rows->rows() as $row) {
            $formatted = array();
            foreach ($rows->columns() as $column) {
                $formatted[] = $this->formatValue($row[$column]);
            }
            $values[] = '(' . implode(', ', $formatted) . ')';
        }

        return sprintf(
            "INSERT INTO `%s` (%s) VALUES\n%s;\n",
            $this->escapeIdentifier($rows->tableName()),
            implode(', ', $columns),
            implode(",\n", $values)
        );
    }

    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * @param mixed $value
     */
    private function formatValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value) . "'";
    }
}
```

- [ ] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/SqlDumpFormatterTest.php
```

Expected: PASS with `OK (3 tests`.

- [ ] **Step 5: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/Database/SqlDumpFormatter.php super-sheep-copy/tests/Unit/SqlDumpFormatterTest.php
git commit -m "feat: format database export sql"
```

Expected: commit succeeds.

---

### Task 5: Database Export Manifest Builder

**Files:**
- Create: `super-sheep-copy/src/Backup/Database/DatabaseExportManifestBuilder.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseExportManifestBuilderTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/DatabaseExportManifestBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\TableSchema;

final class DatabaseExportManifestBuilderTest extends TestCase
{
    public function testBuildsTablesManifestMetadata(): void
    {
        $posts = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 250, 'utf8mb4', 'utf8mb4_unicode_ci');
        $options = new TableSchema('wp_options', 'CREATE TABLE `wp_options` (`option_name` varchar(191))', null, 10, 'utf8mb4', 'utf8mb4_unicode_ci');
        $planner = new ChunkPlanner();

        $manifest = (new DatabaseExportManifestBuilder())->build(
            array($posts, $options),
            array(
                'wp_posts' => array(
                    $planner->plan($posts, 100, 1, null),
                    $planner->plan($posts, 100, 2, 100),
                ),
                'wp_options' => array(
                    $planner->plan($options, 100, 1, null),
                ),
            )
        );

        self::assertSame('1', $manifest['format_version']);
        self::assertSame(2, $manifest['table_count']);
        self::assertSame('wp_posts', $manifest['tables'][0]['name']);
        self::assertSame('ID', $manifest['tables'][0]['primary_key']);
        self::assertSame(250, $manifest['tables'][0]['row_count']);
        self::assertSame(array('wp_posts.part001.sql', 'wp_posts.part002.sql'), $manifest['tables'][0]['chunks']);
        self::assertSame('primary_key', $manifest['tables'][0]['pagination_strategy']);
        self::assertSame('offset', $manifest['tables'][1]['pagination_strategy']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseExportManifestBuilderTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder" not found`.

- [ ] **Step 3: Add manifest builder**

Create `super-sheep-copy/src/Backup/Database/DatabaseExportManifestBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

final class DatabaseExportManifestBuilder
{
    /**
     * @param TableSchema[] $schemas
     * @param array<string, ChunkPlan[]> $plans_by_table
     * @return array{format_version:string,table_count:int,tables:array<int,array<string,mixed>>}
     */
    public function build(array $schemas, array $plans_by_table): array
    {
        $tables = array();

        foreach ($schemas as $schema) {
            $plans = isset($plans_by_table[$schema->name()]) ? $plans_by_table[$schema->name()] : array();
            $chunks = array();
            $strategy = null;

            foreach ($plans as $plan) {
                $chunks[] = $plan->fileName();
                if ($strategy === null) {
                    $strategy = $plan->strategy();
                }
            }

            $tables[] = array(
                'name' => $schema->name(),
                'row_count' => $schema->rowCount(),
                'primary_key' => $schema->primaryKey(),
                'charset' => $schema->charset(),
                'collation' => $schema->collation(),
                'pagination_strategy' => $strategy,
                'chunks' => $chunks,
            );
        }

        return array(
            'format_version' => '1',
            'table_count' => count($tables),
            'tables' => $tables,
        );
    }
}
```

- [ ] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseExportManifestBuilderTest.php
```

Expected: PASS with `OK (1 test`.

- [ ] **Step 5: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add super-sheep-copy/src/Backup/Database/DatabaseExportManifestBuilder.php super-sheep-copy/tests/Unit/DatabaseExportManifestBuilderTest.php
git commit -m "feat: build database export manifest"
```

Expected: commit succeeds.

---

### Task 6: Final Verification

**Files:**
- Verify: `super-sheep-copy/src/Backup/Database`
- Verify: `super-sheep-copy/tests/Unit/Database*.php`
- Verify: `super-sheep-copy/tests/Unit/SqlDumpFormatterTest.php`

- [ ] **Step 1: Run lint**

Run:

```bash
cd super-sheep-copy && composer run lint
```

Expected: all PHP files report `No syntax errors detected`.

- [ ] **Step 2: Run full PHPUnit suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 3: Confirm no WordPress globals in database core**

Run:

```bash
rg "\\$wpdb|wpdb|wp_" super-sheep-copy/src/Backup/Database
```

Expected: no matches for `$wpdb`, `wpdb`, or WordPress function calls. Matches inside string literals for table names should not exist in production classes.

- [ ] **Step 4: Check git status**

Run:

```bash
git status --short
```

Expected: empty working tree after task commits.

## Self-Review

- Spec coverage: The plan covers table selection modes, value objects, primary-key and offset chunk planning, deterministic chunk filenames, SQL schema/row formatting, value escaping, and database export manifest metadata. It excludes `$wpdb`, live export, disk writes, resume state, UI, and restore work as specified.
- Placeholder scan: No step relies on undefined implementation details. Every new class and test includes concrete code.
- Type consistency: `TableSchema`, `TableRows`, `ChunkPlan`, `ChunkPlanner`, `SqlDumpFormatter`, `TableSelector`, and `DatabaseExportManifestBuilder` use the `SuperSheepCopy\Backup\Database` namespace and are covered by the existing Composer `SuperSheepCopy\` => `src/` mapping.

