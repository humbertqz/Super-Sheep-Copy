# Installer Staged Table Swap and URL Replacement Planning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Swap staged database tables into destination table names and persist a post-import URL replacement plan for a later serialized-safe replacement executor.

**Architecture:** Add installer-only helpers for table existence checks, URL replacement plan construction, and guarded table renames. The swap manager uses existing `WpConfigReader` and `DatabaseConnectionTester`, refuses to run without rollback dump plus staged import metadata, records URL replacement planning metadata, renames tables, and locks the installer after success.

**Tech Stack:** PHP 7.4-compatible standalone PHP, PHPUnit 9.6, mysqli, existing installer config.

---

## Spec

This plan implements:

`docs/superpowers/specs/2026-05-19-installer-staged-table-swap-url-replacement-planning-design.md`

## Scope

Included:

- Build and persist URL replacement plan metadata.
- Verify staged tables before swap.
- Rename destination tables to backup names when present.
- Rename staging tables into destination table names.
- Record swap metadata and lock the installer after success.
- Add Bootstrap UI/action for table swap.

Excluded:

- Database value URL replacement.
- File restore.
- Rollback execution.
- Maintenance mode.
- Installer deletion.
- Real MySQL integration tests.

## File Structure

- Create `super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementPlanBuilder.php`
- Create `super-sheep-copy/tests/Unit/DatabaseUrlReplacementPlanBuilderTest.php`
- Create `super-sheep-copy/installer/restore-engine/DatabaseTableInspector.php`
- Create `super-sheep-copy/tests/Unit/DatabaseTableInspectorTest.php`
- Create `super-sheep-copy/installer/restore-engine/DatabaseTableSwapExecutor.php`
- Create `super-sheep-copy/installer/restore-engine/DatabaseTableSwapManager.php`
- Create `super-sheep-copy/tests/Unit/DatabaseTableSwapManagerTest.php`
- Modify `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Modify `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

---

### Task 1: URL Replacement Plan Builder

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementPlanBuilder.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseUrlReplacementPlanBuilderTest.php`

- [x] **Step 1: Write failing tests**

Create `DatabaseUrlReplacementPlanBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseUrlReplacementPlanBuilder.php';

final class DatabaseUrlReplacementPlanBuilderTest extends TestCase
{
    public function testBuildsUniqueSourceVariantsForDatabaseTables(): void
    {
        $plan = (new \SuperSheepCopyInstaller\DatabaseUrlReplacementPlanBuilder())->build(
            'https://www.source.example/site/',
            'http://source.example/site',
            'https://destination.example/new-site/',
            array('wp_posts' => 'ssc_tmp_abcd_wp_posts', 'wp_options' => 'ssc_tmp_abcd_wp_options'),
            '2026-05-19T12:00:00+00:00'
        );

        self::assertSame('planned', $plan['status']);
        self::assertSame('https://destination.example/new-site', $plan['destination_url']);
        self::assertSame(2, $plan['table_count']);
        self::assertSame(array('wp_posts', 'wp_options'), $plan['tables']);
        self::assertSame('2026-05-19T12:00:00+00:00', $plan['planned_at']);
        self::assertContains('https://www.source.example/site', $plan['source_urls']);
        self::assertContains('http://www.source.example/site', $plan['source_urls']);
        self::assertContains('https://source.example/site', $plan['source_urls']);
        self::assertContains('http://source.example/site', $plan['source_urls']);
        self::assertSame(count($plan['source_urls']), count(array_unique($plan['source_urls'])));
    }

    public function testRejectsEmptyDestinationUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Destination URL is required for URL replacement planning.');

        (new \SuperSheepCopyInstaller\DatabaseUrlReplacementPlanBuilder())->build(
            'https://source.example',
            '',
            '',
            array('wp_posts' => 'ssc_tmp_abcd_wp_posts'),
            '2026-05-19T12:00:00+00:00'
        );
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseUrlReplacementPlanBuilderTest.php
```

Expected: FAIL because `DatabaseUrlReplacementPlanBuilder.php` is missing.

- [x] **Step 3: Add plan builder**

Create `DatabaseUrlReplacementPlanBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use InvalidArgumentException;

final class DatabaseUrlReplacementPlanBuilder
{
    /**
     * @param array<string,string> $table_map
     * @return array{status:string,source_urls:list<string>,destination_url:string,table_count:int,tables:list<string>,planned_at:string}
     */
    public function build(string $source_site_url, string $source_home_url, string $destination_url, array $table_map, string $planned_at): array
    {
        $destination_url = $this->normalizeUrl($destination_url);
        if ($destination_url === '') {
            throw new InvalidArgumentException('Destination URL is required for URL replacement planning.');
        }

        $source_urls = array();
        foreach (array($source_site_url, $source_home_url) as $source_url) {
            foreach ($this->variants($this->normalizeUrl($source_url)) as $variant) {
                if ($variant !== '' && !in_array($variant, $source_urls, true)) {
                    $source_urls[] = $variant;
                }
            }
        }

        return array(
            'status' => 'planned',
            'source_urls' => $source_urls,
            'destination_url' => $destination_url,
            'table_count' => count($table_map),
            'tables' => array_keys($table_map),
            'planned_at' => $planned_at,
        );
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /**
     * @return list<string>
     */
    private function variants(string $url): array
    {
        if ($url === '') {
            return array();
        }

        $variants = array($url);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $variants;
        }

        foreach (array('http', 'https') as $scheme) {
            $rebuilt = $this->buildUrl($parts, $scheme, (string) $parts['host']);
            if (!in_array($rebuilt, $variants, true)) {
                $variants[] = $rebuilt;
            }

            $host = (string) $parts['host'];
            $alt_host = strpos($host, 'www.') === 0 ? substr($host, 4) : 'www.' . $host;
            $rebuilt = $this->buildUrl($parts, $scheme, $alt_host);
            if (!in_array($rebuilt, $variants, true)) {
                $variants[] = $rebuilt;
            }
        }

        return $variants;
    }

    /**
     * @param array<string,mixed> $parts
     */
    private function buildUrl(array $parts, string $scheme, string $host): string
    {
        $url = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $url .= ':' . (string) $parts['port'];
        }
        if (isset($parts['path'])) {
            $url .= rtrim((string) $parts['path'], '/');
        }

        return $url;
    }
}
```

- [x] **Step 4: Run focused test to verify GREEN**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseUrlReplacementPlanBuilderTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementPlanBuilder.php super-sheep-copy/tests/Unit/DatabaseUrlReplacementPlanBuilderTest.php
git commit -m "feat: plan database url replacement"
```

---

### Task 2: Database Table Inspector

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DatabaseTableInspector.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseTableInspectorTest.php`

- [x] **Step 1: Write failing tests**

Create `DatabaseTableInspectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTableInspector.php';

final class DatabaseTableInspectorTest extends TestCase
{
    public function testFindsMissingStagingTables(): void
    {
        $inspector = new FakeDatabaseTableInspector(array('ssc_tmp_abcd_wp_posts' => true, 'ssc_tmp_abcd_wp_options' => false));

        $result = $inspector->verifyTables(array('wp_posts' => 'ssc_tmp_abcd_wp_posts', 'wp_options' => 'ssc_tmp_abcd_wp_options'));

        self::assertFalse($result['valid']);
        self::assertSame(array('Missing staging table: ssc_tmp_abcd_wp_options'), $result['warnings']);
    }

    public function testAcceptsExistingStagingTables(): void
    {
        $inspector = new FakeDatabaseTableInspector(array('ssc_tmp_abcd_wp_posts' => true));

        $result = $inspector->verifyTables(array('wp_posts' => 'ssc_tmp_abcd_wp_posts'));

        self::assertTrue($result['valid']);
        self::assertSame(array(), $result['warnings']);
    }
}

final class FakeDatabaseTableInspector extends \SuperSheepCopyInstaller\DatabaseTableInspector
{
    /** @var array<string,bool> */
    private array $tables;

    /**
     * @param array<string,bool> $tables
     */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    protected function tableExists(string $table): bool
    {
        return isset($this->tables[$table]) && $this->tables[$table];
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseTableInspectorTest.php
```

Expected: FAIL because `DatabaseTableInspector.php` is missing.

- [x] **Step 3: Add table inspector**

Create `DatabaseTableInspector.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class DatabaseTableInspector
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
     * @param array<string,string> $table_map
     * @return array{valid:bool,warnings:list<string>}
     */
    public function verifyTables(array $table_map): array
    {
        $warnings = array();

        foreach ($table_map as $staging_table) {
            if (!$this->tableExists($staging_table)) {
                $warnings[] = 'Missing staging table: ' . $staging_table;
            }
        }

        return array(
            'valid' => $warnings === array(),
            'warnings' => $warnings,
        );
    }

    protected function tableExists(string $table): bool
    {
        if (!is_object($this->mysqli) || !method_exists($this->mysqli, 'query')) {
            return false;
        }

        $escaped = method_exists($this->mysqli, 'real_escape_string')
            ? $this->mysqli->real_escape_string($table)
            : addslashes($table);

        $result = $this->mysqli->query("SHOW TABLES LIKE '" . $escaped . "'");
        if (!is_object($result) || !method_exists($result, 'fetch_row')) {
            return false;
        }

        return $result->fetch_row() !== null;
    }
}
```

- [x] **Step 4: Run focused test to verify GREEN**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseTableInspectorTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add super-sheep-copy/installer/restore-engine/DatabaseTableInspector.php super-sheep-copy/tests/Unit/DatabaseTableInspectorTest.php
git commit -m "feat: verify staging tables before swap"
```

---

### Task 3: Database Table Swap Manager

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DatabaseTableSwapExecutor.php`
- Create: `super-sheep-copy/installer/restore-engine/DatabaseTableSwapManager.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseTableSwapManagerTest.php`

- [x] **Step 1: Write failing tests**

Create `DatabaseTableSwapManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTableInspector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseUrlReplacementPlanBuilder.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTableSwapExecutor.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTableSwapManager.php';

final class DatabaseTableSwapManagerTest extends TestCase
{
    private string $engine_dir;

    protected function setUp(): void
    {
        $this->engine_dir = sys_get_temp_dir() . '/ssc-swap-' . bin2hex(random_bytes(4));
        mkdir($this->engine_dir, 0777, true);
        mkdir(dirname($this->engine_dir) . '/wp-root', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->engine_dir . '/config.php')) {
            unlink($this->engine_dir . '/config.php');
        }
        if (is_dir($this->engine_dir)) {
            rmdir($this->engine_dir);
        }
    }

    public function testRejectsMissingStagedImport(): void
    {
        $manager = $this->manager(array());

        $result = $manager->swap($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
        ), array('HTTP_HOST' => 'destination.example', 'HTTPS' => 'on'));

        self::assertFalse($result['swapped']);
        self::assertSame(array('Database import must be staged before table swap.'), $result['warnings']);
    }

    public function testRecordsUrlPlanAndSwapMetadata(): void
    {
        file_put_contents($this->engine_dir . '/config.php', "<?php\n\nreturn array();\n");
        $manager = $this->manager(array('ssc_tmp_abcd_wp_posts' => true));

        $result = $manager->swap($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_import_staging_tables' => array('wp_posts' => 'ssc_tmp_abcd_wp_posts'),
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example',
        ), array('HTTP_HOST' => 'destination.example', 'HTTPS' => 'on'));

        self::assertTrue($result['swapped']);
        self::assertSame(1, $result['table_count']);
        self::assertSame(array('RENAME TABLE `wp_posts` TO `ssc_old_' . substr(hash('sha256', 'restore-123'), 0, 8) . '_wp_posts`, `ssc_tmp_abcd_wp_posts` TO `wp_posts`'), $result['sql']);

        $config = require $this->engine_dir . '/config.php';
        self::assertTrue($config['database_tables_swapped']);
        self::assertTrue($config['locked']);
        self::assertSame(1, $config['database_swap_table_count']);
        self::assertSame(array('wp_posts' => 'ssc_old_' . substr(hash('sha256', 'restore-123'), 0, 8) . '_wp_posts'), $config['database_swap_backup_tables']);
        self::assertSame('planned', $config['database_url_replacement_plan']['status']);
    }

    /**
     * @param array<string,bool> $existing_tables
     */
    private function manager(array $existing_tables): \SuperSheepCopyInstaller\DatabaseTableSwapManager
    {
        return new \SuperSheepCopyInstaller\DatabaseTableSwapManager(
            new FakeSwapWpConfigReader(),
            new FakeSwapConnectionTester(),
            new FakeSwapTableInspector($existing_tables),
            new \SuperSheepCopyInstaller\DatabaseUrlReplacementPlanBuilder(),
            new FakeSwapExecutor()
        );
    }
}

final class FakeSwapWpConfigReader extends \SuperSheepCopyInstaller\WpConfigReader
{
    public function readDatabaseCredentials(string $wordpress_root): array
    {
        return array('complete' => true, 'name' => 'db', 'user' => 'user', 'password' => 'secret', 'host' => 'localhost');
    }
}

final class FakeSwapConnectionTester extends \SuperSheepCopyInstaller\DatabaseConnectionTester
{
    public function test(array $credentials): array
    {
        return array('connected' => true, 'status' => 'ok', 'message' => '', 'database' => 'db', 'host' => 'localhost');
    }
}

final class FakeSwapTableInspector extends \SuperSheepCopyInstaller\DatabaseTableInspector
{
    /** @var array<string,bool> */
    private array $existing_tables;

    /**
     * @param array<string,bool> $existing_tables
     */
    public function __construct(array $existing_tables)
    {
        $this->existing_tables = $existing_tables;
    }

    protected function tableExists(string $table): bool
    {
        return isset($this->existing_tables[$table]) && $this->existing_tables[$table];
    }
}

final class FakeSwapExecutor
{
    /** @var list<string> */
    public array $sql = array();

    /**
     * @param array<string,mixed> $credentials
     * @param list<string> $sql
     */
    public function execute(array $credentials, array $sql): bool
    {
        unset($credentials);
        $this->sql = $sql;
        return true;
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseTableSwapManagerTest.php
```

Expected: FAIL because `DatabaseTableSwapManager.php` is missing.

- [x] **Step 3: Add swap executor and manager**

Create `DatabaseTableSwapExecutor.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class DatabaseTableSwapExecutor
{
    /**
     * @param array<string,mixed> $credentials
     * @param list<string> $sql
     */
    public function execute(array $credentials, array $sql): bool
    {
        if (!class_exists('\\mysqli')) {
            return false;
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
            return false;
        }

        foreach ($sql as $statement) {
            if (!$mysqli->query($statement)) {
                $mysqli->close();

                return false;
            }
        }

        $mysqli->close();

        return true;
    }
}
```

Create `DatabaseTableSwapManager.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class DatabaseTableSwapManager
{
    private WpConfigReader $wp_config;
    private DatabaseConnectionTester $connection_tester;
    private DatabaseTableInspector $table_inspector;
    private DatabaseUrlReplacementPlanBuilder $url_plan_builder;
    /** @var mixed */
    private $executor;

    /**
     * @param mixed $executor Object with execute(array $credentials, array $sql): bool.
     */
    public function __construct(
        WpConfigReader $wp_config,
        DatabaseConnectionTester $connection_tester,
        DatabaseTableInspector $table_inspector,
        DatabaseUrlReplacementPlanBuilder $url_plan_builder,
        $executor
    ) {
        $this->wp_config = $wp_config;
        $this->connection_tester = $connection_tester;
        $this->table_inspector = $table_inspector;
        $this->url_plan_builder = $url_plan_builder;
        $this->executor = $executor;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $server
     * @return array{swapped:bool,table_count:int,warnings:list<string>,sql:list<string>}
     */
    public function swap(string $engine_dir, array $config, array $server): array
    {
        if (empty($config['restore_confirmed'])) {
            return $this->result(false, 0, array('Restore is not confirmed.'), array());
        }
        if (empty($config['rollback_prepared'])) {
            return $this->result(false, 0, array('Rollback is not prepared.'), array());
        }
        if (empty($config['rollback_database_dump'])) {
            return $this->result(false, 0, array('Database table swap requires a database rollback dump.'), array());
        }
        if (empty($config['database_import_staged'])) {
            return $this->result(false, 0, array('Database import must be staged before table swap.'), array());
        }
        if (!empty($config['database_tables_swapped'])) {
            return $this->result(false, 0, array('Database tables are already swapped.'), array());
        }
        if (!empty($config['locked'])) {
            return $this->result(false, 0, array('Installer is locked.'), array());
        }

        $table_map = isset($config['database_import_staging_tables']) && is_array($config['database_import_staging_tables'])
            ? $this->stringMap($config['database_import_staging_tables'])
            : array();
        if ($table_map === array()) {
            return $this->result(false, 0, array('Staging table map is missing.'), array());
        }

        $credentials = $this->wp_config->readDatabaseCredentials(dirname(rtrim($engine_dir, '/\\')));
        if (empty($credentials['complete'])) {
            return $this->result(false, 0, array('Database credentials are incomplete.'), array());
        }

        $connection = $this->connection_tester->test($credentials);
        if (empty($connection['connected'])) {
            return $this->result(false, 0, array(isset($connection['message']) ? (string) $connection['message'] : 'Database connection failed.'), array());
        }

        $verification = $this->table_inspector->verifyTables($table_map, $credentials);
        if (empty($verification['valid'])) {
            return $this->result(false, 0, $this->stringList($verification['warnings']), array());
        }

        $planned_at = gmdate('c');
        $destination_url = $this->destinationUrl($server);
        $url_plan = $this->url_plan_builder->build(
            isset($config['source_site_url']) ? (string) $config['source_site_url'] : '',
            isset($config['source_home_url']) ? (string) $config['source_home_url'] : '',
            $destination_url,
            $table_map,
            $planned_at
        );

        $restore_job_id = isset($config['restore_job_id']) ? (string) $config['restore_job_id'] : 'restore';
        $backup_map = $this->backupMap(array_keys($table_map), $restore_job_id);
        $sql = $this->renameSql($table_map, $backup_map);

        if (!is_object($this->executor) || !method_exists($this->executor, 'execute') || !$this->executor->execute($credentials, $sql)) {
            return $this->result(false, 0, array('Database table swap failed.'), $sql);
        }

        $config['database_url_replacement_plan'] = $url_plan;
        $config['database_url_replacement_planned_at'] = $planned_at;
        $config['database_tables_swapped'] = true;
        $config['database_tables_swapped_at'] = gmdate('c');
        $config['database_swap_table_count'] = count($table_map);
        $config['database_swap_backup_tables'] = $backup_map;
        $config['locked'] = true;

        if (!$this->writeConfig(rtrim($engine_dir, '/\\'), $config)) {
            return $this->result(false, count($table_map), array('Unable to update installer config.'), $sql);
        }

        return $this->result(true, count($table_map), array(), $sql);
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    private function stringMap(array $values): array
    {
        $map = array();
        foreach ($values as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $map[$key] = (string) $value;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $tables
     * @return array<string,string>
     */
    private function backupMap(array $tables, string $restore_job_id): array
    {
        $hash = substr(hash('sha256', $restore_job_id), 0, 8);
        $backup_map = array();
        foreach ($tables as $table) {
            $backup_map[$table] = 'ssc_old_' . $hash . '_' . $this->sanitizeIdentifier($table);
        }

        return $backup_map;
    }

    /**
     * @param array<string,string> $table_map
     * @param array<string,string> $backup_map
     * @return list<string>
     */
    private function renameSql(array $table_map, array $backup_map): array
    {
        $parts = array();
        foreach ($table_map as $destination_table => $staging_table) {
            $parts[] = $this->quoteIdentifier($destination_table) . ' TO ' . $this->quoteIdentifier($backup_map[$destination_table]);
            $parts[] = $this->quoteIdentifier($staging_table) . ' TO ' . $this->quoteIdentifier($destination_table);
        }

        return array('RENAME TABLE ' . implode(', ', $parts));
    }

    private function sanitizeIdentifier(string $identifier): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '_', $identifier);
        return $sanitized === null ? '' : $sanitized;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<string,mixed> $server
     */
    private function destinationUrl(array $server): string
    {
        $scheme = !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off' ? 'https' : 'http';
        $host = isset($server['HTTP_HOST']) ? (string) $server['HTTP_HOST'] : 'localhost';

        return $scheme . '://' . $host;
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
            $strings[] = is_scalar($value) ? (string) $value : '';
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
     * @param list<string> $sql
     * @return array{swapped:bool,table_count:int,warnings:list<string>,sql:list<string>}
     */
    private function result(bool $swapped, int $table_count, array $warnings, array $sql): array
    {
        return array(
            'swapped' => $swapped,
            'table_count' => $table_count,
            'warnings' => $warnings,
            'sql' => $sql,
        );
    }
}
```

- [x] **Step 4: Run focused test to verify GREEN**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseTableSwapManagerTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add super-sheep-copy/installer/restore-engine/DatabaseTableSwapExecutor.php super-sheep-copy/installer/restore-engine/DatabaseTableSwapManager.php super-sheep-copy/tests/Unit/DatabaseTableSwapManagerTest.php
git commit -m "feat: swap staged database tables"
```

---

### Task 4: Bootstrap Table Swap UI

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Modify: `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

- [x] **Step 1: Add failing Bootstrap tests**

Add tests to `InstallerBootstrapTest.php`:

```php
public function testRendersTableSwapGateAfterStagedImport(): void
{
    $this->writeConfig(array(
        'token_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'staged_archive_path' => $this->archive,
        'restore_confirmed' => true,
        'rollback_prepared' => true,
        'rollback_database_dump' => 'rollback/db.sql',
        'database_import_staged' => true,
        'database_import_staging_tables' => array('wp_posts' => 'ssc_tmp_abcd_wp_posts'),
        'source_site_url' => 'https://source.example',
        'source_home_url' => 'https://source.example',
    ));

    $_GET['token'] = 'secret';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'destination.example';
    $_SERVER['HTTPS'] = 'on';

    ob_start();
    \SuperSheepCopyInstaller\Bootstrap::run();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Database Table Swap', $html);
    self::assertStringContainsString('Swap staged database tables into destination table names.', $html);
    self::assertStringContainsString('name="swap_database_tables"', $html);
    self::assertStringNotContainsString('secret', $html);
}

public function testRendersCompletedTableSwapStatus(): void
{
    $this->writeConfig(array(
        'token_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'staged_archive_path' => $this->archive,
        'restore_confirmed' => true,
        'rollback_prepared' => true,
        'rollback_database_dump' => 'rollback/db.sql',
        'database_import_staged' => true,
        'database_tables_swapped' => true,
        'database_swap_table_count' => 2,
        'database_url_replacement_plan' => array('status' => 'planned'),
        'locked' => true,
    ));

    $_GET['token'] = 'secret';
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    \SuperSheepCopyInstaller\Bootstrap::run();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Database tables swapped. 2 tables replaced.', $html);
    self::assertStringContainsString('URL replacement plan recorded.', $html);
}
```

- [x] **Step 2: Run focused Bootstrap tests to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: FAIL because Bootstrap has no table swap UI/action.

- [x] **Step 3: Wire Bootstrap**

Modify `Bootstrap.php`:

```php
require_once __DIR__ . '/DatabaseTableInspector.php';
require_once __DIR__ . '/DatabaseUrlReplacementPlanBuilder.php';
require_once __DIR__ . '/DatabaseTableSwapManager.php';
```

Add POST handling after database import handling:

```php
$table_swap_message = '';
if (self::requestMethod() === 'POST' && isset($_POST['swap_database_tables'])) {
    $manager = new DatabaseTableSwapManager(
        $wp_config,
        $database_tester,
        new DatabaseTableInspector(),
        new DatabaseUrlReplacementPlanBuilder(),
        new DatabaseTableSwapExecutor()
    );
    $swap_result = $manager->swap($engine_dir, $config, $_SERVER);
    if ($swap_result['swapped']) {
        $config = self::loadConfig($engine_dir);
        $table_swap_message = 'Database tables swapped.';
    } else {
        $table_swap_message = isset($swap_result['warnings'][0]) ? $swap_result['warnings'][0] : 'Database table swap failed.';
    }
}
```

Add UI after Database Import:

```php
echo '<h2>Database Table Swap</h2>';
if ($table_swap_message !== '') {
    echo '<div class="status ' . (!empty($config['database_tables_swapped']) ? 'ok' : 'warning') . '">' . htmlspecialchars($table_swap_message, ENT_QUOTES, 'UTF-8') . '</div>';
}
if (empty($config['restore_confirmed'])) {
    echo '<div class="status warning">Database table swap requires restore confirmation.</div>';
} elseif (empty($config['rollback_prepared'])) {
    echo '<div class="status warning">Database table swap requires rollback preparation.</div>';
} elseif (empty($config['rollback_database_dump'])) {
    echo '<div class="status warning">Database table swap requires database rollback dump.</div>';
} elseif (empty($config['database_import_staged'])) {
    echo '<div class="status warning">Database table swap requires staged database import.</div>';
} elseif (!empty($config['database_tables_swapped'])) {
    echo '<div class="status ok">Database tables swapped. '
        . htmlspecialchars((string) ($config['database_swap_table_count'] ?? 0), ENT_QUOTES, 'UTF-8')
        . ' tables replaced.</div>';
    if (!empty($config['database_url_replacement_plan'])) {
        echo '<div class="status ok">URL replacement plan recorded.</div>';
    }
} else {
    echo '<div class="status warning">Swap staged database tables into destination table names.</div>';
    echo '<form method="post">';
    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    echo '<input type="hidden" name="swap_database_tables" value="1">';
    echo '<p><button type="submit">Swap Database Tables</button></p>';
    echo '</form>';
}
```

- [x] **Step 4: Run focused Bootstrap tests to verify GREEN**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add super-sheep-copy/installer/restore-engine/Bootstrap.php super-sheep-copy/tests/Unit/InstallerBootstrapTest.php
git commit -m "feat: add database table swap installer action"
```

---

### Task 5: Full Verification

**Files:**
- Verify all touched files.

- [x] **Step 1: Run unit suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 2: Scan for secrets and unsafe output**

Run:

```bash
rg -n "DB_PASSWORD|password|secret" super-sheep-copy/installer/restore-engine super-sheep-copy/tests/Unit
```

Expected: only credential parsing/test fixture references. No Bootstrap output renders DB passwords.

- [x] **Step 3: Scan for destructive SQL scope**

Run:

```bash
rg -n "DROP TABLE|TRUNCATE|DELETE FROM|UPDATE .* SET|RENAME TABLE" super-sheep-copy/installer/restore-engine super-sheep-copy/tests/Unit
```

Expected: `RENAME TABLE` only in table swap code/tests; staged import safety checks may mention blocked destructive statements. No new `DROP TABLE`, `TRUNCATE`, `DELETE FROM`, or broad `UPDATE` execution in table swap code.

- [x] **Step 4: Review diff**

Run:

```bash
git diff --check
git status --short
```

Expected: no whitespace errors; only intended files changed.

- [ ] **Step 5: Commit final verification fixes if needed**

```bash
git add super-sheep-copy/installer/restore-engine super-sheep-copy/tests/Unit
git commit -m "test: verify database table swap flow"
```

## Self-Review

- Spec coverage: Tasks cover URL replacement planning metadata, staged table existence checks, swap gating, table rename metadata, Bootstrap UI, and verification scans.
- Placeholder scan: No vague implementation steps remain.
- Type consistency: Config keys are `database_url_replacement_plan`, `database_url_replacement_planned_at`, `database_tables_swapped`, `database_tables_swapped_at`, `database_swap_table_count`, `database_swap_backup_tables`, and `locked`.
