# Database Export File Writer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Write database export SQL chunks and `database/tables.json` into a backup working directory.

**Architecture:** Add a WordPress-free `DatabaseExportWriter` under `src/Backup/Database/` that consumes existing `TableSchema`, `ChunkPlan`, and `DatabaseExportManifestBuilder` objects. It creates the database directory structure, validates chunk file names, writes chunk SQL, and writes manifest JSON without querying WordPress or creating archives.

**Tech Stack:** PHP 7.4+, Composer PSR-4 autoloading, PHPUnit 9.6, local filesystem temp directories.

---

## Scope Check

This plan implements `docs/superpowers/specs/2026-05-15-database-export-file-writer-design.md`.

Included:
- `DatabaseExportWriter`.
- Directory creation for `database/` and `database/chunks/`.
- SQL chunk file writing.
- `database/tables.json` writing through `DatabaseExportManifestBuilder`.
- Path safety checks.
- Unit tests with temporary directories.

Excluded:
- `$wpdb` querying.
- Archive writing.
- Backup manager orchestration.
- Resume state.
- Admin UI.
- WP-CLI.
- Restore/import.

## File Structure

- Create `super-sheep-copy/src/Backup/Database/DatabaseExportWriter.php`
  - Writes database export files into a caller-provided working directory.
- Create `super-sheep-copy/tests/Unit/DatabaseExportWriterTest.php`
  - Verifies file output and error handling.

---

### Task 1: Database Export Writer

**Files:**
- Create: `super-sheep-copy/src/Backup/Database/DatabaseExportWriter.php`
- Test: `super-sheep-copy/tests/Unit/DatabaseExportWriterTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/DatabaseExportWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\DatabaseExportWriter;
use SuperSheepCopy\Backup\Database\TableSchema;

final class DatabaseExportWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-db-writer-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testWritesChunksAndTablesManifest(): void
    {
        $posts = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 2, 'utf8mb4', 'utf8mb4_unicode_ci');
        $options = new TableSchema('wp_options', 'CREATE TABLE `wp_options` (`option_id` bigint)', 'option_id', 1, 'utf8mb4', 'utf8mb4_unicode_ci');
        $planner = new ChunkPlanner();
        $posts_plan = $planner->plan($posts, 100, 1, null);
        $options_plan = $planner->plan($options, 100, 1, null);

        $writer = new DatabaseExportWriter(new DatabaseExportManifestBuilder());
        $writer->write(
            $this->root,
            array($posts, $options),
            array(
                'wp_posts' => array($posts_plan),
                'wp_options' => array($options_plan),
            ),
            array(
                'wp_posts.part001.sql' => "DROP TABLE IF EXISTS `wp_posts`;\n",
                'wp_options.part001.sql' => "DROP TABLE IF EXISTS `wp_options`;\n",
            )
        );

        self::assertSame("DROP TABLE IF EXISTS `wp_posts`;\n", file_get_contents($this->root . '/database/chunks/wp_posts.part001.sql'));
        self::assertSame("DROP TABLE IF EXISTS `wp_options`;\n", file_get_contents($this->root . '/database/chunks/wp_options.part001.sql'));

        $manifest = json_decode((string) file_get_contents($this->root . '/database/tables.json'), true);
        self::assertSame('1', $manifest['format_version']);
        self::assertSame(2, $manifest['table_count']);
        self::assertSame(array('wp_posts.part001.sql'), $manifest['tables'][0]['chunks']);
    }

    public function testRejectsUnsafeChunkFileName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe database chunk file name: ../escape.sql');

        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 1, null, null);
        $writer = new DatabaseExportWriter(new DatabaseExportManifestBuilder());
        $writer->write(
            $this->root,
            array($schema),
            array('wp_posts' => array(new \SuperSheepCopy\Backup\Database\ChunkPlan('wp_posts', '../escape.sql', 'primary_key', 'ID', null, 100, null, 1))),
            array('../escape.sql' => 'SELECT 1;')
        );
    }

    public function testRejectsMissingSqlForPlannedChunk(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing SQL for database chunk: wp_posts.part001.sql');

        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 1, null, null);
        $plan = (new ChunkPlanner())->plan($schema, 100, 1, null);
        (new DatabaseExportWriter(new DatabaseExportManifestBuilder()))->write(
            $this->root,
            array($schema),
            array('wp_posts' => array($plan)),
            array()
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
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseExportWriterTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\Database\DatabaseExportWriter" not found`.

- [ ] **Step 3: Add writer implementation**

Create `super-sheep-copy/src/Backup/Database/DatabaseExportWriter.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;
use RuntimeException;

final class DatabaseExportWriter
{
    private DatabaseExportManifestBuilder $manifest_builder;

    public function __construct(DatabaseExportManifestBuilder $manifest_builder)
    {
        $this->manifest_builder = $manifest_builder;
    }

    /**
     * @param TableSchema[] $schemas
     * @param array<string, ChunkPlan[]> $plans_by_table
     * @param array<string, string> $sql_by_chunk
     */
    public function write(string $working_directory, array $schemas, array $plans_by_table, array $sql_by_chunk): void
    {
        $database_directory = rtrim($working_directory, '/\\') . '/database';
        $chunks_directory = $database_directory . '/chunks';

        $this->ensureDirectory($database_directory);
        $this->ensureDirectory($chunks_directory);

        foreach ($plans_by_table as $plans) {
            foreach ($plans as $plan) {
                $file_name = $plan->fileName();
                $this->assertSafeChunkFileName($file_name);

                if (!array_key_exists($file_name, $sql_by_chunk)) {
                    throw new InvalidArgumentException('Missing SQL for database chunk: ' . $file_name);
                }

                $this->writeFile($chunks_directory . '/' . $file_name, $sql_by_chunk[$file_name]);
            }
        }

        $manifest = $this->manifest_builder->build($schemas, $plans_by_table);
        $this->writeFile(
            $database_directory . '/tables.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write file: ' . $path);
        }
    }

    private function assertSafeChunkFileName(string $file_name): void
    {
        if (
            $file_name === ''
            || strpos($file_name, "\0") !== false
            || strpos($file_name, '/') !== false
            || strpos($file_name, '\\') !== false
            || strpos($file_name, '..') !== false
            || substr($file_name, -4) !== '.sql'
        ) {
            throw new InvalidArgumentException('Unsafe database chunk file name: ' . $file_name);
        }
    }
}
```

- [ ] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DatabaseExportWriterTest.php
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
git add super-sheep-copy/src/Backup/Database/DatabaseExportWriter.php super-sheep-copy/tests/Unit/DatabaseExportWriterTest.php
git commit -m "feat: write database export files"
```

Expected: commit succeeds.

---

### Task 2: Final Verification

**Files:**
- Verify: `super-sheep-copy/src/Backup/Database/DatabaseExportWriter.php`
- Verify: `super-sheep-copy/tests/Unit/DatabaseExportWriterTest.php`

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

- [ ] **Step 3: Confirm writer has no WordPress dependency**

Run:

```bash
rg "\\$wpdb|ABSPATH|wp-load|wp_" super-sheep-copy/src/Backup/Database/DatabaseExportWriter.php
```

Expected: no matches.

- [ ] **Step 4: Check git status**

Run:

```bash
git status --short
```

Expected: empty working tree after task commits.

## Self-Review

- Spec coverage: The plan covers database directory creation, SQL chunk file writing, `tables.json` writing through `DatabaseExportManifestBuilder`, unsafe file-name rejection, missing SQL rejection, and temp-directory tests.
- Placeholder scan: No step relies on unspecified implementation details. The test and implementation code are fully included.
- Type consistency: `DatabaseExportWriter` uses existing `DatabaseExportManifestBuilder`, `TableSchema`, and `ChunkPlan` classes in the `SuperSheepCopy\Backup\Database` namespace.

