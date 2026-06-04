# Installer Staged Database Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import backup database SQL chunks into isolated installer staging tables without replacing destination tables.

**Architecture:** Add installer-only readers/import helpers around the existing backup archive format. Validate `database/tables.json`, rewrite plugin-generated SQL from source table names to staging table names, execute only rewritten staging-table SQL, then expose a token-gated Bootstrap action after rollback prep.

**Tech Stack:** PHP 7.4-compatible standalone PHP, PHPUnit 9.6, ZipArchive, mysqli, existing installer config.

---

## Spec

This plan implements:

`docs/superpowers/specs/2026-05-18-installer-staged-database-import-design.md`

## Scope

Included:

- Read and validate backup archive database manifest/chunks.
- Rewrite backticked source table identifiers to staging table identifiers.
- Split plugin-generated SQL chunks into statements.
- Execute rewritten SQL into staging tables.
- Record staging import metadata in installer `config.php`.
- Add Bootstrap UI/action for staged database import.

Excluded:

- Destination table replacement/swap.
- URL replacement.
- File extraction/restore.
- Rollback execution.
- Manual DB credential entry.
- Real MySQL integration tests.

## File Structure

- Create `super-sheep-copy/installer/restore-engine/DatabaseImportManifestReader.php`
- Create `super-sheep-copy/tests/Unit/DatabaseImportManifestReaderTest.php`
- Create `super-sheep-copy/installer/restore-engine/SqlTableNameRewriter.php`
- Create `super-sheep-copy/tests/Unit/SqlTableNameRewriterTest.php`
- Create `super-sheep-copy/installer/restore-engine/DatabaseChunkImporter.php`
- Create `super-sheep-copy/tests/Unit/DatabaseChunkImporterTest.php`
- Create `super-sheep-copy/installer/restore-engine/DatabaseImportPreparationManager.php`
- Create `super-sheep-copy/tests/Unit/DatabaseImportPreparationManagerTest.php`
- Modify `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Modify `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

---

### Task 1: Database Import Manifest Reader

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DatabaseImportManifestReader.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseImportManifestReaderTest.php`

- [x] **Step 1: Write failing tests**

Create `DatabaseImportManifestReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseImportManifestReader.php';

final class DatabaseImportManifestReaderTest extends TestCase
{
    private string $archive;

    protected function setUp(): void
    {
        $this->archive = sys_get_temp_dir() . '/ssc-import-manifest-' . bin2hex(random_bytes(4)) . '.zip';
    }

    protected function tearDown(): void
    {
        if (is_file($this->archive)) {
            unlink($this->archive);
        }
    }

    public function testReadsValidDatabaseManifestAndChunks(): void
    {
        $this->writeArchive(array(
            'format_version' => '1',
            'table_count' => 1,
            'tables' => array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
        ), array('wp_posts.part001.sql' => 'CREATE TABLE `wp_posts` (`ID` bigint);'));

        $result = (new \SuperSheepCopyInstaller\DatabaseImportManifestReader())->read($this->archive);

        self::assertTrue($result['valid']);
        self::assertSame(array(), $result['warnings']);
        self::assertSame('wp_posts', $result['tables'][0]['name']);
        self::assertSame(array('wp_posts.part001.sql'), $result['tables'][0]['chunks']);
        self::assertSame('CREATE TABLE `wp_posts` (`ID` bigint);', $result['chunks']['wp_posts.part001.sql']);
    }

    public function testRejectsUnsafeChunkName(): void
    {
        $this->writeArchive(array(
            'format_version' => '1',
            'table_count' => 1,
            'tables' => array(array('name' => 'wp_posts', 'chunks' => array('../escape.sql'))),
        ), array('../escape.sql' => ''));

        $result = (new \SuperSheepCopyInstaller\DatabaseImportManifestReader())->read($this->archive);

        self::assertFalse($result['valid']);
        self::assertSame(array('Unsafe database chunk file name: ../escape.sql'), $result['warnings']);
    }

    public function testRejectsMissingChunkEntry(): void
    {
        $this->writeArchive(array(
            'format_version' => '1',
            'table_count' => 1,
            'tables' => array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
        ), array());

        $result = (new \SuperSheepCopyInstaller\DatabaseImportManifestReader())->read($this->archive);

        self::assertFalse($result['valid']);
        self::assertSame(array('Missing database chunk entry: database/chunks/wp_posts.part001.sql'), $result['warnings']);
    }

    private function writeArchive(array $manifest, array $chunks): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('database/tables.json', json_encode($manifest));
        foreach ($chunks as $file => $sql) {
            $zip->addFromString('database/chunks/' . basename($file), $sql);
        }
        $zip->close();
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseImportManifestReaderTest.php
```

Expected: FAIL because `DatabaseImportManifestReader.php` is missing.

- [x] **Step 3: Add manifest reader**

Create `DatabaseImportManifestReader.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use ZipArchive;

final class DatabaseImportManifestReader
{
    /**
     * @return array{valid:bool,tables:list<array{name:string,chunks:list<string>}>,chunks:array<string,string>,warnings:list<string>}
     */
    public function read(string $archive_path): array
    {
        if (!class_exists('\\ZipArchive') || !is_readable($archive_path)) {
            return $this->result(false, array(), array(), array('Database import archive is not readable.'));
        }

        $zip = new ZipArchive();
        if ($zip->open($archive_path) !== true) {
            return $this->result(false, array(), array(), array('Database import archive could not be opened.'));
        }

        $json = $zip->getFromName('database/tables.json');
        if (!is_string($json)) {
            $zip->close();

            return $this->result(false, array(), array(), array('Missing database/tables.json.'));
        }

        $manifest = json_decode($json, true);
        if (!is_array($manifest) || !isset($manifest['tables']) || !is_array($manifest['tables'])) {
            $zip->close();

            return $this->result(false, array(), array(), array('Invalid database/tables.json.'));
        }

        $tables = array();
        $chunks = array();
        $warnings = array();

        foreach ($manifest['tables'] as $table) {
            if (!is_array($table) || !isset($table['name'], $table['chunks']) || !is_string($table['name']) || !is_array($table['chunks']) || $table['name'] === '') {
                $warnings[] = 'Invalid database table manifest entry.';
                continue;
            }

            $table_chunks = array();
            foreach ($table['chunks'] as $chunk) {
                if (!is_string($chunk) || !$this->isSafeChunkName($chunk)) {
                    $warnings[] = 'Unsafe database chunk file name: ' . (is_scalar($chunk) ? (string) $chunk : '');
                    continue;
                }

                $entry = 'database/chunks/' . $chunk;
                $sql = $zip->getFromName($entry);
                if (!is_string($sql)) {
                    $warnings[] = 'Missing database chunk entry: ' . $entry;
                    continue;
                }

                $table_chunks[] = $chunk;
                $chunks[$chunk] = $sql;
            }

            $tables[] = array('name' => $table['name'], 'chunks' => $table_chunks);
        }

        $zip->close();

        return $this->result($warnings === array(), $tables, $chunks, $warnings);
    }

    private function isSafeChunkName(string $name): bool
    {
        return basename($name) === $name && preg_match('/^[A-Za-z0-9_.-]+\\.sql$/', $name) === 1;
    }

    private function result(bool $valid, array $tables, array $chunks, array $warnings): array
    {
        return array('valid' => $valid, 'tables' => $tables, 'chunks' => $chunks, 'warnings' => $warnings);
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseImportManifestReaderTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/DatabaseImportManifestReader.php super-sheep-copy/tests/Unit/DatabaseImportManifestReaderTest.php docs/superpowers/plans/2026-05-18-installer-staged-database-import.md
git commit -m "feat: read installer database import manifest"
```

Expected: commit succeeds.

---

### Task 2: SQL Table Name Rewriter

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/SqlTableNameRewriter.php`
- Test: `super-sheep-copy/tests/Unit/SqlTableNameRewriterTest.php`

- [x] **Step 1: Write failing tests**

Create `SqlTableNameRewriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/SqlTableNameRewriter.php';

final class SqlTableNameRewriterTest extends TestCase
{
    public function testRewritesBacktickedTableIdentifiersOnly(): void
    {
        $sql = "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (`ID` bigint);\nINSERT INTO `wp_posts` (`post_title`) VALUES ('wp_posts stays in string');\n";

        $rewritten = (new \SuperSheepCopyInstaller\SqlTableNameRewriter())->rewrite($sql, array('wp_posts' => 'ssc_tmp_abcd_wp_posts'));

        self::assertStringContainsString('DROP TABLE IF EXISTS `ssc_tmp_abcd_wp_posts`;', $rewritten);
        self::assertStringContainsString('CREATE TABLE `ssc_tmp_abcd_wp_posts`', $rewritten);
        self::assertStringContainsString('INSERT INTO `ssc_tmp_abcd_wp_posts`', $rewritten);
        self::assertStringContainsString("'wp_posts stays in string'", $rewritten);
    }

    public function testEscapesBackticksInReplacementIdentifier(): void
    {
        $rewritten = (new \SuperSheepCopyInstaller\SqlTableNameRewriter())->rewrite('CREATE TABLE `wp_posts` (`ID` bigint);', array('wp_posts' => 'tmp`posts'));

        self::assertStringContainsString('`tmp``posts`', $rewritten);
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/SqlTableNameRewriterTest.php
```

Expected: FAIL because `SqlTableNameRewriter.php` is missing.

- [x] **Step 3: Add rewriter**

Create `SqlTableNameRewriter.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class SqlTableNameRewriter
{
    /**
     * @param array<string,string> $table_map
     */
    public function rewrite(string $sql, array $table_map): string
    {
        foreach ($table_map as $source => $target) {
            $sql = str_replace($this->quoteIdentifier($source), $this->quoteIdentifier($target), $sql);
        }

        return $sql;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/SqlTableNameRewriterTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/SqlTableNameRewriter.php super-sheep-copy/tests/Unit/SqlTableNameRewriterTest.php docs/superpowers/plans/2026-05-18-installer-staged-database-import.md
git commit -m "feat: rewrite import sql table names"
```

Expected: commit succeeds.

---

### Task 3: Database Chunk Importer

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DatabaseChunkImporter.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseChunkImporterTest.php`

- [x] **Step 1: Write failing tests**

Create `DatabaseChunkImporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseChunkImporter.php';

final class DatabaseChunkImporterTest extends TestCase
{
    public function testSplitsPluginGeneratedSqlStatements(): void
    {
        $sql = "DROP TABLE IF EXISTS `tmp`;\nCREATE TABLE `tmp` (`ID` bigint);\nINSERT INTO `tmp` (`name`) VALUES ('semi; colon');\n";

        $statements = (new \SuperSheepCopyInstaller\DatabaseChunkImporter())->splitStatementsForTest($sql);

        self::assertCount(3, $statements);
        self::assertSame("DROP TABLE IF EXISTS `tmp`", $statements[0]);
        self::assertSame("CREATE TABLE `tmp` (`ID` bigint)", $statements[1]);
        self::assertSame("INSERT INTO `tmp` (`name`) VALUES ('semi; colon')", $statements[2]);
    }

    public function testRejectsOriginalDestinationDropStatement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL statement for staged import.');

        (new \SuperSheepCopyInstaller\DatabaseChunkImporter())->assertSafeStatementForTest('DROP TABLE IF EXISTS `wp_posts`', array('ssc_tmp_hash_wp_posts'));
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseChunkImporterTest.php
```

Expected: FAIL because `DatabaseChunkImporter.php` is missing.

- [x] **Step 3: Add chunk importer**

Create `DatabaseChunkImporter.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use InvalidArgumentException;

class DatabaseChunkImporter
{
    /**
     * @param array<string,mixed> $credentials
     * @param list<array{name:string,chunks:list<string>}> $tables
     * @param array<string,string> $chunks
     * @param array<string,string> $table_map
     * @return array{imported:bool,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    public function import(array $credentials, array $tables, array $chunks, array $table_map, SqlTableNameRewriter $rewriter): array
    {
        if (!class_exists('\\mysqli')) {
            return $this->result(false, 0, 0, 0, array('The mysqli extension is not available.'));
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
            return $this->result(false, 0, 0, 0, array('Database connection failed.'));
        }

        $statement_count = 0;
        $chunk_count = 0;
        $allowed_targets = array_values($table_map);

        foreach ($tables as $table) {
            foreach ($table['chunks'] as $chunk_name) {
                $sql = $rewriter->rewrite(isset($chunks[$chunk_name]) ? $chunks[$chunk_name] : '', $table_map);
                foreach ($this->splitStatements($sql) as $statement) {
                    $this->assertSafeStatement($statement, $allowed_targets);
                    if (!$mysqli->query($statement)) {
                        $mysqli->close();

                        return $this->result(false, count($tables), $chunk_count, $statement_count, array('Database import statement failed.'));
                    }
                    $statement_count++;
                }
                $chunk_count++;
            }
        }

        $mysqli->close();

        return $this->result(true, count($tables), $chunk_count, $statement_count, array());
    }

    /**
     * @return list<string>
     */
    public function splitStatementsForTest(string $sql): array
    {
        return $this->splitStatements($sql);
    }

    /**
     * @param list<string> $allowed_tables
     */
    public function assertSafeStatementForTest(string $statement, array $allowed_tables): void
    {
        $this->assertSafeStatement($statement, $allowed_tables);
    }

    /**
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = array();
        $buffer = '';
        $in_string = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $previous = $i > 0 ? $sql[$i - 1] : '';
            if ($char === "'" && $previous !== '\\') {
                $in_string = !$in_string;
            }
            if ($char === ';' && !$in_string) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * @param list<string> $allowed_tables
     */
    private function assertSafeStatement(string $statement, array $allowed_tables): void
    {
        if (preg_match('/\\b(RENAME\\s+TABLE|TRUNCATE|DELETE\\s+FROM|UPDATE\\s+.+\\s+SET)\\b/i', $statement) === 1) {
            throw new InvalidArgumentException('Unsafe SQL statement for staged import.');
        }

        if (preg_match_all('/`([^`]+(?:``[^`]+)*)`/', $statement, $matches) === false) {
            throw new InvalidArgumentException('Unsafe SQL statement for staged import.');
        }

        foreach ($matches[1] as $identifier) {
            $name = str_replace('``', '`', $identifier);
            if (in_array($name, $allowed_tables, true)) {
                continue;
            }
            if (preg_match('/^(CREATE|DROP|INSERT)\\b/i', $statement) === 1) {
                throw new InvalidArgumentException('Unsafe SQL statement for staged import.');
            }
        }
    }

    private function result(bool $imported, int $table_count, int $chunk_count, int $statement_count, array $warnings): array
    {
        return array(
            'imported' => $imported,
            'table_count' => $table_count,
            'chunk_count' => $chunk_count,
            'statement_count' => $statement_count,
            'warnings' => $warnings,
        );
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseChunkImporterTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/DatabaseChunkImporter.php super-sheep-copy/tests/Unit/DatabaseChunkImporterTest.php docs/superpowers/plans/2026-05-18-installer-staged-database-import.md
git commit -m "feat: import database chunks to staging"
```

Expected: commit succeeds.

---

### Task 4: Database Import Preparation Manager

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DatabaseImportPreparationManager.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseImportPreparationManagerTest.php`

- [x] **Step 1: Write failing tests**

Create `DatabaseImportPreparationManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseImportManifestReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/SqlTableNameRewriter.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseChunkImporter.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseImportPreparationManager.php';

final class DatabaseImportPreparationManagerTest extends TestCase
{
    private string $root;
    private string $engine;
    private string $archive;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-staged-db-import-' . bin2hex(random_bytes(4));
        $this->engine = $this->root . '/ssc-restore-engine';
        $this->archive = $this->root . '/backup.zip';
        mkdir($this->engine, 0777, true);
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");
        $this->writeArchive();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRejectsMissingRollbackDatabaseDump(): void
    {
        $result = $this->manager()->stage($this->engine, array('restore_confirmed' => true, 'rollback_prepared' => true), array());

        self::assertFalse($result['staged']);
        self::assertSame(array('Database import requires a database rollback dump.'), $result['warnings']);
    }

    public function testRecordsStagedImportMetadata(): void
    {
        $config = array(
            'restore_job_id' => 'restore-123',
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/id/database/destination.sql',
            'staged_archive_path' => $this->archive,
            'locked' => false,
        );
        $this->writeConfig($config);

        $result = $this->manager()->stage($this->engine, $config, array('HTTP_HOST' => 'destination.example'));
        $updated = require $this->engine . '/config.php';

        self::assertTrue($result['staged']);
        self::assertTrue($updated['database_import_staged']);
        self::assertSame(1, $updated['database_import_table_count']);
        self::assertSame(1, $updated['database_import_chunk_count']);
        self::assertSame(2, $updated['database_import_statement_count']);
        self::assertSame('ssc_tmp_' . substr(hash('sha256', 'restore-123'), 0, 8) . '_wp_posts', $updated['database_import_staging_tables']['wp_posts']);
        self::assertStringNotContainsString('secret', json_encode($updated['database_import_staging_tables']) ?: '');
    }

    private function manager(): \SuperSheepCopyInstaller\DatabaseImportPreparationManager
    {
        return new \SuperSheepCopyInstaller\DatabaseImportPreparationManager(
            new \SuperSheepCopyInstaller\WpConfigReader(),
            new FakeStagedImportConnectionTester(),
            new \SuperSheepCopyInstaller\DatabaseImportManifestReader(),
            new \SuperSheepCopyInstaller\SqlTableNameRewriter(),
            new FakeDatabaseChunkImporter()
        );
    }

    private function writeArchive(): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('database/tables.json', json_encode(array(
            'format_version' => '1',
            'table_count' => 1,
            'tables' => array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
        )));
        $zip->addFromString('database/chunks/wp_posts.part001.sql', 'CREATE TABLE `wp_posts` (`ID` bigint);');
        $zip->close();
    }

    private function writeConfig(array $config): void
    {
        file_put_contents($this->engine . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n");
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

final class FakeStagedImportConnectionTester extends \SuperSheepCopyInstaller\DatabaseConnectionTester
{
    public function test(array $credentials): array
    {
        return array('connected' => true, 'status' => 'ok', 'message' => 'Connected', 'database' => 'wordpress', 'host' => 'localhost');
    }
}

final class FakeDatabaseChunkImporter extends \SuperSheepCopyInstaller\DatabaseChunkImporter
{
    public function import(array $credentials, array $tables, array $chunks, array $table_map, \SuperSheepCopyInstaller\SqlTableNameRewriter $rewriter): array
    {
        \PHPUnit\Framework\Assert::assertSame(array('wp_posts'), array_keys($table_map));
        \PHPUnit\Framework\Assert::assertStringStartsWith('ssc_tmp_', $table_map['wp_posts']);

        return array('imported' => true, 'table_count' => 1, 'chunk_count' => 1, 'statement_count' => 2, 'warnings' => array());
    }
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseImportPreparationManagerTest.php
```

Expected: FAIL because manager file is missing.

- [x] **Step 3: Add preparation manager**

Create `DatabaseImportPreparationManager.php` with constructor dependencies:

```php
public function __construct(
    WpConfigReader $wp_config,
    DatabaseConnectionTester $connection_tester,
    DatabaseImportManifestReader $manifest_reader,
    SqlTableNameRewriter $rewriter,
    DatabaseChunkImporter $importer
) {
    $this->wp_config = $wp_config;
    $this->connection_tester = $connection_tester;
    $this->manifest_reader = $manifest_reader;
    $this->rewriter = $rewriter;
    $this->importer = $importer;
}
```

Public API:

```php
/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $server
 * @return array{staged:bool,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
 */
public function stage(string $engine_dir, array $config, array $server): array
```

Gate messages:

- `Restore is not confirmed.`
- `Rollback is not prepared.`
- `Database import requires a database rollback dump.`
- `Database import is already staged.`
- `Installer is locked.`
- `Database credentials are incomplete.`
- connection tester message if not connected
- manifest warnings if invalid
- importer warnings if failed

Staging table map:

```php
$hash = substr(hash('sha256', isset($config['restore_job_id']) ? (string) $config['restore_job_id'] : 'restore'), 0, 8);
$target = 'ssc_tmp_' . $hash . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $source);
```

Config update:

```php
$config['database_import_staged'] = true;
$config['database_import_staged_at'] = gmdate('c');
$config['database_import_table_count'] = $import['table_count'];
$config['database_import_chunk_count'] = $import['chunk_count'];
$config['database_import_statement_count'] = $import['statement_count'];
$config['database_import_staging_tables'] = $table_map;
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseImportPreparationManagerTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/DatabaseImportPreparationManager.php super-sheep-copy/tests/Unit/DatabaseImportPreparationManagerTest.php docs/superpowers/plans/2026-05-18-installer-staged-database-import.md
git commit -m "feat: prepare staged database import"
```

Expected: commit succeeds.

---

### Task 5: Bootstrap Staged Import UI

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Test: `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

- [x] **Step 1: Write failing Bootstrap tests**

Add tests:

```php
public function testRollbackPreparedWithDatabaseDumpShowsStagedImportForm(): void
{
    $this->writeWpConfig();
    $this->writeConfig('plain-token', $this->validArchive(), array(
        'restore_confirmed' => true,
        'rollback_prepared' => true,
        'rollback_database_dump' => 'rollback/id/database/destination.sql',
        'rollback_database_table_count' => 2,
    ));
    $_GET['token'] = 'plain-token';

    ob_start();
    \SuperSheepCopyInstaller\Bootstrap::run();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Database Import', $html);
    self::assertStringContainsString('Import Database to Staging', $html);
    self::assertStringContainsString('name="stage_database_import"', $html);
    self::assertStringNotContainsString('secret', $html);
}

public function testStagedImportStatusShowsCounts(): void
{
    $this->writeWpConfig();
    $this->writeConfig('plain-token', $this->validArchive(), array(
        'restore_confirmed' => true,
        'rollback_prepared' => true,
        'rollback_database_dump' => 'rollback/id/database/destination.sql',
        'database_import_staged' => true,
        'database_import_table_count' => 1,
        'database_import_chunk_count' => 2,
    ));
    $_GET['token'] = 'plain-token';

    ob_start();
    \SuperSheepCopyInstaller\Bootstrap::run();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Database import staged', $html);
    self::assertStringContainsString('1 tables', $html);
    self::assertStringContainsString('2 chunks', $html);
}
```

- [x] **Step 2: Run focused test to verify RED**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: FAIL because Bootstrap has no database import UI/action.

- [x] **Step 3: Wire Bootstrap**

Add requires:

```php
require_once __DIR__ . '/DatabaseImportManifestReader.php';
require_once __DIR__ . '/SqlTableNameRewriter.php';
require_once __DIR__ . '/DatabaseChunkImporter.php';
require_once __DIR__ . '/DatabaseImportPreparationManager.php';
```

Handle POST before archive validation output:

```php
$database_import_message = '';
if (self::requestMethod() === 'POST' && isset($_POST['stage_database_import'])) {
    $manager = new DatabaseImportPreparationManager(
        $wp_config,
        $database_tester,
        new DatabaseImportManifestReader(),
        new SqlTableNameRewriter(),
        new DatabaseChunkImporter()
    );
    $import_result = $manager->stage($engine_dir, $config, $_SERVER);
    if ($import_result['staged']) {
        $config = self::loadConfig($engine_dir);
        $database_import_message = 'Database import staged.';
    } else {
        $database_import_message = isset($import_result['warnings'][0]) ? $import_result['warnings'][0] : 'Database import staging failed.';
    }
}
```

Render after rollback section:

```php
echo '<h2>Database Import</h2>';
if ($database_import_message !== '') {
    echo '<div class="status ' . (!empty($config['database_import_staged']) ? 'ok' : 'warning') . '">' . htmlspecialchars($database_import_message, ENT_QUOTES, 'UTF-8') . '</div>';
}
if (empty($config['restore_confirmed'])) {
    echo '<div class="status warning">Database import requires restore confirmation.</div>';
} elseif (empty($config['rollback_prepared'])) {
    echo '<div class="status warning">Database import requires rollback preparation.</div>';
} elseif (empty($config['rollback_database_dump'])) {
    echo '<div class="status warning">Database import requires database rollback dump.</div>';
} elseif (!empty($config['database_import_staged'])) {
    echo '<div class="status ok">Database import staged. '
        . htmlspecialchars((string) ($config['database_import_table_count'] ?? 0), ENT_QUOTES, 'UTF-8') . ' tables, '
        . htmlspecialchars((string) ($config['database_import_chunk_count'] ?? 0), ENT_QUOTES, 'UTF-8') . ' chunks imported. Table replacement is not implemented yet.</div>';
} else {
    echo '<div class="status warning">Import backup database chunks into isolated staging tables. Destination tables will not be replaced.</div>';
    echo '<form method="post">';
    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    echo '<input type="hidden" name="stage_database_import" value="1">';
    echo '<p><button type="submit">Import Database to Staging</button></p>';
    echo '</form>';
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/Bootstrap.php super-sheep-copy/tests/Unit/InstallerBootstrapTest.php docs/superpowers/plans/2026-05-18-installer-staged-database-import.md
git commit -m "feat: add staged database import gate"
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

- [x] **Step 3: Confirm no destination-table replacement SQL exists**

Run:

```bash
rg -n "RENAME TABLE|TRUNCATE|DELETE FROM|UPDATE .* SET|DROP DATABASE" super-sheep-copy/installer/restore-engine
```

Expected: no matches except safety-test strings if tests are included separately. Installer engine should not contain replacement SQL.

- [x] **Step 4: Confirm destructive SQL is constrained to staged importer**

Run:

```bash
rg -n "DROP TABLE" super-sheep-copy/installer/restore-engine
```

Expected: matches only dump/import SQL text generation or safety checks, not destination table swap code.

- [x] **Step 5: Check git status**

Run:

```bash
git status --short
```

Expected: clean after committing checklist updates.

- [x] **Step 6: Commit checklist update**

Run:

```bash
git add docs/superpowers/plans/2026-05-18-installer-staged-database-import.md
git commit -m "docs: mark installer staged database import complete"
```

Expected: commit succeeds after Task 6 checkboxes are marked complete.

---

## Self-Review

- Spec coverage: Plan covers archive DB manifest/chunk reading, table-name rewriting, staged SQL importing, manager gates/config metadata, Bootstrap UI, lint, tests, and destructive SQL scans.
- Scope exclusions remain excluded: no destination table replacement, no URL replacement, no file extraction, no rollback execution, no manual credentials, no real MySQL test requirement.
- Type consistency: Manifest reader returns `valid`, `tables`, `chunks`, `warnings`; importer returns `imported`, `table_count`, `chunk_count`, `statement_count`, `warnings`; manager returns `staged`, counts, and warnings.
- Safety note: `DROP TABLE IF EXISTS` may be executed only after SQL is rewritten to staging table names and checked against the staging table allowlist.
