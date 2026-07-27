# Hyphenated SQL Identifiers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow the database backup exporter to process hyphenated table, column, and primary-key identifiers while preserving its SQL-injection allowlist.

**Architecture:** Keep the existing two-layer identifier validation in `WpdbDatabaseExporter` and `WpdbClient`, but extend both allowlists with a literal hyphen. Add exporter-level tests for discovered identifier use and adapter-level tests for backtick-delimited metadata queries; retain the existing malicious-input tests as the security regression boundary.

**Tech Stack:** PHP 7.4+, WordPress `$wpdb`, PHPUnit 9.6

---

## File Structure

- Modify `super-sheep-copy/tests/Unit/WpdbDatabaseExporterSchemaTest.php`: prove the exporter accepts a hyphenated table name.
- Modify `super-sheep-copy/tests/Unit/WpdbDatabaseExporterRowsTest.php`: prove chunk queries and row metadata accept hyphenated primary-key and column names.
- Modify `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php`: extend the exporter identifier allowlist.
- Modify `super-sheep-copy/tests/Unit/WpdbClientTest.php`: prove `$wpdb` metadata queries quote a hyphenated table name.
- Modify `super-sheep-copy/src/Backup/Database/WpdbClient.php`: extend the client identifier allowlist.

### Task 1: Accept Hyphenated Identifiers in the Exporter

**Files:**

- Modify: `super-sheep-copy/tests/Unit/WpdbDatabaseExporterSchemaTest.php:40`
- Modify: `super-sheep-copy/tests/Unit/WpdbDatabaseExporterRowsTest.php:59`
- Modify: `super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php:99`

- [ ] **Step 1: Add a failing hyphenated table-name test**

Insert this test before `testRejectsUnsafeTableIdentifier()` in
`WpdbDatabaseExporterSchemaTest`:

```php
public function testAcceptsHyphenatedTableIdentifier(): void
{
    $exporter = new WpdbDatabaseExporter(new SchemaFakeClient(), new TableSelector());

    $schema = $exporter->getSchema('wp-play-large');

    self::assertSame('wp-play-large', $schema->name());
    self::assertSame('CREATE TABLE `wp-play-large` (`ID` bigint)', $schema->createSql());
}
```

- [ ] **Step 2: Add failing primary-key and column tests**

Add configurable fake rows to `RowsFakeClient`:

```php
/** @var array<int, array<string, mixed>>|null */
private ?array $rows;

/**
 * @param array<int, array<string, mixed>>|null $rows
 */
public function __construct(?array $rows = null)
{
    $this->rows = $rows;
}
```

Replace `RowsFakeClient::getRows()` with:

```php
public function getRows(string $sql): array
{
    return $this->rows ?? array(array('ID' => 1, 'post_title' => 'Hello'));
}
```

Insert these tests before `testRejectsUnsafeColumnIdentifier()`:

```php
public function testBuildsQueryWithHyphenatedPrimaryKey(): void
{
    $client = new RowsFakeClient();
    $exporter = new WpdbDatabaseExporter($client, new TableSelector());
    $plan = new ChunkPlan('wp_posts', 'wp_posts.part001.sql', ChunkPlan::STRATEGY_PRIMARY_KEY, 'play-large', null, 100, null, 1);

    self::assertSame(
        'SELECT * FROM `wp_posts` ORDER BY `play-large` ASC LIMIT 100',
        $exporter->buildChunkQuery($plan)
    );
}

public function testFetchesRowsWithHyphenatedColumn(): void
{
    $client = new RowsFakeClient(array(array('play-large' => 1)));
    $exporter = new WpdbDatabaseExporter($client, new TableSelector());
    $plan = new ChunkPlan('wp_posts', 'wp_posts.part001.sql', ChunkPlan::STRATEGY_OFFSET, null, null, 100, 0, 1);

    $rows = $exporter->fetchRows($plan, array('play-large'));

    self::assertSame(array('play-large'), $rows->columns());
    self::assertSame(array(array('play-large' => 1)), $rows->rows());
}
```

- [ ] **Step 3: Run the exporter tests and verify the regression**

Run:

```bash
cd super-sheep-copy
vendor/bin/phpunit tests/Unit/WpdbDatabaseExporterSchemaTest.php tests/Unit/WpdbDatabaseExporterRowsTest.php
```

Expected: three new tests fail with `Unsafe SQL identifier` messages containing
`wp-play-large` or `play-large`; existing tests pass.

- [ ] **Step 4: Extend the exporter allowlist minimally**

In `WpdbDatabaseExporter::assertIdentifier()`, replace the regular expression
with:

```php
if ($identifier === '' || preg_match('/^[A-Za-z0-9_-]+$/', $identifier) !== 1) {
    throw new InvalidArgumentException('Unsafe SQL identifier: ' . esc_html($identifier));
}
```

- [ ] **Step 5: Run the exporter tests and verify green**

Run:

```bash
cd super-sheep-copy
vendor/bin/phpunit tests/Unit/WpdbDatabaseExporterSchemaTest.php tests/Unit/WpdbDatabaseExporterRowsTest.php
```

Expected: all tests in both files pass, including the existing semicolon-based
rejection tests.

- [ ] **Step 6: Commit the exporter regression fix**

```bash
git add super-sheep-copy/tests/Unit/WpdbDatabaseExporterSchemaTest.php super-sheep-copy/tests/Unit/WpdbDatabaseExporterRowsTest.php super-sheep-copy/src/Backup/Database/WpdbDatabaseExporter.php
git commit -m "fix: allow hyphenated database export identifiers"
```

### Task 2: Accept Hyphenated Table Names in the `$wpdb` Adapter

**Files:**

- Modify: `super-sheep-copy/tests/Unit/WpdbClientTest.php:41`
- Modify: `super-sheep-copy/src/Backup/Database/WpdbClient.php:85`

- [ ] **Step 1: Add a failing `$wpdb` adapter test**

Insert this test before `testRejectsUnsafeSqlIdentifiersBeforeQuerying()`:

```php
public function testQueriesHyphenatedTableIdentifier(): void
{
    $client = new WpdbClient(new FakeWpdb());

    self::assertSame(
        'CREATE TABLE `wp-play-large` (`play-large` bigint)',
        $client->getCreateTableSql('wp-play-large')
    );
    self::assertSame(array('play-large'), $client->getColumns('wp-play-large'));
}
```

Teach `FakeWpdb::get_row()` to model the quoted table:

```php
if ($sql === 'SHOW CREATE TABLE `wp-play-large`' && $output === 'ARRAY_N') {
    return array('wp-play-large', 'CREATE TABLE `wp-play-large` (`play-large` bigint)');
}
```

Teach `FakeWpdb::get_results()` to model its columns:

```php
if ($sql === 'SHOW COLUMNS FROM `wp-play-large`') {
    return array(array('Field' => 'play-large'));
}
```

- [ ] **Step 2: Run the adapter test and verify the regression**

Run:

```bash
cd super-sheep-copy
vendor/bin/phpunit tests/Unit/WpdbClientTest.php
```

Expected: `testQueriesHyphenatedTableIdentifier` fails with
`InvalidArgumentException: Unsafe SQL identifier.` before the fake database is
queried.

- [ ] **Step 3: Extend the client allowlist minimally**

In `WpdbClient::quoteIdentifier()`, replace the regular expression with:

```php
if (!preg_match('/^[A-Za-z0-9_-]+$/', $identifier)) {
    throw new \InvalidArgumentException('Unsafe SQL identifier.');
}
```

- [ ] **Step 4: Run the adapter test and verify green**

Run:

```bash
cd super-sheep-copy
vendor/bin/phpunit tests/Unit/WpdbClientTest.php
```

Expected: all `WpdbClientTest` tests pass, including the existing backtick and
SQL-fragment rejection test.

- [ ] **Step 5: Commit the adapter regression fix**

```bash
git add super-sheep-copy/tests/Unit/WpdbClientTest.php super-sheep-copy/src/Backup/Database/WpdbClient.php
git commit -m "fix: query hyphenated database tables"
```

### Task 3: Verify the Complete Plugin

**Files:**

- No source changes expected.

- [ ] **Step 1: Run the complete PHPUnit suite**

Run:

```bash
cd super-sheep-copy
vendor/bin/phpunit
```

Expected: the complete suite passes with zero failures and zero errors.

- [ ] **Step 2: Run PHP syntax validation**

Run:

```bash
cd super-sheep-copy
composer lint
```

Expected: every PHP file reports `No syntax errors detected` and the command
exits with status 0.

- [ ] **Step 3: Check the final diff and working tree**

Run:

```bash
git diff HEAD~2 --check
git status --short
```

Expected: `git diff --check` produces no output and the working tree is clean.
