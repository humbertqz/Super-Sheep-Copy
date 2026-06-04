# Installer Preflight and Confirmation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a non-destructive standalone installer preflight and explicit restore-confirmation gate before any future rollback or destructive restore work.

**Architecture:** Keep all new behavior inside the standalone installer runtime, without loading WordPress. Add small installer-local services for destination URL detection, `wp-config.php` credential detection, preflight aggregation, and confirmation-state persistence; then update `Bootstrap` to render token-gated preflight and confirmation UI.

**Tech Stack:** PHP 7.4-compatible standalone PHP, PHPUnit, `ZipArchive`, existing installer `config.php`, existing installer `Security`, `ArchiveValidator`, and `EnvironmentChecker`.

---

## Spec

This plan implements:

`docs/superpowers/specs/2026-05-18-installer-preflight-confirmation-design.md`

## Scope

Included:

- Destination URL detection from `$_SERVER`.
- Read-only `wp-config.php` database credential detection.
- Preflight check aggregation with `ok`, `warning`, and `error` statuses.
- Blocking-error detection for confirmation gating.
- Installer config update for `restore_confirmed` and `restore_confirmed_at`.
- Token-gated bootstrap page showing source-to-destination preview, preflight checks, and confirmation form.

Excluded:

- Database connection testing.
- Database import.
- Archive extraction.
- File restore.
- URL replacement execution.
- Rollback backup creation.
- Maintenance mode.
- Installer lock/delete.
- Manual database credential form.

## File Structure

- Create `super-sheep-copy/installer/restore-engine/DestinationDetector.php`
  - Detects current destination URL from server variables.
- Create `super-sheep-copy/installer/restore-engine/WpConfigReader.php`
  - Reads simple database constants and table prefix from `wp-config.php` without exposing secrets.
- Create `super-sheep-copy/installer/restore-engine/PreflightChecker.php`
  - Combines environment checks, archive validation, destination detection, root writability, and wp-config readiness.
- Create `super-sheep-copy/installer/restore-engine/ConfirmationStore.php`
  - Persists confirmation state to installer `config.php`.
- Modify `super-sheep-copy/installer/restore-engine/Bootstrap.php`
  - Requires new classes, runs preflight, handles confirmation POST, renders preflight and confirmation sections.
- Create `super-sheep-copy/tests/Unit/DestinationDetectorTest.php`
- Create `super-sheep-copy/tests/Unit/WpConfigReaderTest.php`
- Create `super-sheep-copy/tests/Unit/PreflightCheckerTest.php`
- Create `super-sheep-copy/tests/Unit/ConfirmationStoreTest.php`
- Modify `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

---

### Task 1: Destination and wp-config Detection

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/DestinationDetector.php`
- Create: `super-sheep-copy/installer/restore-engine/WpConfigReader.php`
- Test: `super-sheep-copy/tests/Unit/DestinationDetectorTest.php`
- Test: `super-sheep-copy/tests/Unit/WpConfigReaderTest.php`

- [x] **Step 1: Write destination detector failing test**

Create `super-sheep-copy/tests/Unit/DestinationDetectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DestinationDetector.php';

final class DestinationDetectorTest extends TestCase
{
    public function testDetectsRootHttpUrl(): void
    {
        $detector = new \SuperSheepCopyInstaller\DestinationDetector();

        self::assertSame('http://example.com', $detector->detect(array(
            'HTTP_HOST' => 'example.com',
            'SCRIPT_NAME' => '/installer.php',
        )));
    }

    public function testDetectsHttpsSubdirectoryUrl(): void
    {
        $detector = new \SuperSheepCopyInstaller\DestinationDetector();

        self::assertSame('https://example.com/subsite', $detector->detect(array(
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com',
            'SCRIPT_NAME' => '/subsite/installer.php',
        )));
    }

    public function testDetectsForwardedHttps(): void
    {
        $detector = new \SuperSheepCopyInstaller\DestinationDetector();

        self::assertSame('https://example.com', $detector->detect(array(
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'SERVER_NAME' => 'example.com',
            'SCRIPT_NAME' => '/installer.php',
        )));
    }

    public function testReturnsEmptyStringWhenHostIsMissing(): void
    {
        $detector = new \SuperSheepCopyInstaller\DestinationDetector();

        self::assertSame('', $detector->detect(array('SCRIPT_NAME' => '/installer.php')));
    }
}
```

- [x] **Step 2: Write wp-config reader failing test**

Create `super-sheep-copy/tests/Unit/WpConfigReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';

final class WpConfigReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-wp-config-reader-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: array() as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testParsesDatabaseConstantsAndTablePrefix(): void
    {
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");

        $config = (new \SuperSheepCopyInstaller\WpConfigReader())->readDatabaseConfig($this->root);

        self::assertTrue($config['readable']);
        self::assertTrue($config['has_db_name']);
        self::assertTrue($config['has_db_user']);
        self::assertTrue($config['has_db_password']);
        self::assertTrue($config['has_db_host']);
        self::assertTrue($config['has_table_prefix']);
        self::assertSame('wp_', $config['table_prefix']);
        self::assertArrayNotHasKey('db_password', $config);
        self::assertStringNotContainsString('secret', json_encode($config) ?: '');
    }

    public function testReportsMissingConfigAsUnreadable(): void
    {
        $config = (new \SuperSheepCopyInstaller\WpConfigReader())->readDatabaseConfig($this->root);

        self::assertFalse($config['readable']);
        self::assertFalse($config['has_db_name']);
        self::assertFalse($config['has_db_user']);
        self::assertFalse($config['has_db_password']);
        self::assertFalse($config['has_db_host']);
        self::assertFalse($config['has_table_prefix']);
    }
}
```

- [x] **Step 3: Run tests to verify they fail**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DestinationDetectorTest.php tests/Unit/WpConfigReaderTest.php
```

Expected: FAIL because the required installer classes do not exist.

- [x] **Step 4: Add destination detector**

Create `super-sheep-copy/installer/restore-engine/DestinationDetector.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class DestinationDetector
{
    /**
     * @param array<string,mixed> $server
     */
    public function detect(array $server): string
    {
        $host = isset($server['HTTP_HOST']) ? (string) $server['HTTP_HOST'] : '';
        if ($host === '' && isset($server['SERVER_NAME'])) {
            $host = (string) $server['SERVER_NAME'];
        }

        if ($host === '') {
            return '';
        }

        $https = isset($server['HTTPS']) && strtolower((string) $server['HTTPS']) === 'on';
        $forwarded = isset($server['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $server['HTTP_X_FORWARDED_PROTO']) === 'https';
        $scheme = ($https || $forwarded) ? 'https' : 'http';
        $script = isset($server['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $server['SCRIPT_NAME']) : '/installer.php';
        $directory = rtrim(str_replace('/installer.php', '', $script), '/');

        return $scheme . '://' . $host . ($directory === '' ? '' : $directory);
    }
}
```

- [x] **Step 5: Add wp-config reader**

Create `super-sheep-copy/installer/restore-engine/WpConfigReader.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class WpConfigReader
{
    /**
     * @return array{readable:bool,has_db_name:bool,has_db_user:bool,has_db_password:bool,has_db_host:bool,has_table_prefix:bool,table_prefix:string}
     */
    public function readDatabaseConfig(string $wordpress_root): array
    {
        $defaults = array(
            'readable' => false,
            'has_db_name' => false,
            'has_db_user' => false,
            'has_db_password' => false,
            'has_db_host' => false,
            'has_table_prefix' => false,
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

        $prefix = '';
        if (preg_match('/\\$table_prefix\\s*=\\s*[\\'"]([^\\'"]*)[\\'"]\\s*;/', $contents, $match) === 1) {
            $prefix = (string) $match[1];
        }

        return array(
            'readable' => true,
            'has_db_name' => $this->hasDefine($contents, 'DB_NAME'),
            'has_db_user' => $this->hasDefine($contents, 'DB_USER'),
            'has_db_password' => $this->hasDefine($contents, 'DB_PASSWORD'),
            'has_db_host' => $this->hasDefine($contents, 'DB_HOST'),
            'has_table_prefix' => $prefix !== '',
            'table_prefix' => $prefix,
        );
    }

    private function hasDefine(string $contents, string $name): bool
    {
        return preg_match('/define\\s*\\(\\s*[\\'"]' . preg_quote($name, '/') . '[\\'"]\\s*,\\s*[\\'"][^\\'"]*[\\'"]\\s*\\)/', $contents) === 1;
    }
}
```

- [x] **Step 6: Run focused tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DestinationDetectorTest.php tests/Unit/WpConfigReaderTest.php
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
git add super-sheep-copy/installer/restore-engine/DestinationDetector.php super-sheep-copy/installer/restore-engine/WpConfigReader.php super-sheep-copy/tests/Unit/DestinationDetectorTest.php super-sheep-copy/tests/Unit/WpConfigReaderTest.php
git commit -m "feat: detect installer destination context"
```

Expected: commit succeeds.

---

### Task 2: Installer Preflight Checker

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/PreflightChecker.php`
- Test: `super-sheep-copy/tests/Unit/PreflightCheckerTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/PreflightCheckerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/EnvironmentChecker.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/ArchiveValidationResult.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/ArchiveValidator.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DestinationDetector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PreflightChecker.php';

final class PreflightCheckerTest extends TestCase
{
    private string $root;
    private string $engine;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-preflight-root-' . bin2hex(random_bytes(4));
        $this->engine = $this->root . '/ssc-restore-engine';
        mkdir($this->engine, 0777, true);
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReportsOkArchiveAndDestinationChecksForValidContext(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $checks = $this->checker()->run(
            array('staged_archive_path' => $this->validArchive()),
            array('HTTP_HOST' => 'example.com', 'SCRIPT_NAME' => '/installer.php'),
            $this->engine
        );

        self::assertSame('ok', $this->status($checks, 'archive_readable'));
        self::assertSame('ok', $this->status($checks, 'archive_valid'));
        self::assertSame('ok', $this->status($checks, 'destination_url'));
        self::assertSame('ok', $this->status($checks, 'wp_config_readable'));
        self::assertFalse(\SuperSheepCopyInstaller\PreflightChecker::hasBlockingErrors($checks));
    }

    public function testReportsBlockingErrorForUnreadableArchive(): void
    {
        $checks = $this->checker()->run(
            array('staged_archive_path' => $this->root . '/missing.zip'),
            array('HTTP_HOST' => 'example.com', 'SCRIPT_NAME' => '/installer.php'),
            $this->engine
        );

        self::assertSame('error', $this->status($checks, 'archive_readable'));
        self::assertTrue(\SuperSheepCopyInstaller\PreflightChecker::hasBlockingErrors($checks));
    }

    public function testReportsWarningForUnreadableWpConfig(): void
    {
        unlink($this->root . '/wp-config.php');

        $checks = $this->checker()->run(
            array('staged_archive_path' => $this->validArchive()),
            array('HTTP_HOST' => 'example.com', 'SCRIPT_NAME' => '/installer.php'),
            $this->engine
        );

        self::assertSame('warning', $this->status($checks, 'wp_config_readable'));
    }

    private function checker(): \SuperSheepCopyInstaller\PreflightChecker
    {
        return new \SuperSheepCopyInstaller\PreflightChecker(
            new \SuperSheepCopyInstaller\EnvironmentChecker(),
            new \SuperSheepCopyInstaller\DestinationDetector(),
            new \SuperSheepCopyInstaller\WpConfigReader(),
            new \SuperSheepCopyInstaller\ArchiveValidator()
        );
    }

    private function status(array $checks, string $key): string
    {
        foreach ($checks as $check) {
            if ($check['key'] === $key) {
                return $check['status'];
            }
        }

        return '';
    }

    private function validArchive(): string
    {
        $archive = $this->root . '/backup.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', json_encode(array(
            'project' => 'Super Sheep Copy',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
        )));
        $zip->addFromString('checksums.json', '{}');
        $zip->addFromString('database/tables.json', '{}');
        $zip->close();

        return $archive;
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
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/PreflightCheckerTest.php
```

Expected: FAIL because `PreflightChecker` does not exist.

- [x] **Step 3: Add preflight checker**

Create `super-sheep-copy/installer/restore-engine/PreflightChecker.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class PreflightChecker
{
    private EnvironmentChecker $environment;
    private DestinationDetector $destination;
    private WpConfigReader $wp_config;
    private ArchiveValidator $archive_validator;

    public function __construct(EnvironmentChecker $environment, DestinationDetector $destination, WpConfigReader $wp_config, ArchiveValidator $archive_validator)
    {
        $this->environment = $environment;
        $this->destination = $destination;
        $this->wp_config = $wp_config;
        $this->archive_validator = $archive_validator;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $server
     * @return list<array{key:string,label:string,status:string,value:string,message:string}>
     */
    public function run(array $config, array $server, string $engine_dir): array
    {
        $checks = array();
        foreach ($this->environment->check() as $key => $check) {
            $checks[] = $this->check((string) $key, $check['label'], $check['status'], $check['value'], '');
        }

        $archive_path = isset($config['staged_archive_path']) ? (string) $config['staged_archive_path'] : '';
        $archive_readable = $archive_path !== '' && is_readable($archive_path);
        $checks[] = $this->check('archive_readable', 'Staged archive readable', $archive_readable ? 'ok' : 'error', $archive_readable ? 'Readable' : 'Unavailable', $archive_readable ? '' : 'The prepared archive cannot be read.');

        if ($archive_readable) {
            $validation = $this->archive_validator->validatePackage($archive_path);
            $checks[] = $this->check('archive_valid', 'Staged archive valid', $validation->isValid() ? 'ok' : 'error', $validation->isValid() ? 'Valid' : 'Invalid', $validation->isValid() ? '' : 'The prepared archive failed validation.');
        } else {
            $checks[] = $this->check('archive_valid', 'Staged archive valid', 'error', 'Not checked', 'Archive validation requires a readable archive.');
        }

        $destination_url = $this->destination->detect($server);
        $checks[] = $this->check('destination_url', 'Destination URL detected', $destination_url === '' ? 'warning' : 'ok', $destination_url === '' ? 'Unavailable' : $destination_url, $destination_url === '' ? 'The installer could not detect the destination URL.' : '');

        $wordpress_root = dirname(rtrim($engine_dir, '/\\'));
        $checks[] = $this->check('wordpress_root', 'WordPress root detected', is_dir($wordpress_root) ? 'ok' : 'error', is_dir($wordpress_root) ? 'Detected' : 'Missing', is_dir($wordpress_root) ? '' : 'The WordPress root directory could not be detected.');
        $checks[] = $this->check('wordpress_root_writable', 'WordPress root writable', is_writable($wordpress_root) ? 'ok' : 'warning', is_writable($wordpress_root) ? 'Writable' : 'Not writable', is_writable($wordpress_root) ? '' : 'File restore may require writable WordPress root permissions later.');

        $database = $this->wp_config->readDatabaseConfig($wordpress_root);
        $checks[] = $this->check('wp_config_readable', 'wp-config.php readable', $database['readable'] ? 'ok' : 'warning', $database['readable'] ? 'Readable' : 'Unavailable', $database['readable'] ? '' : 'Manual database credentials will be required in a later step.');
        $has_credentials = $database['has_db_name'] && $database['has_db_user'] && $database['has_db_password'] && $database['has_db_host'];
        $checks[] = $this->check('database_credentials', 'Database credentials detected', $has_credentials ? 'ok' : 'warning', $has_credentials ? 'Detected' : 'Incomplete', $has_credentials ? '' : 'Database constants are incomplete or unavailable.');

        return $checks;
    }

    /**
     * @param list<array{key:string,label:string,status:string,value:string,message:string}> $checks
     */
    public static function hasBlockingErrors(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{key:string,label:string,status:string,value:string,message:string}
     */
    private function check(string $key, string $label, string $status, string $value, string $message): array
    {
        return array('key' => $key, 'label' => $label, 'status' => $status, 'value' => $value, 'message' => $message);
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/PreflightCheckerTest.php
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
git add super-sheep-copy/installer/restore-engine/PreflightChecker.php super-sheep-copy/tests/Unit/PreflightCheckerTest.php
git commit -m "feat: add installer preflight checks"
```

Expected: commit succeeds.

---

### Task 3: Confirmation Store

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/ConfirmationStore.php`
- Test: `super-sheep-copy/tests/Unit/ConfirmationStoreTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/ConfirmationStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/ConfirmationStore.php';

final class ConfirmationStoreTest extends TestCase
{
    private string $engine;

    protected function setUp(): void
    {
        $this->engine = sys_get_temp_dir() . '/ssc-confirmation-store-' . bin2hex(random_bytes(4));
        mkdir($this->engine, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->engine . '/*') ?: array() as $file) {
            unlink($file);
        }
        rmdir($this->engine);
    }

    public function testRejectsMissingCheckbox(): void
    {
        $store = new \SuperSheepCopyInstaller\ConfirmationStore();
        $config = $this->config();
        $this->writeConfig($config);

        self::assertFalse($store->confirm($this->engine, $config, 'RESTORE', false, false));
        self::assertFalse($store->isConfirmed(require $this->engine . '/config.php'));
    }

    public function testRejectsWrongTypedPhrase(): void
    {
        $store = new \SuperSheepCopyInstaller\ConfirmationStore();
        $config = $this->config();
        $this->writeConfig($config);

        self::assertFalse($store->confirm($this->engine, $config, 'restore', true, false));
        self::assertFalse($store->isConfirmed(require $this->engine . '/config.php'));
    }

    public function testRejectsBlockingPreflightErrors(): void
    {
        $store = new \SuperSheepCopyInstaller\ConfirmationStore();
        $config = $this->config();
        $this->writeConfig($config);

        self::assertFalse($store->confirm($this->engine, $config, 'RESTORE', true, true));
        self::assertFalse($store->isConfirmed(require $this->engine . '/config.php'));
    }

    public function testWritesConfirmationFieldsAndPreservesConfig(): void
    {
        $store = new \SuperSheepCopyInstaller\ConfirmationStore();
        $config = $this->config();
        $this->writeConfig($config);

        self::assertTrue($store->confirm($this->engine, $config, 'RESTORE', true, false));

        $updated = require $this->engine . '/config.php';
        self::assertTrue($store->isConfirmed($updated));
        self::assertSame('restore-123', $updated['restore_job_id']);
        self::assertSame('hash', $updated['token_hash']);
        self::assertArrayHasKey('restore_confirmed_at', $updated);
    }

    private function config(): array
    {
        return array(
            'restore_job_id' => 'restore-123',
            'token_hash' => 'hash',
            'staged_archive_path' => '/tmp/backup.zip',
            'locked' => false,
        );
    }

    private function writeConfig(array $config): void
    {
        file_put_contents($this->engine . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n");
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/ConfirmationStoreTest.php
```

Expected: FAIL because `ConfirmationStore` does not exist.

- [x] **Step 3: Add confirmation store**

Create `super-sheep-copy/installer/restore-engine/ConfirmationStore.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class ConfirmationStore
{
    public function isConfirmed(array $config): bool
    {
        return !empty($config['restore_confirmed']);
    }

    /**
     * @param array<string,mixed> $config
     */
    public function confirm(string $engine_dir, array $config, string $typed_phrase, bool $checkbox_checked, bool $has_blocking_errors): bool
    {
        if (!$checkbox_checked || $typed_phrase !== 'RESTORE' || $has_blocking_errors) {
            return false;
        }

        $config['restore_confirmed'] = true;
        $config['restore_confirmed_at'] = gmdate('c');

        $path = rtrim($engine_dir, '/\\') . '/config.php';
        $contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        return file_put_contents($path, $contents) !== false;
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/ConfirmationStoreTest.php
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
git add super-sheep-copy/installer/restore-engine/ConfirmationStore.php super-sheep-copy/tests/Unit/ConfirmationStoreTest.php
git commit -m "feat: store installer restore confirmation"
```

Expected: commit succeeds.

---

### Task 4: Bootstrap Preflight and Confirmation UI

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Modify: `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

- [x] **Step 1: Write the failing tests**

Modify `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`.

Add `$_POST` and `$_SERVER` reset in `setUp()`:

```php
$_POST = array();
$_SERVER = array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php');
```

Update `tearDown()` to remove the WordPress root created by tests if needed. The current engine directory can become `$this->root . '/ssc-restore-engine'` so tests can create a sibling `wp-config.php`.

Add tests:

```php
/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
public function testValidTokenShowsDestinationPreviewAndPreflightChecks(): void
{
    if (!class_exists(ZipArchive::class)) {
        self::markTestSkipped('ZipArchive is not available.');
    }

    $this->writeWpConfig();
    $this->writeConfig('plain-token', $this->validArchive());
    $_GET['token'] = 'plain-token';
    $_SERVER = array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php');

    ob_start();
    \SuperSheepCopyInstaller\Bootstrap::run();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Restore Preview', $html);
    self::assertStringContainsString('https://source.example', $html);
    self::assertStringContainsString('http://destination.example', $html);
    self::assertStringContainsString('Preflight Checks', $html);
    self::assertStringContainsString('Type RESTORE to confirm', $html);
}

/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
public function testConfirmationPostWithPhraseShowsConfirmedStatus(): void
{
    if (!class_exists(ZipArchive::class)) {
        self::markTestSkipped('ZipArchive is not available.');
    }

    $this->writeWpConfig();
    $this->writeConfig('plain-token', $this->validArchive());
    $_GET['token'] = 'plain-token';
    $_POST = array('token' => 'plain-token', 'confirm_restore' => '1', 'restore_confirmation' => 'RESTORE', 'restore_warning_accepted' => '1');
    $_SERVER = array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php');

    ob_start();
    \SuperSheepCopyInstaller\Bootstrap::run();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Restore confirmation recorded', $html);
    $config = require $this->engine . '/config.php';
    self::assertTrue($config['restore_confirmed']);
}

/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
public function testConfirmationPostWithoutPhraseRemainsUnconfirmed(): void
{
    if (!class_exists(ZipArchive::class)) {
        self::markTestSkipped('ZipArchive is not available.');
    }

    $this->writeWpConfig();
    $this->writeConfig('plain-token', $this->validArchive());
    $_GET['token'] = 'plain-token';
    $_POST = array('token' => 'plain-token', 'confirm_restore' => '1', 'restore_warning_accepted' => '1');
    $_SERVER = array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php');

    ob_start();
    \SuperSheepCopyInstaller\Bootstrap::run();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Confirmation was not accepted', $html);
    $config = require $this->engine . '/config.php';
    self::assertArrayNotHasKey('restore_confirmed', $config);
}
```

Add helper:

```php
private function writeWpConfig(): void
{
    file_put_contents(dirname($this->engine) . '/wp-config.php', "<?php\n"
        . "define('DB_NAME', 'wordpress');\n"
        . "define('DB_USER', 'dbuser');\n"
        . "define('DB_PASSWORD', 'secret');\n"
        . "define('DB_HOST', 'localhost');\n"
        . "\$table_prefix = 'wp_';\n");
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: FAIL because `Bootstrap` does not render preflight preview or process confirmation.

- [x] **Step 3: Update bootstrap dependencies**

Modify `super-sheep-copy/installer/restore-engine/Bootstrap.php` and add:

```php
require_once __DIR__ . '/DestinationDetector.php';
require_once __DIR__ . '/WpConfigReader.php';
require_once __DIR__ . '/PreflightChecker.php';
require_once __DIR__ . '/ConfirmationStore.php';
```

- [x] **Step 4: Add bootstrap request helpers**

Inside `Bootstrap`, add:

```php
private static function requestMethod(): string
{
    return isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : ($_POST === array() ? 'GET' : 'POST');
}

private static function requestToken(Security $security): string
{
    $token = $security->requestToken();
    if ($token !== '') {
        return $token;
    }

    return isset($_POST['token']) && is_string($_POST['token']) ? (string) $_POST['token'] : '';
}

private static function postString(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? (string) $_POST[$key] : '';
}
```

- [x] **Step 5: Replace token-verified branch with preflight and confirmation flow**

In `run()`, after token verification and before rendering archive metadata, instantiate:

```php
$preflight = new PreflightChecker(new EnvironmentChecker(), new DestinationDetector(), new WpConfigReader(), new ArchiveValidator());
$checks = $preflight->run($config, $_SERVER, $engine_dir);
$has_blocking_errors = PreflightChecker::hasBlockingErrors($checks);
$confirmation = new ConfirmationStore();
$confirmation_message = '';
if (self::requestMethod() === 'POST' && isset($_POST['confirm_restore'])) {
    $confirmed = $confirmation->confirm(
        $engine_dir,
        $config,
        self::postString('restore_confirmation'),
        isset($_POST['restore_warning_accepted']),
        $has_blocking_errors
    );
    if ($confirmed) {
        $config = self::loadConfig($engine_dir);
        $confirmation_message = 'Restore confirmation recorded.';
    } else {
        $confirmation_message = 'Confirmation was not accepted.';
    }
}
```

Keep archive validation for manifest display, then render:

```php
$destination_url = (new DestinationDetector())->detect($_SERVER);
$confirmed = $confirmation->isConfirmed($config);
```

Add output sections:

```php
echo '<h2>Restore Preview</h2>';
echo '<div class="status ok"><strong>Source:</strong> ' . htmlspecialchars((string) ($manifest['source_site_url'] ?? $config['source_site_url'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
echo '<div class="status ok"><strong>Destination:</strong> ' . htmlspecialchars($destination_url, ENT_QUOTES, 'UTF-8') . '</div>';

echo '<h2>Preflight Checks</h2>';
foreach ($checks as $check) {
    $class = $check['status'] === 'error' ? 'error' : ($check['status'] === 'ok' ? 'ok' : 'warning');
    echo '<div class="status ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"><strong>' . htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($check['value'], ENT_QUOTES, 'UTF-8');
    if ($check['message'] !== '') {
        echo '<br><span>' . htmlspecialchars($check['message'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    echo '</div>';
}

if ($confirmation_message !== '') {
    echo '<div class="status ' . ($confirmed ? 'ok' : 'warning') . '">' . htmlspecialchars($confirmation_message, ENT_QUOTES, 'UTF-8') . '</div>';
}

echo '<h2>Restore Confirmation</h2>';
if ($confirmed) {
    echo '<div class="status ok">Restore confirmation recorded. Restore execution is not implemented yet.</div>';
} elseif ($has_blocking_errors) {
    echo '<div class="status error">Resolve blocking preflight errors before confirming restore.</div>';
} else {
    echo '<div class="status warning">This confirmation gate is required before future restore execution. No destructive action runs in this milestone.</div>';
    echo '<form method="post">';
    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    echo '<input type="hidden" name="confirm_restore" value="1">';
    echo '<p><label><input type="checkbox" name="restore_warning_accepted" value="1"> I understand this restore will replace the destination site in a later step.</label></p>';
    echo '<p><label>Type RESTORE to confirm <input type="text" name="restore_confirmation" autocomplete="off"></label></p>';
    echo '<p><button type="submit">Confirm Restore Intent</button></p>';
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
git commit -m "feat: gate installer restore confirmation"
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

Expected: direct request/global access is limited to:

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
git add docs/superpowers/plans/2026-05-18-installer-preflight-confirmation.md
git commit -m "docs: mark installer preflight confirmation complete"
```

Expected: commit succeeds after Task 5 checkboxes are marked complete.

---

## Self-Review

- Spec coverage: This plan covers destination URL detection, read-only `wp-config.php` credential detection, preflight check aggregation, blocking confirmation errors, config-backed confirmation state, bootstrap rendering, focused tests, full suite, lint, request-global scan, and git status.
- Scope exclusions remain excluded: no database connection testing, import, archive extraction, file restore, URL replacement execution, rollback backup, maintenance mode, installer lock/delete, cache clearing, health checks, or manual database credential form.
- Type consistency: `DestinationDetector::detect(array): string`, `WpConfigReader::readDatabaseConfig(string): array`, `PreflightChecker::run(array,array,string): array`, `PreflightChecker::hasBlockingErrors(array): bool`, and `ConfirmationStore::confirm(string,array,string,bool,bool): bool` match the spec.
- Gap scan: every task includes concrete test, implementation, verification, and commit steps.
