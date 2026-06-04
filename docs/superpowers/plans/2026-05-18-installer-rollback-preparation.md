# Installer Rollback Preparation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add non-destructive rollback preparation inside standalone installer before future destructive restore work.

**Architecture:** Keep rollback prep installer-local and file allowlisted. Add rollback file collector, manifest builder, and preparation manager; then wire token-gated Bootstrap UI to require confirmed restore intent and no blocking preflight errors before preparing rollback artifact.

**Tech Stack:** PHP 7.4-compatible standalone PHP, PHPUnit, JSON files, SHA-256 checksums, existing installer `config.php`.

---

## Spec

This plan implements:

`docs/superpowers/specs/2026-05-18-installer-rollback-preparation-design.md`

## Scope

Included:

- Rollback artifact directory under `{engine_dir}/rollback/`.
- Optional `wp-config.php` snapshot.
- Rollback manifest JSON.
- Config fields: `rollback_prepared`, `rollback_prepared_at`, `rollback_directory`, `rollback_manifest`.
- Bootstrap rollback preparation UI and POST handling.

Excluded:

- Database dump rollback.
- Database connection test.
- Recursive file backup.
- Archive extraction.
- Restore execution.
- Rollback execution.
- Maintenance mode.
- Installer lock/delete.

## File Structure

- Create `super-sheep-copy/installer/restore-engine/RollbackFileCollector.php`
  - Copies allowlisted files into rollback artifact and returns entries/warnings.
- Create `super-sheep-copy/installer/restore-engine/RollbackManifestBuilder.php`
  - Builds sanitized rollback manifest array.
- Create `super-sheep-copy/installer/restore-engine/RollbackPreparationManager.php`
  - Coordinates rollback dir creation, file collector, manifest write, config update.
- Modify `super-sheep-copy/installer/restore-engine/Bootstrap.php`
  - Requires rollback manager classes, handles `prepare_rollback`, renders rollback status/form.
- Create `super-sheep-copy/tests/Unit/RollbackFileCollectorTest.php`
- Create `super-sheep-copy/tests/Unit/RollbackManifestBuilderTest.php`
- Create `super-sheep-copy/tests/Unit/RollbackPreparationManagerTest.php`
- Modify `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

---

### Task 1: Rollback File Collector

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/RollbackFileCollector.php`
- Test: `super-sheep-copy/tests/Unit/RollbackFileCollectorTest.php`

- [x] **Step 1: Write failing test**

Create `super-sheep-copy/tests/Unit/RollbackFileCollectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackFileCollector.php';

final class RollbackFileCollectorTest extends TestCase
{
    private string $root;
    private string $rollback;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-rollback-files-root-' . bin2hex(random_bytes(4));
        $this->rollback = sys_get_temp_dir() . '/ssc-rollback-files-artifact-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        mkdir($this->rollback, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        $this->removeDirectory($this->rollback);
    }

    public function testCopiesReadableWpConfigAndRecordsChecksumAndSize(): void
    {
        file_put_contents($this->root . '/wp-config.php', "<?php\n\$table_prefix = 'wp_';\n");

        $result = (new \SuperSheepCopyInstaller\RollbackFileCollector())->collect($this->root, $this->rollback);

        self::assertSame(array(), $result['warnings']);
        self::assertCount(1, $result['files']);
        self::assertSame('wp-config.php', $result['files'][0]['relative_path']);
        self::assertSame('files/wp-config.php', $result['files'][0]['rollback_path']);
        self::assertSame(hash_file('sha256', $this->root . '/wp-config.php'), $result['files'][0]['sha256']);
        self::assertSame(filesize($this->root . '/wp-config.php'), $result['files'][0]['size']);
        self::assertFileExists($this->rollback . '/files/wp-config.php');
    }

    public function testWarnsWhenWpConfigMissing(): void
    {
        $result = (new \SuperSheepCopyInstaller\RollbackFileCollector())->collect($this->root, $this->rollback);

        self::assertSame(array(), $result['files']);
        self::assertSame(array('wp-config.php is not readable.'), $result['warnings']);
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

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RollbackFileCollectorTest.php
```

Expected: FAIL because `RollbackFileCollector.php` missing.

- [x] **Step 3: Add file collector**

Create `super-sheep-copy/installer/restore-engine/RollbackFileCollector.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class RollbackFileCollector
{
    /**
     * @return array{files:list<array{relative_path:string,rollback_path:string,sha256:string,size:int}>,warnings:list<string>}
     */
    public function collect(string $wordpress_root, string $rollback_directory): array
    {
        $source = rtrim($wordpress_root, '/\\') . '/wp-config.php';
        $target_relative = 'files/wp-config.php';
        $target = rtrim($rollback_directory, '/\\') . '/' . $target_relative;

        if (!is_readable($source)) {
            return array('files' => array(), 'warnings' => array('wp-config.php is not readable.'));
        }

        $target_dir = dirname($target);
        if (!is_dir($target_dir) && !mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
            return array('files' => array(), 'warnings' => array('Unable to create rollback files directory.'));
        }

        if (!copy($source, $target)) {
            return array('files' => array(), 'warnings' => array('Unable to copy wp-config.php.'));
        }

        return array(
            'files' => array(array(
                'relative_path' => 'wp-config.php',
                'rollback_path' => $target_relative,
                'sha256' => hash_file('sha256', $target) ?: '',
                'size' => (int) filesize($target),
            )),
            'warnings' => array(),
        );
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RollbackFileCollectorTest.php
```

Expected: PASS.

- [x] **Step 5: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 6: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/RollbackFileCollector.php super-sheep-copy/tests/Unit/RollbackFileCollectorTest.php
git commit -m "feat: collect rollback file snapshot"
```

Expected: commit succeeds.

---

### Task 2: Rollback Manifest Builder

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/RollbackManifestBuilder.php`
- Test: `super-sheep-copy/tests/Unit/RollbackManifestBuilderTest.php`

- [x] **Step 1: Write failing test**

Create `super-sheep-copy/tests/Unit/RollbackManifestBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackManifestBuilder.php';

final class RollbackManifestBuilderTest extends TestCase
{
    public function testBuildsManifestWithExpectedMetadata(): void
    {
        $manifest = (new \SuperSheepCopyInstaller\RollbackManifestBuilder())->build(
            array(
                'restore_job_id' => 'restore-123',
                'source_site_url' => 'https://source.example',
                'source_home_url' => 'https://source.example/home',
                'staged_archive_basename' => 'restore-123.zip',
                'token_hash' => 'secret-hash',
                'db_password' => 'secret-password',
            ),
            'https://destination.example',
            '/var/www/html',
            array(array('relative_path' => 'wp-config.php', 'rollback_path' => 'files/wp-config.php', 'sha256' => 'abc', 'size' => 123)),
            array('sample warning')
        );

        self::assertSame('Super Sheep Copy', $manifest['project']);
        self::assertSame('rollback', $manifest['type']);
        self::assertSame('https://destination.example', $manifest['destination_url']);
        self::assertSame('/var/www/html', $manifest['wordpress_root']);
        self::assertSame('restore-123', $manifest['restore_job_id']);
        self::assertSame('restore-123.zip', $manifest['staged_archive_basename']);
        self::assertSame('wp-config.php', $manifest['files'][0]['relative_path']);
        self::assertSame(array('sample warning'), $manifest['warnings']);
    }

    public function testExcludesSecrets(): void
    {
        $manifest = (new \SuperSheepCopyInstaller\RollbackManifestBuilder())->build(
            array('token_hash' => 'secret-hash', 'db_password' => 'secret-password', 'staged_archive_path' => '/private/backup.zip'),
            '',
            '/var/www/html',
            array(),
            array()
        );

        $json = json_encode($manifest) ?: '';

        self::assertStringNotContainsString('secret-hash', $json);
        self::assertStringNotContainsString('secret-password', $json);
        self::assertStringNotContainsString('/private/backup.zip', $json);
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RollbackManifestBuilderTest.php
```

Expected: FAIL because `RollbackManifestBuilder.php` missing.

- [x] **Step 3: Add manifest builder**

Create `super-sheep-copy/installer/restore-engine/RollbackManifestBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class RollbackManifestBuilder
{
    /**
     * @param array<string,mixed> $config
     * @param list<array{relative_path:string,rollback_path:string,sha256:string,size:int}> $files
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    public function build(array $config, string $destination_url, string $wordpress_root, array $files, array $warnings): array
    {
        return array(
            'project' => 'Super Sheep Copy',
            'type' => 'rollback',
            'created_at' => gmdate('c'),
            'destination_url' => $destination_url,
            'wordpress_root' => $wordpress_root,
            'restore_job_id' => isset($config['restore_job_id']) ? (string) $config['restore_job_id'] : '',
            'source_site_url' => isset($config['source_site_url']) ? (string) $config['source_site_url'] : '',
            'source_home_url' => isset($config['source_home_url']) ? (string) $config['source_home_url'] : '',
            'staged_archive_basename' => isset($config['staged_archive_basename']) ? (string) $config['staged_archive_basename'] : '',
            'files' => $files,
            'warnings' => $warnings,
        );
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RollbackManifestBuilderTest.php
```

Expected: PASS.

- [x] **Step 5: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 6: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/RollbackManifestBuilder.php super-sheep-copy/tests/Unit/RollbackManifestBuilderTest.php
git commit -m "feat: build rollback manifest"
```

Expected: commit succeeds.

---

### Task 3: Rollback Preparation Manager

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/RollbackPreparationManager.php`
- Test: `super-sheep-copy/tests/Unit/RollbackPreparationManagerTest.php`

- [x] **Step 1: Write failing test**

Create `super-sheep-copy/tests/Unit/RollbackPreparationManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackFileCollector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackManifestBuilder.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DestinationDetector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackPreparationManager.php';

final class RollbackPreparationManagerTest extends TestCase
{
    private string $root;
    private string $engine;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-rollback-manager-' . bin2hex(random_bytes(4));
        $this->engine = $this->root . '/ssc-restore-engine';
        mkdir($this->engine, 0777, true);
        file_put_contents($this->root . '/wp-config.php', "<?php\n\$table_prefix = 'wp_';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRejectsUnconfirmedConfig(): void
    {
        $this->writeConfig(array('restore_job_id' => 'restore-123'));

        $result = $this->manager()->prepare($this->engine, require $this->engine . '/config.php', array());

        self::assertFalse($result['prepared']);
        self::assertSame('Restore is not confirmed.', $result['warnings'][0]);
    }

    public function testCreatesRollbackDirectoryCopiedFileAndManifestAndUpdatesConfig(): void
    {
        $this->writeConfig($this->confirmedConfig());

        $result = $this->manager()->prepare($this->engine, require $this->engine . '/config.php', array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php'));

        self::assertTrue($result['prepared']);
        self::assertSame(1, $result['file_count']);
        self::assertDirectoryExists($this->engine . '/rollback/' . $result['rollback_directory']);
        self::assertFileExists($this->engine . '/rollback/' . $result['rollback_directory'] . '/files/wp-config.php');
        self::assertFileExists($this->engine . '/rollback/' . $result['rollback_directory'] . '/manifest.json');

        $config = require $this->engine . '/config.php';
        self::assertTrue($config['rollback_prepared']);
        self::assertSame($result['rollback_directory'], $config['rollback_directory']);
        self::assertSame('rollback/' . $result['rollback_directory'] . '/manifest.json', $config['rollback_manifest']);

        $manifest = json_decode((string) file_get_contents($this->engine . '/' . $config['rollback_manifest']), true);
        self::assertSame('rollback', $manifest['type']);
        self::assertSame('http://destination.example', $manifest['destination_url']);
    }

    public function testAllowsManifestOnlyRollbackWhenWpConfigMissing(): void
    {
        unlink($this->root . '/wp-config.php');
        $this->writeConfig($this->confirmedConfig());

        $result = $this->manager()->prepare($this->engine, require $this->engine . '/config.php', array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php'));

        self::assertTrue($result['prepared']);
        self::assertSame(0, $result['file_count']);
        self::assertSame(array('wp-config.php is not readable.'), $result['warnings']);
    }

    private function manager(): \SuperSheepCopyInstaller\RollbackPreparationManager
    {
        return new \SuperSheepCopyInstaller\RollbackPreparationManager(
            new \SuperSheepCopyInstaller\RollbackFileCollector(),
            new \SuperSheepCopyInstaller\RollbackManifestBuilder(),
            new \SuperSheepCopyInstaller\DestinationDetector()
        );
    }

    private function confirmedConfig(): array
    {
        return array(
            'restore_confirmed' => true,
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
            'staged_archive_basename' => 'restore-123.zip',
            'locked' => false,
        );
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
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RollbackPreparationManagerTest.php
```

Expected: FAIL because `RollbackPreparationManager.php` missing.

- [x] **Step 3: Add preparation manager**

Create `super-sheep-copy/installer/restore-engine/RollbackPreparationManager.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class RollbackPreparationManager
{
    private RollbackFileCollector $files;
    private RollbackManifestBuilder $manifest;
    private DestinationDetector $destination;

    public function __construct(RollbackFileCollector $files, RollbackManifestBuilder $manifest, DestinationDetector $destination)
    {
        $this->files = $files;
        $this->manifest = $manifest;
        $this->destination = $destination;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $server
     * @return array{prepared:bool,rollback_directory:string,file_count:int,warnings:list<string>}
     */
    public function prepare(string $engine_dir, array $config, array $server): array
    {
        if (empty($config['restore_confirmed'])) {
            return $this->result(false, '', 0, array('Restore is not confirmed.'));
        }

        if (!empty($config['locked'])) {
            return $this->result(false, '', 0, array('Installer is locked.'));
        }

        $engine_dir = rtrim($engine_dir, '/\\');
        $wordpress_root = dirname($engine_dir);
        $basename = 'rollback-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $rollback_dir = $engine_dir . '/rollback/' . $basename;
        if (!is_dir($rollback_dir) && !mkdir($rollback_dir, 0777, true) && !is_dir($rollback_dir)) {
            return $this->result(false, '', 0, array('Unable to create rollback directory.'));
        }

        $collection = $this->files->collect($wordpress_root, $rollback_dir);
        $manifest = $this->manifest->build($config, $this->destination->detect($server), $wordpress_root, $collection['files'], $collection['warnings']);
        $manifest_path = $rollback_dir . '/manifest.json';
        if (file_put_contents($manifest_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return $this->result(false, $basename, count($collection['files']), array('Unable to write rollback manifest.'));
        }

        $config['rollback_prepared'] = true;
        $config['rollback_prepared_at'] = gmdate('c');
        $config['rollback_directory'] = $basename;
        $config['rollback_manifest'] = 'rollback/' . $basename . '/manifest.json';
        $config_path = $engine_dir . '/config.php';
        if (file_put_contents($config_path, "<?php\n\nreturn " . var_export($config, true) . ";\n") === false) {
            return $this->result(false, $basename, count($collection['files']), array('Unable to update installer config.'));
        }

        return $this->result(true, $basename, count($collection['files']), $collection['warnings']);
    }

    /**
     * @param list<string> $warnings
     * @return array{prepared:bool,rollback_directory:string,file_count:int,warnings:list<string>}
     */
    private function result(bool $prepared, string $directory, int $file_count, array $warnings): array
    {
        return array('prepared' => $prepared, 'rollback_directory' => $directory, 'file_count' => $file_count, 'warnings' => $warnings);
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RollbackPreparationManagerTest.php
```

Expected: PASS.

- [x] **Step 5: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 6: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/RollbackPreparationManager.php super-sheep-copy/tests/Unit/RollbackPreparationManagerTest.php
git commit -m "feat: prepare installer rollback artifact"
```

Expected: commit succeeds.

---

### Task 4: Bootstrap Rollback Preparation UI

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Modify: `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

- [x] **Step 1: Write failing tests**

Modify `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`.

Update `writeConfig()` to include optional extra config:

```php
private function writeConfig(string $token, string $archive, array $extra = array()): void
{
    file_put_contents($this->engine . '/config.php', "<?php\nreturn " . var_export(array_merge(array(
        'restore_job_id' => 'restore-123',
        'staged_archive_path' => $archive,
        'staged_archive_basename' => basename($archive),
        'source_site_url' => 'https://source.example',
        'source_home_url' => 'https://source.example/home',
        'token_hash' => password_hash($token, PASSWORD_DEFAULT),
        'token_created_at' => gmdate('c'),
        'locked' => false,
    ), $extra), true) . ";\n");
}
```

Add tests:

```php
/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
public function testConfirmedRestoreShowsRollbackPreparationForm(): void
{
    if (!class_exists(ZipArchive::class)) {
        self::markTestSkipped('ZipArchive is not available.');
    }

    $this->writeWpConfig();
    $this->writeConfig('plain-token', $this->validArchive(), array('restore_confirmed' => true));
    $_GET['token'] = 'plain-token';

    ob_start();
    \SuperSheepCopyInstaller\Bootstrap::run();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Rollback Preparation', $html);
    self::assertStringContainsString('Prepare Rollback', $html);
    self::assertStringContainsString('name="prepare_rollback"', $html);
}

/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
public function testRollbackPostRecordsRollbackPreparedStatus(): void
{
    if (!class_exists(ZipArchive::class)) {
        self::markTestSkipped('ZipArchive is not available.');
    }

    $this->writeWpConfig();
    $this->writeConfig('plain-token', $this->validArchive(), array('restore_confirmed' => true));
    $_GET['token'] = 'plain-token';
    $_POST = array('token' => 'plain-token', 'prepare_rollback' => '1');

    ob_start();
    \SuperSheepCopyInstaller\Bootstrap::run();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Rollback prepared', $html);
    $config = require $this->engine . '/config.php';
    self::assertTrue($config['rollback_prepared']);
    self::assertFileExists($this->engine . '/' . $config['rollback_manifest']);
}

/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
public function testUnconfirmedRestoreDoesNotShowRollbackForm(): void
{
    if (!class_exists(ZipArchive::class)) {
        self::markTestSkipped('ZipArchive is not available.');
    }

    $this->writeWpConfig();
    $this->writeConfig('plain-token', $this->validArchive());
    $_GET['token'] = 'plain-token';

    ob_start();
    \SuperSheepCopyInstaller\Bootstrap::run();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Rollback requires restore confirmation', $html);
    self::assertStringNotContainsString('name="prepare_rollback"', $html);
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: FAIL because Bootstrap does not render rollback UI or process rollback POST.

- [x] **Step 3: Update bootstrap dependencies**

Modify `super-sheep-copy/installer/restore-engine/Bootstrap.php` and add:

```php
require_once __DIR__ . '/RollbackFileCollector.php';
require_once __DIR__ . '/RollbackManifestBuilder.php';
require_once __DIR__ . '/RollbackPreparationManager.php';
```

- [x] **Step 4: Handle rollback POST**

In `Bootstrap::run()`, after confirmation handling and before archive display, add:

```php
$rollback_message = '';
if (self::requestMethod() === 'POST' && isset($_POST['prepare_rollback'])) {
    if ($has_blocking_errors) {
        $rollback_message = 'Rollback preparation blocked by preflight errors.';
    } elseif (empty($config['restore_confirmed'])) {
        $rollback_message = 'Rollback requires restore confirmation.';
    } else {
        $rollback = new RollbackPreparationManager(new RollbackFileCollector(), new RollbackManifestBuilder(), new DestinationDetector());
        $rollback_result = $rollback->prepare($engine_dir, $config, $_SERVER);
        if ($rollback_result['prepared']) {
            $config = self::loadConfig($engine_dir);
            $rollback_message = 'Rollback prepared.';
        } else {
            $rollback_message = 'Rollback preparation failed.';
        }
    }
}
```

- [x] **Step 5: Render rollback UI**

After restore confirmation section, add:

```php
echo '<h2>Rollback Preparation</h2>';
if ($rollback_message !== '') {
    echo '<div class="status ' . (!empty($config['rollback_prepared']) ? 'ok' : 'warning') . '">' . htmlspecialchars($rollback_message, ENT_QUOTES, 'UTF-8') . '</div>';
}
if (empty($config['restore_confirmed'])) {
    echo '<div class="status warning">Rollback requires restore confirmation.</div>';
} elseif (!empty($config['rollback_prepared'])) {
    echo '<div class="status ok">Rollback prepared. Restore execution is still not implemented yet.</div>';
} elseif ($has_blocking_errors) {
    echo '<div class="status error">Resolve blocking preflight errors before preparing rollback.</div>';
} else {
    echo '<div class="status warning">Prepare rollback artifact before any future destructive restore step.</div>';
    echo '<form method="post">';
    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    echo '<input type="hidden" name="prepare_rollback" value="1">';
    echo '<p><button type="submit">Prepare Rollback</button></p>';
    echo '</form>';
}
```

- [x] **Step 6: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: PASS.

- [x] **Step 7: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 8: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/Bootstrap.php super-sheep-copy/tests/Unit/InstallerBootstrapTest.php
git commit -m "feat: add installer rollback preparation gate"
```

Expected: commit succeeds.

---

### Task 5: Final Verification

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

- [x] **Step 3: Confirm direct request access is scoped**

Run:

```bash
rg -n '\$_POST|\$_REQUEST|\$_GET|\$_FILES|\$_SERVER' super-sheep-copy/src super-sheep-copy/installer
```

Expected: direct request/global access remains limited to:

- `super-sheep-copy/src/Admin/BackupPage.php`
- `super-sheep-copy/src/Admin/RestorePage.php`
- `super-sheep-copy/src/Security/Nonce.php`
- `super-sheep-copy/installer/restore-engine/Security.php`
- `super-sheep-copy/installer/restore-engine/Bootstrap.php`

- [x] **Step 4: Check git status**

Run:

```bash
git status --short
```

Expected: clean after committing plan checklist updates.

- [x] **Step 5: Commit checklist update**

Run:

```bash
git add docs/superpowers/plans/2026-05-18-installer-rollback-preparation.md
git commit -m "docs: mark installer rollback preparation complete"
```

Expected: commit succeeds after Task 5 checkboxes are marked complete.

---

## Self-Review

- Spec coverage: Plan covers file collector, manifest builder, rollback manager, Bootstrap UI/POST, tests, lint, full suite, request scan, git clean.
- Scope exclusions remain excluded: no database dump, DB connection test, recursive file backup, archive extraction, restore execution, rollback execution, maintenance mode, installer lock/delete, cache clearing, or health checks.
- Type consistency: `RollbackFileCollector::collect(string,string): array`, `RollbackManifestBuilder::build(array,string,string,array,array): array`, and `RollbackPreparationManager::prepare(string,array,array): array` align with spec.
- Gap scan: every task has concrete test, implementation, verification, and commit steps.
