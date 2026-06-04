# Installer DB Preflight and Rollback Dump Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Verify installer database connectivity and include a non-destructive destination database rollback dump in rollback preparation.

**Architecture:** Keep database restore preparation installer-local and non-destructive. Extend `WpConfigReader` with a trusted credential parser, add small database services for connection testing and rollback dump writing, then wire their safe status into preflight, rollback manifest/config, and Bootstrap UI.

**Tech Stack:** PHP 7.4-compatible standalone PHP, PHPUnit 9.6, `mysqli` when available, JSON manifests, existing installer `config.php`.

---

## Spec

This plan implements:

`docs/superpowers/specs/2026-05-18-installer-db-preflight-rollback-dump-design.md`

## Scope

Included:

- Parse literal `wp-config.php` DB constants into trusted installer credential arrays.
- Keep current `readDatabaseConfig()` output secret-free.
- Add safe DB connection status service.
- Add rollback SQL dump writer for destination prefixed tables.
- Extend rollback preparation manifest/config with database rollback metadata.
- Extend preflight and Bootstrap UI with database connection/dump statuses.

Excluded:

- Manual credential entry.
- Database import.
- Table drops/truncates/renames/replaces.
- URL replacement.
- Rollback execution.
- Real MySQL integration test requirement.

## File Structure

- Modify `super-sheep-copy/installer/restore-engine/WpConfigReader.php`
  - Add trusted credential parsing and host splitting.
- Modify `super-sheep-copy/tests/Unit/WpConfigReaderTest.php`
  - Cover secret parsing and secret-free summary output.
- Create `super-sheep-copy/installer/restore-engine/DatabaseConnectionTester.php`
  - Return safe connection status for credentials.
- Create `super-sheep-copy/tests/Unit/DatabaseConnectionTesterTest.php`
  - Cover incomplete credentials and unavailable `mysqli` path.
- Create `super-sheep-copy/installer/restore-engine/RollbackDatabaseDumper.php`
  - Dump prefixed table SQL to rollback artifact.
- Create `super-sheep-copy/tests/Unit/RollbackDatabaseDumperTest.php`
  - Cover SQL formatting/helpers and skipped empty prefix path.
- Modify `super-sheep-copy/installer/restore-engine/RollbackManifestBuilder.php`
  - Add optional `database` section.
- Modify `super-sheep-copy/tests/Unit/RollbackManifestBuilderTest.php`
  - Cover database section and secret exclusion.
- Modify `super-sheep-copy/installer/restore-engine/RollbackPreparationManager.php`
  - Coordinate DB status/dump and config metadata.
- Modify `super-sheep-copy/tests/Unit/RollbackPreparationManagerTest.php`
  - Cover DB dump success and skip warning metadata.
- Modify `super-sheep-copy/installer/restore-engine/PreflightChecker.php`
  - Add safe DB connection preflight row.
- Modify `super-sheep-copy/tests/Unit/PreflightCheckerTest.php`
  - Cover database connection status row.
- Modify `super-sheep-copy/installer/restore-engine/Bootstrap.php`
  - Require new classes and show rollback DB dump status.
- Modify `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`
  - Cover UI text and no password leakage.

---

### Task 1: Trusted DB Credential Parser

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/WpConfigReader.php`
- Test: `super-sheep-copy/tests/Unit/WpConfigReaderTest.php`

- [x] **Step 1: Write failing credential tests**

Append tests to `WpConfigReaderTest`:

```php
public function testReadsTrustedDatabaseCredentialsWithoutChangingSecretFreeSummary(): void
{
    file_put_contents($this->root . '/wp-config.php', "<?php\n"
        . "define('DB_NAME', 'wordpress');\n"
        . "define('DB_USER', 'dbuser');\n"
        . "define('DB_PASSWORD', 'secret');\n"
        . "define('DB_HOST', 'localhost:3307');\n"
        . "\$table_prefix = 'wp_';\n");

    $reader = new \SuperSheepCopyInstaller\WpConfigReader();
    $credentials = $reader->readDatabaseCredentials($this->root);
    $summary = $reader->readDatabaseConfig($this->root);

    self::assertTrue($credentials['readable']);
    self::assertTrue($credentials['complete']);
    self::assertSame('wordpress', $credentials['name']);
    self::assertSame('dbuser', $credentials['user']);
    self::assertSame('secret', $credentials['password']);
    self::assertSame('localhost', $credentials['host']);
    self::assertSame(3307, $credentials['port']);
    self::assertSame('', $credentials['socket']);
    self::assertSame('wp_', $credentials['table_prefix']);

    self::assertArrayNotHasKey('password', $summary);
    self::assertStringNotContainsString('secret', json_encode($summary) ?: '');
}

public function testSplitsSocketLikeDatabaseHost(): void
{
    file_put_contents($this->root . '/wp-config.php', "<?php\n"
        . "define('DB_NAME', 'wordpress');\n"
        . "define('DB_USER', 'dbuser');\n"
        . "define('DB_PASSWORD', 'secret');\n"
        . "define('DB_HOST', 'localhost:/tmp/mysql.sock');\n"
        . "\$table_prefix = 'wp_';\n");

    $credentials = (new \SuperSheepCopyInstaller\WpConfigReader())->readDatabaseCredentials($this->root);

    self::assertSame('localhost', $credentials['host']);
    self::assertSame(0, $credentials['port']);
    self::assertSame('/tmp/mysql.sock', $credentials['socket']);
}

public function testUnreadableCredentialsAreIncompleteAndSecretFree(): void
{
    $credentials = (new \SuperSheepCopyInstaller\WpConfigReader())->readDatabaseCredentials($this->root);

    self::assertFalse($credentials['readable']);
    self::assertFalse($credentials['complete']);
    self::assertSame('', $credentials['password']);
    self::assertStringNotContainsString('secret', json_encode($credentials) ?: '');
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/WpConfigReaderTest.php
```

Expected: FAIL because `readDatabaseCredentials()` does not exist.

- [x] **Step 3: Add credential parser**

Modify `WpConfigReader.php`:

```php
/**
 * @return array{readable:bool,complete:bool,name:string,user:string,password:string,host:string,port:int,socket:string,table_prefix:string}
 */
public function readDatabaseCredentials(string $wordpress_root): array
{
    $defaults = array(
        'readable' => false,
        'complete' => false,
        'name' => '',
        'user' => '',
        'password' => '',
        'host' => '',
        'port' => 0,
        'socket' => '',
        'table_prefix' => '',
    );

    $path = rtrim($wordpress_root, '/\\') . '/wp-config.php';
    if (!is_readable($path)) {
        return $defaults;
    }

    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        return $defaults;
    }

    $host_parts = $this->splitHost($this->definedValue($contents, 'DB_HOST'));
    $credentials = array(
        'readable' => true,
        'complete' => false,
        'name' => $this->definedValue($contents, 'DB_NAME'),
        'user' => $this->definedValue($contents, 'DB_USER'),
        'password' => $this->definedValue($contents, 'DB_PASSWORD'),
        'host' => $host_parts['host'],
        'port' => $host_parts['port'],
        'socket' => $host_parts['socket'],
        'table_prefix' => $this->tablePrefix($contents),
    );
    $credentials['complete'] = $credentials['name'] !== ''
        && $credentials['user'] !== ''
        && $credentials['host'] !== '';

    return $credentials;
}

private function definedValue(string $contents, string $name): string
{
    if (preg_match('/define\\s*\\(\\s*["\']' . preg_quote($name, '/') . '["\']\\s*,\\s*["\']([^"\']*)["\']\\s*\\)/', $contents, $match) !== 1) {
        return '';
    }

    return (string) $match[1];
}

private function tablePrefix(string $contents): string
{
    if (preg_match('/\\$table_prefix\\s*=\\s*["\']([^"\']*)["\']\\s*;/', $contents, $match) !== 1) {
        return '';
    }

    return (string) $match[1];
}

/**
 * @return array{host:string,port:int,socket:string}
 */
private function splitHost(string $host): array
{
    if ($host === '') {
        return array('host' => '', 'port' => 0, 'socket' => '');
    }

    if (strpos($host, ':/') !== false) {
        [$hostname, $socket] = explode(':', $host, 2);

        return array('host' => $hostname, 'port' => 0, 'socket' => $socket);
    }

    if (preg_match('/^([^:]+):(\\d+)$/', $host, $match) === 1) {
        return array('host' => (string) $match[1], 'port' => (int) $match[2], 'socket' => '');
    }

    return array('host' => $host, 'port' => 0, 'socket' => '');
}
```

Then update existing `readDatabaseConfig()` to reuse `definedValue()` and `tablePrefix()` instead of duplicating regex. Keep returned keys unchanged and do not include `password`.

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/WpConfigReaderTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/WpConfigReader.php super-sheep-copy/tests/Unit/WpConfigReaderTest.php
git commit -m "feat: parse installer database credentials"
```

Expected: commit succeeds.

---

### Task 2: Database Connection Tester

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DatabaseConnectionTester.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseConnectionTesterTest.php`

- [x] **Step 1: Write failing tests**

Create `DatabaseConnectionTesterTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';

final class DatabaseConnectionTesterTest extends TestCase
{
    public function testReportsIncompleteCredentialsWithoutSecrets(): void
    {
        $result = (new \SuperSheepCopyInstaller\DatabaseConnectionTester())->test(array(
            'complete' => false,
            'name' => '',
            'user' => '',
            'password' => 'secret',
            'host' => '',
            'port' => 0,
            'socket' => '',
        ));

        self::assertFalse($result['connected']);
        self::assertSame('warning', $result['status']);
        self::assertSame('Database credentials are incomplete.', $result['message']);
        self::assertStringNotContainsString('secret', json_encode($result) ?: '');
    }

    public function testResultNeverIncludesPasswordWhenConnectionCannotBeMade(): void
    {
        $result = (new \SuperSheepCopyInstaller\DatabaseConnectionTester())->test(array(
            'complete' => true,
            'name' => 'missing_db',
            'user' => 'missing_user',
            'password' => 'secret',
            'host' => '127.0.0.1',
            'port' => 65000,
            'socket' => '',
        ));

        self::assertFalse($result['connected']);
        self::assertContains($result['status'], array('warning', 'error'));
        self::assertSame('missing_db', $result['database']);
        self::assertSame('127.0.0.1', $result['host']);
        self::assertStringNotContainsString('secret', json_encode($result) ?: '');
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseConnectionTesterTest.php
```

Expected: FAIL because `DatabaseConnectionTester.php` is missing.

- [x] **Step 3: Add connection tester**

Create `DatabaseConnectionTester.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class DatabaseConnectionTester
{
    /**
     * @param array<string,mixed> $credentials
     * @return array{connected:bool,status:string,message:string,database:string,host:string}
     */
    public function test(array $credentials): array
    {
        $database = isset($credentials['name']) ? (string) $credentials['name'] : '';
        $host = isset($credentials['host']) ? (string) $credentials['host'] : '';

        if (empty($credentials['complete'])) {
            return $this->result(false, 'warning', 'Database credentials are incomplete.', $database, $host);
        }

        if (!class_exists('\\mysqli')) {
            return $this->result(false, 'warning', 'The mysqli extension is not available.', $database, $host);
        }

        \mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new \mysqli(
            $host,
            isset($credentials['user']) ? (string) $credentials['user'] : '',
            isset($credentials['password']) ? (string) $credentials['password'] : '',
            $database,
            isset($credentials['port']) ? (int) $credentials['port'] : 0,
            isset($credentials['socket']) ? (string) $credentials['socket'] : ''
        );

        if ($mysqli->connect_errno !== 0) {
            return $this->result(false, 'error', 'Database connection failed.', $database, $host);
        }

        $mysqli->close();

        return $this->result(true, 'ok', 'Connected', $database, $host);
    }

    /**
     * @return array{connected:bool,status:string,message:string,database:string,host:string}
     */
    private function result(bool $connected, string $status, string $message, string $database, string $host): array
    {
        return array(
            'connected' => $connected,
            'status' => $status,
            'message' => $message,
            'database' => $database,
            'host' => $host,
        );
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseConnectionTesterTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/DatabaseConnectionTester.php super-sheep-copy/tests/Unit/DatabaseConnectionTesterTest.php
git commit -m "feat: test installer database connection"
```

Expected: commit succeeds.

---

### Task 3: Rollback Database Dumper

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/RollbackDatabaseDumper.php`
- Test: `super-sheep-copy/tests/Unit/RollbackDatabaseDumperTest.php`

- [x] **Step 1: Write failing tests**

Create `RollbackDatabaseDumperTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackDatabaseDumper.php';

final class RollbackDatabaseDumperTest extends TestCase
{
    private string $rollback;

    protected function setUp(): void
    {
        $this->rollback = sys_get_temp_dir() . '/ssc-rollback-db-' . bin2hex(random_bytes(4));
        mkdir($this->rollback, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rollback);
    }

    public function testSkipsDumpWhenPrefixIsEmpty(): void
    {
        $result = (new \SuperSheepCopyInstaller\RollbackDatabaseDumper())->dump(array('table_prefix' => ''), $this->rollback);

        self::assertFalse($result['included']);
        self::assertSame('', $result['dump_path']);
        self::assertSame(0, $result['table_count']);
        self::assertSame(array('Database table prefix is empty; database rollback dump skipped.'), $result['warnings']);
    }

    public function testFormatsSqlValueLiterals(): void
    {
        $dumper = new \SuperSheepCopyInstaller\RollbackDatabaseDumper();

        self::assertSame('NULL', $dumper->formatValueForTest(null));
        self::assertSame("'simple'", $dumper->formatValueForTest('simple'));
        self::assertSame("'Bob\\'s'", $dumper->formatValueForTest("Bob's"));
        self::assertSame("'line\\nfeed'", $dumper->formatValueForTest("line\nfeed"));
    }

    public function testQuotesIdentifiers(): void
    {
        $dumper = new \SuperSheepCopyInstaller\RollbackDatabaseDumper();

        self::assertSame('`wp_posts`', $dumper->quoteIdentifierForTest('wp_posts'));
        self::assertSame('`odd``name`', $dumper->quoteIdentifierForTest('odd`name'));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $item) {
            $child = $path . '/' . $item;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RollbackDatabaseDumperTest.php
```

Expected: FAIL because `RollbackDatabaseDumper.php` is missing.

- [x] **Step 3: Add database dumper**

Create `RollbackDatabaseDumper.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class RollbackDatabaseDumper
{
    /**
     * @param array<string,mixed> $credentials
     * @return array{included:bool,dump_path:string,table_count:int,warnings:list<string>}
     */
    public function dump(array $credentials, string $rollback_directory): array
    {
        $prefix = isset($credentials['table_prefix']) ? (string) $credentials['table_prefix'] : '';
        if ($prefix === '') {
            return $this->result(false, '', 0, array('Database table prefix is empty; database rollback dump skipped.'));
        }

        if (!class_exists('\\mysqli')) {
            return $this->result(false, '', 0, array('The mysqli extension is not available; database rollback dump skipped.'));
        }

        \mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new \mysqli(
            isset($credentials['host']) ? (string) $credentials['host'] : '',
            isset($credentials['user']) ? (string) $credentials['user'] : '',
            isset($credentials['password']) ? (string) $credentials['password'] : '',
            isset($credentials['name']) ? (string) $credentials['name'] : '',
            isset($credentials['port']) ? (int) $credentials['port'] : 0,
            isset($credentials['socket']) ? (string) $credentials['socket'] : ''
        );

        if ($mysqli->connect_errno !== 0) {
            return $this->result(false, '', 0, array('Database connection failed; database rollback dump skipped.'));
        }

        $target_dir = rtrim($rollback_directory, '/\\') . '/database';
        if (!is_dir($target_dir) && !mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
            $mysqli->close();
            return $this->result(false, '', 0, array('Unable to create rollback database directory.'));
        }

        $relative_path = 'database/destination.sql';
        $target = rtrim($rollback_directory, '/\\') . '/' . $relative_path;
        $sql = $this->buildDump($mysqli, isset($credentials['name']) ? (string) $credentials['name'] : '', $prefix);
        $mysqli->close();

        if (file_put_contents($target, $sql['contents']) === false) {
            return $this->result(false, '', 0, array('Unable to write rollback database dump.'));
        }

        return $this->result(true, $relative_path, $sql['table_count'], array());
    }

    public function formatValueForTest($value): string
    {
        return $this->formatValue($value, null);
    }

    public function quoteIdentifierForTest(string $identifier): string
    {
        return $this->quoteIdentifier($identifier);
    }

    /**
     * @return array{contents:string,table_count:int}
     */
    private function buildDump(\mysqli $mysqli, string $database, string $prefix): array
    {
        $tables = $this->tables($mysqli, $prefix);
        $lines = array(
            '-- Super Sheep Copy destination database rollback dump',
            '-- Created at: ' . gmdate('c'),
            '-- Database: ' . $database,
            '',
        );

        foreach ($tables as $table) {
            $create_sql = (string) $mysqli->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($table))->fetch_assoc()['Create Table'];
            $lines[] = 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table) . ';';
            $lines[] = $create_sql . ';';

            $rows = $mysqli->query('SELECT * FROM ' . $this->quoteIdentifier($table));
            while ($row = $rows->fetch_assoc()) {
                $columns = array();
                $values = array();
                foreach ($row as $column => $value) {
                    $columns[] = $this->quoteIdentifier((string) $column);
                    $values[] = $this->formatValue($value, $mysqli);
                }
                $lines[] = 'INSERT INTO ' . $this->quoteIdentifier($table)
                    . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
            }
            $lines[] = '';
        }

        return array('contents' => implode("\n", $lines) . "\n", 'table_count' => count($tables));
    }

    /**
     * @return list<string>
     */
    private function tables(\mysqli $mysqli, string $prefix): array
    {
        $safe_prefix = $mysqli->real_escape_string($prefix);
        $result = $mysqli->query("SHOW TABLES LIKE '" . $safe_prefix . "%'");
        $tables = array();

        while ($row = $result->fetch_array()) {
            if (isset($row[0]) && is_string($row[0])) {
                $tables[] = $row[0];
            }
        }

        sort($tables);

        return $tables;
    }

    private function formatValue($value, ?\mysqli $mysqli): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $string = (string) $value;
        $escaped = $mysqli instanceof \mysqli
            ? $mysqli->real_escape_string($string)
            : str_replace(array('\\', "\n", "\r", "\0", "'"), array('\\\\', '\\n', '\\r', '\\0', "\\'"), $string);

        return "'" . $escaped . "'";
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param list<string> $warnings
     * @return array{included:bool,dump_path:string,table_count:int,warnings:list<string>}
     */
    private function result(bool $included, string $dump_path, int $table_count, array $warnings): array
    {
        return array(
            'included' => $included,
            'dump_path' => $dump_path,
            'table_count' => $table_count,
            'warnings' => $warnings,
        );
    }
}
```

If local PHP static checks object to chained `query()->fetch_assoc()`, split that call into `$create_result = $mysqli->query(...); $create_row = $create_result->fetch_assoc();`.

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RollbackDatabaseDumperTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/RollbackDatabaseDumper.php super-sheep-copy/tests/Unit/RollbackDatabaseDumperTest.php
git commit -m "feat: dump rollback database snapshot"
```

Expected: commit succeeds.

---

### Task 4: Rollback Manifest and Preparation Metadata

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/RollbackManifestBuilder.php`
- Modify: `super-sheep-copy/installer/restore-engine/RollbackPreparationManager.php`
- Test: `super-sheep-copy/tests/Unit/RollbackManifestBuilderTest.php`
- Test: `super-sheep-copy/tests/Unit/RollbackPreparationManagerTest.php`

- [x] **Step 1: Write failing manifest and manager tests**

In `RollbackManifestBuilderTest`, add:

```php
public function testIncludesDatabaseRollbackMetadata(): void
{
    $manifest = (new \SuperSheepCopyInstaller\RollbackManifestBuilder())->build(
        array('restore_job_id' => 'restore-123'),
        'https://destination.example',
        '/var/www/html',
        array(),
        array(),
        array('included' => true, 'dump_path' => 'database/destination.sql', 'table_count' => 2, 'warnings' => array())
    );

    self::assertTrue($manifest['database']['included']);
    self::assertSame('database/destination.sql', $manifest['database']['dump_path']);
    self::assertSame(2, $manifest['database']['table_count']);
}
```

In `RollbackPreparationManagerTest`, add fake helper classes at the bottom of the file:

Add these `require_once` lines near the existing installer requires:

```php
require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackDatabaseDumper.php';
```

```php
final class FakeDatabaseConnectionTester extends \SuperSheepCopyInstaller\DatabaseConnectionTester
{
    public function test(array $credentials): array
    {
        return array(
            'connected' => true,
            'status' => 'ok',
            'message' => 'Connected',
            'database' => isset($credentials['name']) ? (string) $credentials['name'] : '',
            'host' => isset($credentials['host']) ? (string) $credentials['host'] : '',
        );
    }
}

final class FakeRollbackDatabaseDumper extends \SuperSheepCopyInstaller\RollbackDatabaseDumper
{
    public function dump(array $credentials, string $rollback_directory): array
    {
        mkdir($rollback_directory . '/database', 0777, true);
        file_put_contents($rollback_directory . '/database/destination.sql', "-- dump\n");

        return array('included' => true, 'dump_path' => 'database/destination.sql', 'table_count' => 2, 'warnings' => array());
    }
}
```

Then add test:

```php
public function testAddsDatabaseDumpMetadataToManifestAndConfig(): void
{
    file_put_contents($this->root . '/wp-config.php', "<?php\n"
        . "define('DB_NAME', 'wordpress');\n"
        . "define('DB_USER', 'dbuser');\n"
        . "define('DB_PASSWORD', 'secret');\n"
        . "define('DB_HOST', 'localhost');\n"
        . "\$table_prefix = 'wp_';\n");
    $this->writeConfig($this->confirmedConfig());

    $result = $this->managerWithDatabaseDumper(new FakeRollbackDatabaseDumper())->prepare(
        $this->engine,
        require $this->engine . '/config.php',
        array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php')
    );

    $config = require $this->engine . '/config.php';
    $manifest = json_decode((string) file_get_contents($this->engine . '/' . $config['rollback_manifest']), true);

    self::assertTrue($result['database_included']);
    self::assertSame('rollback/' . $result['rollback_directory'] . '/database/destination.sql', $config['rollback_database_dump']);
    self::assertSame(2, $config['rollback_database_table_count']);
    self::assertTrue($manifest['database']['included']);
    self::assertSame(2, $manifest['database']['table_count']);
}
```

Add helper:

```php
private function managerWithDatabaseDumper(\SuperSheepCopyInstaller\RollbackDatabaseDumper $dumper): \SuperSheepCopyInstaller\RollbackPreparationManager
{
    return new \SuperSheepCopyInstaller\RollbackPreparationManager(
        new \SuperSheepCopyInstaller\RollbackFileCollector(),
        new \SuperSheepCopyInstaller\RollbackManifestBuilder(),
        new \SuperSheepCopyInstaller\DestinationDetector(),
        new \SuperSheepCopyInstaller\WpConfigReader(),
        new FakeDatabaseConnectionTester(),
        $dumper
    );
}
```

- [x] **Step 2: Run focused tests to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RollbackManifestBuilderTest.php tests/Unit/RollbackPreparationManagerTest.php
```

Expected: FAIL because builder signature and manager constructor/result do not support database metadata yet.

- [x] **Step 3: Extend manifest builder**

Change `RollbackManifestBuilder::build()` signature:

```php
public function build(array $config, string $destination_url, string $wordpress_root, array $files, array $warnings, array $database = array()): array
```

Add returned key:

```php
'database' => $database !== array() ? $database : array(
    'included' => false,
    'dump_path' => '',
    'table_count' => 0,
    'warnings' => array(),
),
```

- [x] **Step 4: Extend rollback preparation manager**

Update constructor to accept optional dependencies:

```php
private ?WpConfigReader $wp_config;
private ?DatabaseConnectionTester $database_tester;
private ?RollbackDatabaseDumper $database_dumper;

public function __construct(
    RollbackFileCollector $files,
    RollbackManifestBuilder $manifest,
    DestinationDetector $destination,
    ?WpConfigReader $wp_config = null,
    ?DatabaseConnectionTester $database_tester = null,
    ?RollbackDatabaseDumper $database_dumper = null
) {
    $this->files = $files;
    $this->manifest = $manifest;
    $this->destination = $destination;
    $this->wp_config = $wp_config;
    $this->database_tester = $database_tester;
    $this->database_dumper = $database_dumper;
}
```

Inside `prepare()`, before manifest build:

```php
$database = $this->prepareDatabaseRollback($wordpress_root, $rollback_dir);
$warnings = array_merge($collection['warnings'], $database['warnings']);
```

Pass `$database` and `$warnings` into builder:

```php
$manifest = $this->manifest->build(
    $config,
    $this->destination->detect($server),
    $wordpress_root,
    $collection['files'],
    $warnings,
    $database
);
```

Add config fields after manifest write:

```php
$config['rollback_database_dump'] = $database['included']
    ? 'rollback/' . $basename . '/' . $database['dump_path']
    : '';
$config['rollback_database_table_count'] = $database['table_count'];
if ($database['included']) {
    $config['rollback_database_dumped_at'] = gmdate('c');
}
```

Return `database_included` by extending result shape:

```php
return array(
    'prepared' => $prepared,
    'rollback_directory' => $directory,
    'file_count' => $file_count,
    'database_included' => $database_included,
    'warnings' => $warnings,
);
```

Add helper:

```php
/**
 * @return array{included:bool,dump_path:string,table_count:int,warnings:list<string>}
 */
private function prepareDatabaseRollback(string $wordpress_root, string $rollback_dir): array
{
    if (!$this->wp_config instanceof WpConfigReader || !$this->database_tester instanceof DatabaseConnectionTester || !$this->database_dumper instanceof RollbackDatabaseDumper) {
        return array('included' => false, 'dump_path' => '', 'table_count' => 0, 'warnings' => array('Database rollback dump dependencies are unavailable.'));
    }

    $credentials = $this->wp_config->readDatabaseCredentials($wordpress_root);
    if (empty($credentials['complete'])) {
        return array('included' => false, 'dump_path' => '', 'table_count' => 0, 'warnings' => array('Database credentials are incomplete.'));
    }

    $connection = $this->database_tester->test($credentials);
    if (empty($connection['connected'])) {
        return array('included' => false, 'dump_path' => '', 'table_count' => 0, 'warnings' => array($connection['message']));
    }

    return $this->database_dumper->dump($credentials, $rollback_dir);
}
```

- [x] **Step 5: Run focused tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RollbackManifestBuilderTest.php tests/Unit/RollbackPreparationManagerTest.php
```

Expected: PASS.

- [x] **Step 6: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/RollbackManifestBuilder.php super-sheep-copy/installer/restore-engine/RollbackPreparationManager.php super-sheep-copy/tests/Unit/RollbackManifestBuilderTest.php super-sheep-copy/tests/Unit/RollbackPreparationManagerTest.php
git commit -m "feat: attach rollback database metadata"
```

Expected: commit succeeds.

---

### Task 5: Preflight and Bootstrap DB Status

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/PreflightChecker.php`
- Modify: `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Test: `super-sheep-copy/tests/Unit/PreflightCheckerTest.php`
- Test: `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

- [x] **Step 1: Write failing UI/preflight tests**

In `PreflightCheckerTest`, add a test that writes `wp-config.php` with complete credentials and asserts a check with key `database_connection` exists. It should accept `ok`, `warning`, or `error` because CI may not have MySQL, but it must not contain the password.

Add this require before `PreflightChecker.php`:

```php
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';
```

```php
public function testReportsDatabaseConnectionWithoutSecrets(): void
{
    $checks = $this->checker()->run(
        array('staged_archive_path' => $this->validArchive()),
        array('HTTP_HOST' => 'example.com', 'SCRIPT_NAME' => '/installer.php'),
        $this->engine
    );

    $database_check = $this->check($checks, 'database_connection');

    self::assertSame('database_connection', $database_check['key']);
    self::assertContains($database_check['status'], array('ok', 'warning', 'error'));
    self::assertStringNotContainsString('secret', json_encode($database_check) ?: '');
}
```

Add helper:

```php
/**
 * @param array<int,array{key:string,label:string,status:string,value:string,message:string}> $checks
 * @return array{key:string,label:string,status:string,value:string,message:string}
 */
private function check(array $checks, string $key): array
{
    foreach ($checks as $check) {
        if ($check['key'] === $key) {
            return $check;
        }
    }

    self::fail('Missing preflight check: ' . $key);
}
```

In `InstallerBootstrapTest`, update the rollback POST test:

```php
self::assertStringContainsString('Database rollback', $html);
self::assertStringNotContainsString('secret', $html);
```

- [x] **Step 2: Run focused tests to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/PreflightCheckerTest.php tests/Unit/InstallerBootstrapTest.php
```

Expected: FAIL because `database_connection` check and database rollback status UI are missing.

- [x] **Step 3: Wire preflight DB status**

In `PreflightChecker.php`, add property and constructor dependency:

```php
private DatabaseConnectionTester $database_tester;
```

Constructor:

```php
public function __construct(EnvironmentChecker $environment, DestinationDetector $destination, WpConfigReader $wp_config, ArchiveValidator $archive_validator, ?DatabaseConnectionTester $database_tester = null)
{
    $this->environment = $environment;
    $this->destination = $destination;
    $this->wp_config = $wp_config;
    $this->archive_validator = $archive_validator;
    $this->database_tester = $database_tester ?: new DatabaseConnectionTester();
}
```

After database credentials detected check, add:

```php
$credentials = $this->wp_config->readDatabaseCredentials($wordpress_root);
$connection = $this->database_tester->test($credentials);
$checks[] = $this->check(
    'database_connection',
    'Database connection',
    $connection['status'],
    $connection['connected'] ? 'Connected' : 'Unavailable',
    $connection['message']
);
```

- [x] **Step 4: Wire Bootstrap dependencies and UI**

In `Bootstrap.php`, add requires:

```php
require_once __DIR__ . '/DatabaseConnectionTester.php';
require_once __DIR__ . '/RollbackDatabaseDumper.php';
```

Instantiate preflight with tester:

```php
$database_tester = new DatabaseConnectionTester();
$preflight = new PreflightChecker(new EnvironmentChecker(), new DestinationDetector(), new WpConfigReader(), $archive_validator, $database_tester);
```

Instantiate rollback manager with DB dependencies:

```php
$rollback = new RollbackPreparationManager(
    new RollbackFileCollector(),
    new RollbackManifestBuilder(),
    new DestinationDetector(),
    new WpConfigReader(),
    $database_tester,
    new RollbackDatabaseDumper()
);
```

In rollback prepared UI, add:

```php
$database_dump = isset($config['rollback_database_dump']) ? (string) $config['rollback_database_dump'] : '';
$database_count = isset($config['rollback_database_table_count']) ? (string) $config['rollback_database_table_count'] : '0';
if ($database_dump !== '') {
    echo '<div class="status ok"><strong>Database rollback:</strong> ' . htmlspecialchars($database_count, ENT_QUOTES, 'UTF-8') . ' tables dumped.</div>';
} else {
    echo '<div class="status warning"><strong>Database rollback:</strong> Database dump was skipped. Restore execution remains unavailable.</div>';
}
```

- [x] **Step 5: Run focused tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/PreflightCheckerTest.php tests/Unit/InstallerBootstrapTest.php
```

Expected: PASS.

- [x] **Step 6: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/PreflightChecker.php super-sheep-copy/installer/restore-engine/Bootstrap.php super-sheep-copy/tests/Unit/PreflightCheckerTest.php super-sheep-copy/tests/Unit/InstallerBootstrapTest.php
git commit -m "feat: show installer database rollback status"
```

Expected: commit succeeds.

---

### Task 6: Final Verification

**Files:**
- Verify all files changed in this plan.

- [x] **Step 1: Run lint**

Run:

```bash
cd super-sheep-copy && composer run lint
```

Expected: every PHP file reports `No syntax errors detected`.

- [x] **Step 2: Run full PHPUnit suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: all tests pass.

- [x] **Step 3: Confirm no destructive database SQL was added**

Run:

```bash
rg -n "DROP DATABASE|DROP TABLE|TRUNCATE|RENAME TABLE|ALTER TABLE|DELETE FROM|UPDATE .* SET" super-sheep-copy/installer/restore-engine
```

Expected: only rollback dump text may include `DROP TABLE IF EXISTS` inside `RollbackDatabaseDumper.php`; no execution path should run destructive SQL against the destination database.

- [x] **Step 4: Confirm secrets are not rendered**

Run:

```bash
rg -n "DB_PASSWORD|password|secret" super-sheep-copy/installer/restore-engine super-sheep-copy/tests/Unit
```

Expected: DB password appears only in credential parsing/tests and never in rendered Bootstrap output or manifest fields.

- [x] **Step 5: Check git status**

Run:

```bash
git status --short
```

Expected: clean after committing plan checklist updates.

- [x] **Step 6: Commit checklist update**

Run:

```bash
git add docs/superpowers/plans/2026-05-18-installer-db-preflight-rollback-dump.md
git commit -m "docs: mark installer db rollback dump complete"
```

Expected: commit succeeds after Task 6 checkboxes are marked complete.

---

## Self-Review

- Spec coverage: Plan covers credential parsing, connection status, rollback dump writer, manifest/config metadata, preflight/UI status, security scans, lint, and full tests.
- Scope exclusions remain excluded: no manual credentials, no database import, no table replacement, no URL replacement, no rollback execution, no real MySQL dependency in PHPUnit.
- Type consistency: Credential arrays use `readable`, `complete`, `name`, `user`, `password`, `host`, `port`, `socket`, `table_prefix`; dump results use `included`, `dump_path`, `table_count`, `warnings`; rollback manager returns `database_included`.
- Risk note: `RollbackDatabaseDumper` writes `DROP TABLE IF EXISTS` statements into the rollback dump file, but must not execute destructive SQL.
