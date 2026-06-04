# Installer Database URL Replacement Execution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Add a standalone installer action that replaces source URLs with the destination URL across all swapped database tables using serialization-safe replacement.

**Architecture:** Add installer-only column inspection, URL replacement execution, and orchestration classes. `Bootstrap` loads shared serialization/URL classes, exposes a POST action after table swap, and renders URL replacement gate/status. Replacement runs in one request and persists completion metadata in installer config.

**Tech Stack:** PHP 7.4-compatible standalone PHP, PHPUnit 9.6, mysqli-style fake connections in unit tests, existing shared URL replacement library.

---

## Spec

This plan implements:

`docs/superpowers/specs/2026-05-20-installer-database-url-replacement-execution-design.md`

## Scope

Included:

- Inspect text-like columns in all planned swapped tables.
- Replace URLs with `StructuredValueReplacer`.
- Update changed values only.
- Persist URL replacement completion metadata.
- Add installer UI/action after table swap.

Excluded:

- Resumable batches.
- File URL replacement.
- Cache clearing.
- Rollback execution.
- Real MySQL integration tests.

## File Structure

- Create `super-sheep-copy/installer/restore-engine/DatabaseTextColumnInspector.php`
- Create `super-sheep-copy/tests/Unit/DatabaseTextColumnInspectorTest.php`
- Create `super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementExecutor.php`
- Create `super-sheep-copy/tests/Unit/DatabaseUrlReplacementExecutorTest.php`
- Create `super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementManager.php`
- Create `super-sheep-copy/tests/Unit/DatabaseUrlReplacementManagerTest.php`
- Modify `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Modify `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

---

### Task 1: Database Text Column Inspector

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DatabaseTextColumnInspector.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseTextColumnInspectorTest.php`

- [x] **Step 1: Write failing tests**

Create `DatabaseTextColumnInspectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTextColumnInspector.php';

final class DatabaseTextColumnInspectorTest extends TestCase
{
    public function testReturnsTextColumnsAndPrimaryKey(): void
    {
        $connection = new FakeTextColumnMysqli(array(
            'wp_posts' => array(
                array('Field' => 'ID', 'Type' => 'bigint(20) unsigned', 'Key' => 'PRI'),
                array('Field' => 'post_title', 'Type' => 'varchar(255)', 'Key' => ''),
                array('Field' => 'post_content', 'Type' => 'longtext', 'Key' => ''),
                array('Field' => 'post_date', 'Type' => 'datetime', 'Key' => ''),
                array('Field' => 'binary_payload', 'Type' => 'blob', 'Key' => ''),
            ),
        ));
        $inspector = new \SuperSheepCopyInstaller\DatabaseTextColumnInspector($connection);

        $result = $inspector->inspect(array('wp_posts'));

        self::assertTrue($result['valid']);
        self::assertSame(array(), $result['warnings']);
        self::assertSame(array('post_title', 'post_content'), $result['tables']['wp_posts']['columns']);
        self::assertSame('ID', $result['tables']['wp_posts']['primary_key']);
    }

    public function testIncludesJsonAndTinyTextButExcludesBinaryTypes(): void
    {
        $connection = new FakeTextColumnMysqli(array(
            'wp_options' => array(
                array('Field' => 'option_id', 'Type' => 'bigint(20)', 'Key' => 'PRI'),
                array('Field' => 'option_value', 'Type' => 'longtext', 'Key' => ''),
                array('Field' => 'settings_json', 'Type' => 'json', 'Key' => ''),
                array('Field' => 'short_note', 'Type' => 'tinytext', 'Key' => ''),
                array('Field' => 'raw_file', 'Type' => 'longblob', 'Key' => ''),
            ),
        ));

        $result = (new \SuperSheepCopyInstaller\DatabaseTextColumnInspector($connection))->inspect(array('wp_options'));

        self::assertTrue($result['valid']);
        self::assertSame(array('option_value', 'settings_json', 'short_note'), $result['tables']['wp_options']['columns']);
    }

    public function testRejectsInvalidTableIdentifier(): void
    {
        $connection = new FakeTextColumnMysqli(array());

        $result = (new \SuperSheepCopyInstaller\DatabaseTextColumnInspector($connection))->inspect(array('wp-posts'));

        self::assertFalse($result['valid']);
        self::assertSame(array('Invalid database table identifier: wp-posts'), $result['warnings']);
        self::assertSame(array(), $connection->queries);
    }

    public function testReportsColumnInspectionFailure(): void
    {
        $connection = new FakeTextColumnMysqli(array(), true);

        $result = (new \SuperSheepCopyInstaller\DatabaseTextColumnInspector($connection))->inspect(array('wp_posts'));

        self::assertFalse($result['valid']);
        self::assertSame(array('Unable to inspect columns for table: wp_posts'), $result['warnings']);
    }
}

final class FakeTextColumnMysqli
{
    /** @var array<string,list<array<string,string>>> */
    private array $columns;
    private bool $query_fails;
    /** @var list<string> */
    public array $queries = array();
    public int $close_count = 0;

    /**
     * @param array<string,list<array<string,string>>> $columns
     */
    public function __construct(array $columns, bool $query_fails = false)
    {
        $this->columns = $columns;
        $this->query_fails = $query_fails;
    }

    public function query(string $sql)
    {
        $this->queries[] = $sql;
        if ($this->query_fails) {
            return false;
        }

        if (preg_match('/^SHOW COLUMNS FROM `([^`]+)`$/', $sql, $matches) !== 1) {
            return false;
        }

        return new FakeTextColumnResult($this->columns[$matches[1]] ?? array());
    }

    public function close(): void
    {
        ++$this->close_count;
    }
}

final class FakeTextColumnResult
{
    /** @var list<array<string,string>> */
    private array $rows;

    /**
     * @param list<array<string,string>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function fetch_assoc()
    {
        return array_shift($this->rows);
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseTextColumnInspectorTest.php
```

Expected: FAIL because `DatabaseTextColumnInspector.php` is missing.

- [x] **Step 3: Add inspector implementation**

Create `DatabaseTextColumnInspector.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class DatabaseTextColumnInspector
{
    /** @var mixed */
    private $mysqli;

    /**
     * @param mixed $mysqli
     */
    public function __construct($mysqli = null)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @param list<string> $tables
     * @param array<string,mixed> $credentials
     * @return array{valid:bool,tables:array<string,array{columns:list<string>,primary_key:string}>,warnings:list<string>}
     */
    public function inspect(array $tables, array $credentials = array()): array
    {
        $connection = $this->mysqli;
        $should_close = false;
        if (!is_object($connection) && $credentials !== array()) {
            $connection = $this->connect($credentials);
            $should_close = is_object($connection);
        }

        if (!is_object($connection) || !method_exists($connection, 'query')) {
            return array('valid' => false, 'tables' => array(), 'warnings' => array('Unable to inspect database columns.'));
        }

        $metadata = array();
        $warnings = array();
        foreach ($tables as $table) {
            if (!$this->isIdentifier($table)) {
                $warnings[] = 'Invalid database table identifier: ' . $table;
                continue;
            }

            $result = $connection->query('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table));
            if (!is_object($result) || !method_exists($result, 'fetch_assoc')) {
                $warnings[] = 'Unable to inspect columns for table: ' . $table;
                continue;
            }

            $columns = array();
            $primary_key = '';
            while (is_array($row = $result->fetch_assoc())) {
                if (!isset($row['Field'], $row['Type'])) {
                    continue;
                }
                $field = (string) $row['Field'];
                if (!$this->isIdentifier($field)) {
                    continue;
                }
                if (isset($row['Key']) && (string) $row['Key'] === 'PRI' && $primary_key === '') {
                    $primary_key = $field;
                }
                if ($this->isTextType((string) $row['Type'])) {
                    $columns[] = $field;
                }
            }

            $metadata[$table] = array('columns' => $columns, 'primary_key' => $primary_key);
        }

        if ($should_close && is_object($connection) && method_exists($connection, 'close')) {
            $connection->close();
        }

        return array('valid' => $warnings === array(), 'tables' => $warnings === array() ? $metadata : array(), 'warnings' => $warnings);
    }

    /**
     * @param array<string,mixed> $credentials
     * @return mixed
     */
    protected function connect(array $credentials)
    {
        if (!class_exists('\\mysqli')) {
            return null;
        }

        \mysqli_report(MYSQLI_REPORT_OFF);

        return @new \mysqli(
            isset($credentials['host']) ? (string) $credentials['host'] : '',
            isset($credentials['user']) ? (string) $credentials['user'] : '',
            isset($credentials['password']) ? (string) $credentials['password'] : '',
            isset($credentials['name']) ? (string) $credentials['name'] : '',
            isset($credentials['port']) ? (int) $credentials['port'] : 0,
            isset($credentials['socket']) ? (string) $credentials['socket'] : ''
        );
    }

    private function isTextType(string $type): bool
    {
        $type = strtolower($type);

        return preg_match('/^(?:var)?char\\b|^(?:tiny|medium|long)?text\\b|^json\\b/', $type) === 1;
    }

    private function isIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
```

- [x] **Step 4: Run focused test to verify GREEN**

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseTextColumnInspectorTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add super-sheep-copy/installer/restore-engine/DatabaseTextColumnInspector.php super-sheep-copy/tests/Unit/DatabaseTextColumnInspectorTest.php
git commit -m "feat: inspect database text columns"
```

---

### Task 2: Database URL Replacement Executor

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementExecutor.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseUrlReplacementExecutorTest.php`

- [x] **Step 1: Write failing tests**

Create `DatabaseUrlReplacementExecutorTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Shared\Serialization\SerializationWalker;
use SuperSheepCopy\Shared\Urls\StructuredValueReplacer;
use SuperSheepCopy\Shared\Urls\UrlReplacementEngine;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseUrlReplacementExecutor.php';

final class DatabaseUrlReplacementExecutorTest extends TestCase
{
    public function testUpdatesPlainJsonAndSerializedValues(): void
    {
        $connection = new FakeUrlReplacementMysqli(array(
            'wp_posts' => array(
                array(
                    'ID' => '1',
                    'post_content' => 'Visit https://source.example/page',
                    'post_meta' => '{"url":"https:\/\/source.example\/json"}',
                    'post_settings' => serialize(array('url' => 'https://source.example/serialized')),
                ),
            ),
        ));
        $executor = new \SuperSheepCopyInstaller\DatabaseUrlReplacementExecutor($connection);

        $result = $executor->execute(
            array('complete' => true),
            array('source_urls' => array('https://source.example'), 'destination_url' => 'https://destination.example'),
            array('wp_posts' => array('columns' => array('post_content', 'post_meta', 'post_settings'), 'primary_key' => 'ID')),
            new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker())
        );

        self::assertTrue($result['completed']);
        self::assertSame(1, $result['scanned_rows']);
        self::assertSame(1, $result['changed_rows']);
        self::assertSame(3, $result['changed_cells']);
        self::assertSame(3, $result['replacement_count']);
        self::assertCount(3, $connection->updates);
        self::assertStringContainsString('UPDATE `wp_posts` SET `post_content` = ', $connection->updates[0]);
        self::assertStringContainsString('WHERE `ID` = ', $connection->updates[0]);
    }

    public function testSkipsUnchangedValues(): void
    {
        $connection = new FakeUrlReplacementMysqli(array(
            'wp_options' => array(array('option_id' => '1', 'option_value' => 'unchanged')),
        ));
        $executor = new \SuperSheepCopyInstaller\DatabaseUrlReplacementExecutor($connection);

        $result = $executor->execute(
            array('complete' => true),
            array('source_urls' => array('https://source.example'), 'destination_url' => 'https://destination.example'),
            array('wp_options' => array('columns' => array('option_value'), 'primary_key' => 'option_id')),
            new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker())
        );

        self::assertTrue($result['completed']);
        self::assertSame(1, $result['scanned_rows']);
        self::assertSame(0, $result['changed_rows']);
        self::assertSame(0, $result['changed_cells']);
        self::assertSame(array(), $connection->updates);
    }

    public function testRejectsInvalidColumnIdentifier(): void
    {
        $connection = new FakeUrlReplacementMysqli(array());
        $executor = new \SuperSheepCopyInstaller\DatabaseUrlReplacementExecutor($connection);

        $result = $executor->execute(
            array('complete' => true),
            array('source_urls' => array('https://source.example'), 'destination_url' => 'https://destination.example'),
            array('wp_posts' => array('columns' => array('post-content'), 'primary_key' => 'ID')),
            new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker())
        );

        self::assertFalse($result['completed']);
        self::assertSame(array('Invalid database column identifier: post-content'), $result['warnings']);
        self::assertSame(array(), $connection->updates);
    }
}

final class FakeUrlReplacementMysqli
{
    /** @var array<string,list<array<string,string>>> */
    private array $rows;
    /** @var list<string> */
    public array $selects = array();
    /** @var list<string> */
    public array $updates = array();

    /**
     * @param array<string,list<array<string,string>>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function real_escape_string(string $value): string
    {
        return addslashes($value);
    }

    public function query(string $sql)
    {
        if (strpos($sql, 'SELECT ') === 0) {
            $this->selects[] = $sql;
            if (preg_match('/FROM `([^`]+)`/', $sql, $matches) !== 1) {
                return false;
            }

            return new FakeUrlReplacementResult($this->rows[$matches[1]] ?? array());
        }

        if (strpos($sql, 'UPDATE ') === 0) {
            $this->updates[] = $sql;

            return true;
        }

        return false;
    }

    public function close(): void
    {
    }
}

final class FakeUrlReplacementResult
{
    /** @var list<array<string,string>> */
    private array $rows;

    /**
     * @param list<array<string,string>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function fetch_assoc()
    {
        return array_shift($this->rows);
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseUrlReplacementExecutorTest.php
```

Expected: FAIL because `DatabaseUrlReplacementExecutor.php` is missing.

- [x] **Step 3: Add executor implementation**

Create `DatabaseUrlReplacementExecutor.php` with this public API and behavior:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use SuperSheepCopy\Shared\Urls\StructuredValueReplacer;

class DatabaseUrlReplacementExecutor
{
    /** @var mixed */
    private $mysqli;

    /**
     * @param mixed $mysqli
     */
    public function __construct($mysqli = null)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @param array<string,mixed> $credentials
     * @param array<string,mixed> $plan
     * @param array<string,array{columns:list<string>,primary_key:string}> $tables
     * @return array{completed:bool,table_count:int,scanned_rows:int,changed_rows:int,scanned_cells:int,changed_cells:int,replacement_count:int,warnings:list<string>}
     */
    public function execute(array $credentials, array $plan, array $tables, StructuredValueReplacer $replacer): array
    {
        $connection = $this->mysqli;
        $should_close = false;
        if (!is_object($connection)) {
            $connection = $this->connect($credentials);
            $should_close = is_object($connection);
        }
        if (!is_object($connection) || !method_exists($connection, 'query')) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Database connection failed.'));
        }

        $source_urls = isset($plan['source_urls']) && is_array($plan['source_urls']) ? $this->stringList($plan['source_urls']) : array();
        $destination_url = isset($plan['destination_url']) ? (string) $plan['destination_url'] : '';
        if ($source_urls === array() || $destination_url === '') {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('URL replacement plan is malformed.'));
        }

        $scanned_rows = 0;
        $changed_rows = 0;
        $scanned_cells = 0;
        $changed_cells = 0;
        $replacement_count = 0;
        $warnings = array();

        foreach ($tables as $table => $metadata) {
            if (!$this->isIdentifier($table)) {
                $warnings[] = 'Invalid database table identifier: ' . $table;
                continue;
            }
            $primary_key = isset($metadata['primary_key']) ? (string) $metadata['primary_key'] : '';
            $columns = isset($metadata['columns']) ? $metadata['columns'] : array();
            foreach (array_merge($columns, $primary_key === '' ? array() : array($primary_key)) as $identifier) {
                if (!$this->isIdentifier((string) $identifier)) {
                    $warnings[] = 'Invalid database column identifier: ' . (string) $identifier;
                    continue 2;
                }
            }
            if ($columns === array()) {
                continue;
            }

            $select_columns = $primary_key === '' ? $columns : array_merge(array($primary_key), $columns);
            $result = $connection->query('SELECT ' . implode(', ', array_map(array($this, 'quoteIdentifier'), $select_columns)) . ' FROM ' . $this->quoteIdentifier($table));
            if (!is_object($result) || !method_exists($result, 'fetch_assoc')) {
                $warnings[] = 'Unable to scan table: ' . $table;
                continue;
            }

            while (is_array($row = $result->fetch_assoc())) {
                ++$scanned_rows;
                $row_changed = false;
                foreach ($columns as $column) {
                    ++$scanned_cells;
                    $original = isset($row[$column]) && is_scalar($row[$column]) ? (string) $row[$column] : '';
                    $value = $original;
                    $cell_replacements = 0;
                    foreach ($source_urls as $source_url) {
                        $cell_result = $replacer->replace($value, $source_url, $destination_url);
                        $value = $cell_result->value();
                        $cell_replacements += $cell_result->replacementCount();
                    }
                    if ($value === $original) {
                        continue;
                    }
                    if (!$this->updateCell($connection, $table, $column, $value, $primary_key, $row)) {
                        $warnings[] = 'Unable to update table: ' . $table;
                        continue 3;
                    }
                    $row_changed = true;
                    ++$changed_cells;
                    $replacement_count += $cell_replacements;
                }
                if ($row_changed) {
                    ++$changed_rows;
                }
            }
        }

        if ($should_close && is_object($connection) && method_exists($connection, 'close')) {
            $connection->close();
        }

        return $this->result($warnings === array(), count($tables), $scanned_rows, $changed_rows, $scanned_cells, $changed_cells, $replacement_count, $warnings);
    }

    /**
     * @param array<string,mixed> $credentials
     * @return mixed
     */
    protected function connect(array $credentials)
    {
        if (!class_exists('\\mysqli')) {
            return null;
        }

        \mysqli_report(MYSQLI_REPORT_OFF);

        return @new \mysqli(
            isset($credentials['host']) ? (string) $credentials['host'] : '',
            isset($credentials['user']) ? (string) $credentials['user'] : '',
            isset($credentials['password']) ? (string) $credentials['password'] : '',
            isset($credentials['name']) ? (string) $credentials['name'] : '',
            isset($credentials['port']) ? (int) $credentials['port'] : 0,
            isset($credentials['socket']) ? (string) $credentials['socket'] : ''
        );
    }

    /**
     * @param mixed $connection
     * @param array<string,mixed> $row
     */
    private function updateCell($connection, string $table, string $column, string $value, string $primary_key, array $row): bool
    {
        if ($primary_key === '' || !array_key_exists($primary_key, $row)) {
            return false;
        }

        $escaped_value = method_exists($connection, 'real_escape_string') ? $connection->real_escape_string($value) : addslashes($value);
        $escaped_pk = method_exists($connection, 'real_escape_string') ? $connection->real_escape_string((string) $row[$primary_key]) : addslashes((string) $row[$primary_key]);

        return $connection->query(
            'UPDATE ' . $this->quoteIdentifier($table)
            . ' SET ' . $this->quoteIdentifier($column) . " = '" . $escaped_value . "'"
            . ' WHERE ' . $this->quoteIdentifier($primary_key) . " = '" . $escaped_pk . "'"
        ) === true;
    }

    private function isIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $strings = array();
        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $strings[] = (string) $value;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param list<string> $warnings
     * @return array{completed:bool,table_count:int,scanned_rows:int,changed_rows:int,scanned_cells:int,changed_cells:int,replacement_count:int,warnings:list<string>}
     */
    private function result(bool $completed, int $table_count, int $scanned_rows, int $changed_rows, int $scanned_cells, int $changed_cells, int $replacement_count, array $warnings): array
    {
        return array(
            'completed' => $completed,
            'table_count' => $table_count,
            'scanned_rows' => $scanned_rows,
            'changed_rows' => $changed_rows,
            'scanned_cells' => $scanned_cells,
            'changed_cells' => $changed_cells,
            'replacement_count' => $replacement_count,
            'warnings' => $warnings,
        );
    }
}
```

- [x] **Step 4: Run focused test to verify GREEN**

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseUrlReplacementExecutorTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementExecutor.php super-sheep-copy/tests/Unit/DatabaseUrlReplacementExecutorTest.php
git commit -m "feat: execute database url replacement"
```

---

### Task 3: Database URL Replacement Manager

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementManager.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseUrlReplacementManagerTest.php`

- [x] **Step 1: Write failing tests**

Create `DatabaseUrlReplacementManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTextColumnInspector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseUrlReplacementExecutor.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseUrlReplacementManager.php';

final class DatabaseUrlReplacementManagerTest extends TestCase
{
    private string $root_dir;
    private string $engine_dir;

    protected function setUp(): void
    {
        $this->root_dir = sys_get_temp_dir() . '/ssc-url-manager-' . bin2hex(random_bytes(4));
        $this->engine_dir = $this->root_dir . '/ssc-restore-engine';
        mkdir($this->engine_dir, 0777, true);
        file_put_contents($this->root_dir . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'db');\n"
            . "define('DB_USER', 'user');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");
        file_put_contents($this->engine_dir . '/config.php', "<?php\nreturn array();\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root_dir);
    }

    public function testRejectsMissingTableSwap(): void
    {
        $result = $this->manager()->replace($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_url_replacement_plan' => $this->plan(),
        ));

        self::assertFalse($result['completed']);
        self::assertSame(array('Database tables must be swapped before URL replacement.'), $result['warnings']);
    }

    public function testRejectsMissingUrlPlan(): void
    {
        $result = $this->manager()->replace($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_tables_swapped' => true,
        ));

        self::assertFalse($result['completed']);
        self::assertSame(array('URL replacement plan is missing.'), $result['warnings']);
    }

    public function testRecordsCompletionMetadata(): void
    {
        $manager = $this->manager(
            new FakeUrlManagerColumnInspector(array(
                'wp_posts' => array('columns' => array('post_content'), 'primary_key' => 'ID'),
            )),
            new FakeUrlManagerExecutor(array(
                'completed' => true,
                'table_count' => 1,
                'scanned_rows' => 2,
                'changed_rows' => 1,
                'scanned_cells' => 2,
                'changed_cells' => 1,
                'replacement_count' => 3,
                'warnings' => array(),
            ))
        );

        $result = $manager->replace($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_tables_swapped' => true,
            'database_url_replacement_plan' => $this->plan(),
            'locked' => true,
        ));

        self::assertTrue($result['completed']);
        $config = require $this->engine_dir . '/config.php';
        self::assertTrue($config['database_url_replacement_completed']);
        self::assertTrue($config['locked']);
        self::assertSame(1, $config['database_url_replacement_table_count']);
        self::assertSame(2, $config['database_url_replacement_scanned_rows']);
        self::assertSame(1, $config['database_url_replacement_changed_rows']);
        self::assertSame(3, $config['database_url_replacement_count']);
    }

    private function manager(?FakeUrlManagerColumnInspector $inspector = null, ?FakeUrlManagerExecutor $executor = null): \SuperSheepCopyInstaller\DatabaseUrlReplacementManager
    {
        return new \SuperSheepCopyInstaller\DatabaseUrlReplacementManager(
            new \SuperSheepCopyInstaller\WpConfigReader(),
            new FakeUrlManagerConnectionTester(),
            $inspector === null ? new FakeUrlManagerColumnInspector(array()) : $inspector,
            $executor === null ? new FakeUrlManagerExecutor(array()) : $executor
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function plan(): array
    {
        return array(
            'status' => 'planned',
            'source_urls' => array('https://source.example'),
            'destination_url' => 'https://destination.example',
            'tables' => array('wp_posts'),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $item) {
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

final class FakeUrlManagerConnectionTester extends \SuperSheepCopyInstaller\DatabaseConnectionTester
{
    public function test(array $credentials): array
    {
        return array('connected' => true, 'status' => 'ok', 'message' => '', 'database' => 'db', 'host' => 'localhost');
    }
}

final class FakeUrlManagerColumnInspector extends \SuperSheepCopyInstaller\DatabaseTextColumnInspector
{
    /** @var array<string,array{columns:list<string>,primary_key:string}> */
    private array $tables;

    /**
     * @param array<string,array{columns:list<string>,primary_key:string}> $tables
     */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function inspect(array $tables, array $credentials = array()): array
    {
        unset($credentials);
        $metadata = array();
        foreach ($tables as $table) {
            $metadata[$table] = $this->tables[$table] ?? array('columns' => array(), 'primary_key' => '');
        }

        return array('valid' => true, 'tables' => $metadata, 'warnings' => array());
    }
}

final class FakeUrlManagerExecutor extends \SuperSheepCopyInstaller\DatabaseUrlReplacementExecutor
{
    /** @var array<string,mixed> */
    private array $result;

    /**
     * @param array<string,mixed> $result
     */
    public function __construct(array $result)
    {
        $this->result = $result;
    }

    public function execute(array $credentials, array $plan, array $tables, \SuperSheepCopy\Shared\Urls\StructuredValueReplacer $replacer): array
    {
        unset($credentials, $plan, $tables, $replacer);

        return $this->result === array()
            ? array('completed' => true, 'table_count' => 0, 'scanned_rows' => 0, 'changed_rows' => 0, 'scanned_cells' => 0, 'changed_cells' => 0, 'replacement_count' => 0, 'warnings' => array())
            : $this->result;
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseUrlReplacementManagerTest.php
```

Expected: FAIL because `DatabaseUrlReplacementManager.php` is missing.

- [x] **Step 3: Add manager implementation**

Create `DatabaseUrlReplacementManager.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use SuperSheepCopy\Shared\Serialization\SerializationWalker;
use SuperSheepCopy\Shared\Urls\StructuredValueReplacer;
use SuperSheepCopy\Shared\Urls\UrlReplacementEngine;

final class DatabaseUrlReplacementManager
{
    private WpConfigReader $wp_config;
    private DatabaseConnectionTester $connection_tester;
    private DatabaseTextColumnInspector $column_inspector;
    private DatabaseUrlReplacementExecutor $executor;

    public function __construct(
        WpConfigReader $wp_config,
        DatabaseConnectionTester $connection_tester,
        DatabaseTextColumnInspector $column_inspector,
        DatabaseUrlReplacementExecutor $executor
    ) {
        $this->wp_config = $wp_config;
        $this->connection_tester = $connection_tester;
        $this->column_inspector = $column_inspector;
        $this->executor = $executor;
    }

    /**
     * @param array<string,mixed> $config
     * @return array{completed:bool,table_count:int,scanned_rows:int,changed_rows:int,scanned_cells:int,changed_cells:int,replacement_count:int,warnings:list<string>}
     */
    public function replace(string $engine_dir, array $config): array
    {
        if (empty($config['restore_confirmed'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Restore is not confirmed.'));
        }
        if (empty($config['rollback_prepared'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Rollback is not prepared.'));
        }
        if (empty($config['rollback_database_dump'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Database URL replacement requires a database rollback dump.'));
        }
        if (empty($config['database_tables_swapped'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Database tables must be swapped before URL replacement.'));
        }
        if (!empty($config['database_url_replacement_completed'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Database URL replacement is already completed.'));
        }

        $plan = isset($config['database_url_replacement_plan']) && is_array($config['database_url_replacement_plan'])
            ? $config['database_url_replacement_plan']
            : array();
        if (!$this->isValidPlan($plan)) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('URL replacement plan is missing.'));
        }

        $credentials = $this->wp_config->readDatabaseCredentials(dirname(rtrim($engine_dir, '/\\')));
        if (empty($credentials['complete'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Database credentials are incomplete.'));
        }
        $connection = $this->connection_tester->test($credentials);
        if (empty($connection['connected'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array(isset($connection['message']) ? (string) $connection['message'] : 'Database connection failed.'));
        }

        $tables = $this->stringList($plan['tables']);
        $inspection = $this->column_inspector->inspect($tables, $credentials);
        if (empty($inspection['valid'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, $this->stringList(isset($inspection['warnings']) ? $inspection['warnings'] : array()));
        }

        $started_at = gmdate('c');
        $replacement = $this->executor->execute(
            $credentials,
            $plan,
            isset($inspection['tables']) && is_array($inspection['tables']) ? $inspection['tables'] : array(),
            new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker())
        );
        if (empty($replacement['completed'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, $this->stringList(isset($replacement['warnings']) ? $replacement['warnings'] : array('Database URL replacement failed.')));
        }

        $config['database_url_replacement_started_at'] = $started_at;
        $config['database_url_replacement_completed'] = true;
        $config['database_url_replacement_completed_at'] = gmdate('c');
        $config['database_url_replacement_table_count'] = (int) $replacement['table_count'];
        $config['database_url_replacement_scanned_rows'] = (int) $replacement['scanned_rows'];
        $config['database_url_replacement_changed_rows'] = (int) $replacement['changed_rows'];
        $config['database_url_replacement_scanned_cells'] = (int) $replacement['scanned_cells'];
        $config['database_url_replacement_changed_cells'] = (int) $replacement['changed_cells'];
        $config['database_url_replacement_count'] = (int) $replacement['replacement_count'];
        $config['database_url_replacement_warnings'] = $this->stringList(isset($replacement['warnings']) ? $replacement['warnings'] : array());
        $config['locked'] = true;

        if (!$this->writeConfig(rtrim($engine_dir, '/\\'), $config)) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Unable to update installer config.'));
        }

        return $this->result(true, (int) $replacement['table_count'], (int) $replacement['scanned_rows'], (int) $replacement['changed_rows'], (int) $replacement['scanned_cells'], (int) $replacement['changed_cells'], (int) $replacement['replacement_count'], array());
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function isValidPlan(array $plan): bool
    {
        return isset($plan['source_urls'], $plan['destination_url'], $plan['tables'])
            && is_array($plan['source_urls'])
            && is_array($plan['tables'])
            && (string) $plan['destination_url'] !== '';
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private function stringList($values): array
    {
        if (!is_array($values)) {
            return array();
        }
        $strings = array();
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function writeConfig(string $engine_dir, array $config): bool
    {
        return file_put_contents($engine_dir . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n") !== false;
    }

    /**
     * @param list<string> $warnings
     * @return array{completed:bool,table_count:int,scanned_rows:int,changed_rows:int,scanned_cells:int,changed_cells:int,replacement_count:int,warnings:list<string>}
     */
    private function result(bool $completed, int $table_count, int $scanned_rows, int $changed_rows, int $scanned_cells, int $changed_cells, int $replacement_count, array $warnings): array
    {
        return array(
            'completed' => $completed,
            'table_count' => $table_count,
            'scanned_rows' => $scanned_rows,
            'changed_rows' => $changed_rows,
            'scanned_cells' => $scanned_cells,
            'changed_cells' => $changed_cells,
            'replacement_count' => $replacement_count,
            'warnings' => $warnings,
        );
    }
}
```

- [x] **Step 4: Run focused test to verify GREEN**

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseUrlReplacementManagerTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementManager.php super-sheep-copy/tests/Unit/DatabaseUrlReplacementManagerTest.php
git commit -m "feat: manage database url replacement"
```

---

### Task 4: Bootstrap UI and Wiring

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Modify: `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

- [x] **Step 1: Write failing Bootstrap tests**

Append tests to `InstallerBootstrapTest` before `writeConfig()`:

```php
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRendersDatabaseUrlReplacementActionAfterTableSwap(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('secret', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_tables_swapped' => true,
            'database_url_replacement_plan' => array(
                'status' => 'planned',
                'source_urls' => array('https://source.example'),
                'destination_url' => 'https://destination.example',
                'tables' => array('wp_posts'),
            ),
            'locked' => true,
        ));
        $_GET['token'] = 'secret';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Database URL Replacement', $html);
        self::assertStringContainsString('Replace source URLs in swapped database tables.', $html);
        self::assertStringContainsString('name="replace_database_urls"', $html);
        self::assertStringContainsString('Replace Database URLs', $html);
        self::assertStringNotContainsString('secret', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRendersCompletedDatabaseUrlReplacementStatus(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('secret', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_tables_swapped' => true,
            'database_url_replacement_completed' => true,
            'database_url_replacement_table_count' => 2,
            'database_url_replacement_changed_rows' => 3,
            'database_url_replacement_changed_cells' => 4,
            'database_url_replacement_count' => 5,
            'locked' => true,
        ));
        $_GET['token'] = 'secret';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Database URLs replaced.', $html);
        self::assertStringContainsString('2 tables scanned.', $html);
        self::assertStringContainsString('3 rows changed.', $html);
        self::assertStringContainsString('4 cells changed.', $html);
        self::assertStringContainsString('5 replacements.', $html);
        self::assertStringNotContainsString('name="replace_database_urls"', $html);
    }
```

- [x] **Step 2: Run focused test to verify RED**

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: FAIL because Bootstrap has no `Database URL Replacement` section.

- [x] **Step 3: Wire classes in Bootstrap**

Modify `Bootstrap.php` near existing `require_once` calls:

```php
require_once dirname(__DIR__, 2) . '/shared/Serialization/SerializationWalkerInterface.php';
require_once dirname(__DIR__, 2) . '/shared/Serialization/SerializationWalker.php';
require_once dirname(__DIR__, 2) . '/shared/Urls/UrlReplacementEngineInterface.php';
require_once dirname(__DIR__, 2) . '/shared/Urls/UrlReplacementEngine.php';
require_once dirname(__DIR__, 2) . '/shared/Urls/StructuredValueReplacementResult.php';
require_once dirname(__DIR__, 2) . '/shared/Urls/StructuredValueReplacer.php';
require_once __DIR__ . '/DatabaseTextColumnInspector.php';
require_once __DIR__ . '/DatabaseUrlReplacementExecutor.php';
require_once __DIR__ . '/DatabaseUrlReplacementManager.php';
```

Add POST handling after table swap handling:

```php
        $url_replacement_message = '';
        if (self::requestMethod() === 'POST' && isset($_POST['replace_database_urls'])) {
            $manager = new DatabaseUrlReplacementManager(
                $wp_config,
                $database_tester,
                new DatabaseTextColumnInspector(),
                new DatabaseUrlReplacementExecutor()
            );
            $replacement_result = $manager->replace($engine_dir, $config);
            $config = self::loadConfig($engine_dir);
            if ($replacement_result['completed']) {
                $url_replacement_message = 'Database URLs replaced.';
            } else {
                $url_replacement_message = isset($replacement_result['warnings'][0]) ? $replacement_result['warnings'][0] : 'Database URL replacement failed.';
            }
        }
```

Add UI section after Database Table Swap section:

```php
        echo '<h2>Database URL Replacement</h2>';
        if ($url_replacement_message !== '') {
            echo '<div class="status ' . (!empty($config['database_url_replacement_completed']) ? 'ok' : 'warning') . '">' . htmlspecialchars($url_replacement_message, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        if (empty($config['restore_confirmed'])) {
            echo '<div class="status warning">Database URL replacement requires restore confirmation.</div>';
        } elseif (empty($config['rollback_prepared'])) {
            echo '<div class="status warning">Database URL replacement requires rollback preparation.</div>';
        } elseif (empty($config['rollback_database_dump'])) {
            echo '<div class="status warning">Database URL replacement requires database rollback dump.</div>';
        } elseif (empty($config['database_tables_swapped'])) {
            echo '<div class="status warning">Database URL replacement requires swapped database tables.</div>';
        } elseif (empty($config['database_url_replacement_plan']) || !is_array($config['database_url_replacement_plan'])) {
            echo '<div class="status warning">Database URL replacement requires a recorded URL replacement plan.</div>';
        } elseif (!empty($config['database_url_replacement_completed'])) {
            echo '<div class="status ok">Database URLs replaced. '
                . htmlspecialchars((string) ($config['database_url_replacement_table_count'] ?? 0), ENT_QUOTES, 'UTF-8') . ' tables scanned. '
                . htmlspecialchars((string) ($config['database_url_replacement_changed_rows'] ?? 0), ENT_QUOTES, 'UTF-8') . ' rows changed. '
                . htmlspecialchars((string) ($config['database_url_replacement_changed_cells'] ?? 0), ENT_QUOTES, 'UTF-8') . ' cells changed. '
                . htmlspecialchars((string) ($config['database_url_replacement_count'] ?? 0), ENT_QUOTES, 'UTF-8') . ' replacements.</div>';
        } else {
            echo '<div class="status warning">Replace source URLs in swapped database tables.</div>';
            echo '<form method="post">';
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
            echo '<input type="hidden" name="replace_database_urls" value="1">';
            echo '<p><button type="submit">Replace Database URLs</button></p>';
            echo '</form>';
        }
```

- [x] **Step 4: Run focused test to verify GREEN**

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add super-sheep-copy/installer/restore-engine/Bootstrap.php super-sheep-copy/tests/Unit/InstallerBootstrapTest.php
git commit -m "feat: add database url replacement installer action"
```

---

### Task 5: Full Verification

**Files:**
- No code changes expected.

Verification completed on 2026-05-20:

- `cd super-sheep-copy && composer run lint`
  - Exit code: 0
  - Result: all PHP files reported `No syntax errors detected`.
- `cd super-sheep-copy && ./vendor/bin/phpunit`
  - Exit code: 0
  - Result: `OK (180 tests, 721 assertions)`.

Post-review verification completed on 2026-05-20 after hardening no-primary-key updates and serialized object handling:

- `cd super-sheep-copy && composer run lint`
  - Exit code: 0
  - Result: all PHP files reported `No syntax errors detected`.
- `cd super-sheep-copy && ./vendor/bin/phpunit`
  - Exit code: 0
  - Result: `OK (182 tests, 731 assertions)`.

Final post-review verification completed on 2026-05-20 after serialized object token replacement:

- `cd super-sheep-copy && composer run lint`
  - Exit code: 0
  - Result: all PHP files reported `No syntax errors detected`.
- `cd super-sheep-copy && ./vendor/bin/phpunit`
  - Exit code: 0
  - Result: `OK (182 tests, 733 assertions)`.

- [x] **Step 1: Run lint**

```bash
cd super-sheep-copy && composer run lint
```

Expected: all PHP files report `No syntax errors detected`.

- [x] **Step 2: Run full PHPUnit suite**

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 3: Record verification in plan**

Edit this plan and mark completed implementation steps checked only after they are actually completed during execution. Add exact verification output summary under this task.

- [x] **Step 4: Commit verification note**

```bash
git add docs/superpowers/plans/2026-05-20-installer-database-url-replacement-execution.md
git commit -m "docs: record database url replacement verification"
```
