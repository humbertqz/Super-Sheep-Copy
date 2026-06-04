# Installer Preparation and Token Protection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prepare a WordPress-root standalone installer protected by a one-time token after a restore archive has been uploaded and staged.

**Architecture:** Keep destructive restore work outside WordPress and outside this slice. Add a plugin-side installer preparation service that deploys `installer.php` plus `ssc-restore-engine/`, writes token-hash config, and updates the restore job. Add installer-local token verification and archive validation so the standalone installer independently verifies the staged archive before showing metadata.

**Tech Stack:** PHP 7.4-compatible code, WordPress admin APIs, PHPUnit, `ZipArchive`, existing project autoload/test bootstrap.

---

## Spec

This plan implements:

`docs/superpowers/specs/2026-05-16-installer-preparation-token-design.md`

## Scope

Included:

- Plugin-side installer preparation manager and result object.
- Root deployment to `ABSPATH/installer.php`.
- Engine deployment to `ABSPATH/ssc-restore-engine/`.
- Installer config with hashed token and staged archive path.
- Restore page flow for preparing and launching the installer.
- Installer-local token validation and package validation.
- Non-destructive installer metadata page after token verification.

Excluded:

- Database import.
- File extraction or replacement.
- URL replacement during restore.
- Rollback backup creation.
- Maintenance mode.
- Installer lock/delete after restore completion.

## File Structure

- Create `super-sheep-copy/src/Restore/InstallerPreparationResult.php`
  - Immutable value object for restore job ID, launch URL, plaintext token, installer URL, engine dir basename, staged archive basename, and source URLs.
- Create `super-sheep-copy/src/Restore/InstallerPreparationManagerInterface.php`
  - Defines `prepare(string $restore_job_id): InstallerPreparationResult`.
- Create `super-sheep-copy/src/Restore/InstallerPreparationManager.php`
  - Loads completed restore job, validates staged archive basename, deploys installer files, writes config, updates job payload.
- Modify `super-sheep-copy/src/Admin/RestorePage.php`
  - Inject installer preparation manager and job repository.
  - Redirect upload success with restore job ID.
  - Handle `prepare_installer` POST action.
  - Provide prepared job data and launch token to template.
- Modify `super-sheep-copy/templates/restore-page.php`
  - Show prepared archive metadata.
  - Render installer preparation form.
  - Show installer launch URL only when token is present.
- Modify `super-sheep-copy/src/Admin/AdminMenu.php`
  - Pass installer preparation manager and job repository to `RestorePage`.
- Modify `super-sheep-copy/src/Plugin.php`
  - Construct `InstallerPreparationManager`.
- Create `super-sheep-copy/installer/restore-engine/ArchiveValidationResult.php`
  - Installer-local validation result.
- Create `super-sheep-copy/installer/restore-engine/ArchiveValidator.php`
  - Installer-local ZIP structure validator.
- Modify `super-sheep-copy/installer/restore-engine/Security.php`
  - Verify provided token against config hash with `password_verify()`.
- Modify `super-sheep-copy/installer/restore-engine/Bootstrap.php`
  - Load config, enforce token, validate staged archive, render non-destructive metadata.
- Modify `super-sheep-copy/tests/Unit/RestorePageTest.php`
  - Cover restore job ID redirect, installer form, installer success/failure redirects.
- Create `super-sheep-copy/tests/Unit/InstallerPreparationManagerTest.php`
  - Cover root deployment, config, safe payload updates, invalid jobs, unsafe archive basenames.
- Create `super-sheep-copy/tests/Unit/InstallerSecurityTest.php`
  - Cover valid, missing, and invalid token verification.
- Create `super-sheep-copy/tests/Unit/InstallerArchiveValidatorTest.php`
  - Cover installer-local package validation.
- Create `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`
  - Cover output for invalid and valid tokens.

---

### Task 1: Plugin Installer Preparation Manager

**Files:**
- Create: `super-sheep-copy/src/Restore/InstallerPreparationResult.php`
- Create: `super-sheep-copy/src/Restore/InstallerPreparationManagerInterface.php`
- Create: `super-sheep-copy/src/Restore/InstallerPreparationManager.php`
- Test: `super-sheep-copy/tests/Unit/InstallerPreparationManagerTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/InstallerPreparationManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Restore\InstallerPreparationManager;

final class InstallerPreparationManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-installer-prep-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/source/restore-engine', 0777, true);
        mkdir($this->root . '/staging', 0777, true);
        mkdir($this->root . '/wp-root', 0777, true);
        file_put_contents($this->root . '/source/installer.php', "<?php\nrequire_once __DIR__ . '/restore-engine/Bootstrap.php';\n");
        file_put_contents($this->root . '/source/restore-engine/Bootstrap.php', "<?php\n");
        file_put_contents($this->root . '/source/restore-engine/Security.php', "<?php\n");
        file_put_contents($this->root . '/staging/restore-123.zip', 'zip bytes');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPreparesRootInstallerAndConfigForCompletedRestoreJob(): void
    {
        $jobs = new MemoryInstallerJobRepository();
        $jobs->save(new Job('restore-123', 'restore', Job::COMPLETED, array(
            'staged_archive' => 'restore-123.zip',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
            'database_entry_count' => 2,
            'archive_entry_count' => 5,
        )));

        $manager = new InstallerPreparationManager(
            $this->root . '/source',
            $this->root . '/wp-root',
            $this->root . '/staging',
            'https://destination.example',
            $jobs
        );

        $result = $manager->prepare('restore-123');

        self::assertSame('restore-123', $result->jobId());
        self::assertSame('https://destination.example/installer.php', $result->installerUrl());
        self::assertStringStartsWith('https://destination.example/installer.php?token=', $result->launchUrl());
        self::assertNotSame('', $result->token());
        self::assertSame('ssc-restore-engine', $result->engineDirectoryBasename());
        self::assertSame('restore-123.zip', $result->stagedArchiveBasename());
        self::assertFileExists($this->root . '/wp-root/installer.php');
        self::assertFileExists($this->root . '/wp-root/ssc-restore-engine/Bootstrap.php');
        self::assertFileExists($this->root . '/wp-root/ssc-restore-engine/config.php');

        $installer = file_get_contents($this->root . '/wp-root/installer.php');
        self::assertIsString($installer);
        self::assertStringContainsString("ssc-restore-engine/Bootstrap.php", $installer);

        $config = require $this->root . '/wp-root/ssc-restore-engine/config.php';
        self::assertSame('restore-123', $config['restore_job_id']);
        self::assertSame($this->root . '/staging/restore-123.zip', $config['staged_archive_path']);
        self::assertSame('restore-123.zip', $config['staged_archive_basename']);
        self::assertSame('https://source.example', $config['source_site_url']);
        self::assertSame('https://source.example/home', $config['source_home_url']);
        self::assertFalse($config['locked']);
        self::assertTrue(password_verify($result->token(), $config['token_hash']));

        $updated = $jobs->find('restore-123');
        self::assertInstanceOf(Job::class, $updated);
        self::assertTrue($updated->payload()['installer_prepared']);
        self::assertSame('https://destination.example/installer.php', $updated->payload()['installer_url']);
        self::assertSame('ssc-restore-engine', $updated->payload()['installer_engine_dir']);
        self::assertArrayNotHasKey('installer_token', $updated->payload());
        self::assertArrayNotHasKey('token_hash', $updated->payload());
    }

    public function testRejectsUnsafeStagedArchiveBasename(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore archive basename is invalid.');

        $jobs = new MemoryInstallerJobRepository();
        $jobs->save(new Job('restore-123', 'restore', Job::COMPLETED, array('staged_archive' => '../backup.zip')));

        $manager = new InstallerPreparationManager($this->root . '/source', $this->root . '/wp-root', $this->root . '/staging', 'https://destination.example', $jobs);
        $manager->prepare('restore-123');
    }

    public function testRejectsIncompleteRestoreJob(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore job is not ready for installer preparation.');

        $jobs = new MemoryInstallerJobRepository();
        $jobs->save(new Job('restore-123', 'restore', Job::VALIDATING_RESTORE, array('staged_archive' => 'restore-123.zip')));

        $manager = new InstallerPreparationManager($this->root . '/source', $this->root . '/wp-root', $this->root . '/staging', 'https://destination.example', $jobs);
        $manager->prepare('restore-123');
    }

    public function testRejectsMissingRestoreJob(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore job was not found.');

        $manager = new InstallerPreparationManager($this->root . '/source', $this->root . '/wp-root', $this->root . '/staging', 'https://destination.example', new MemoryInstallerJobRepository());
        $manager->prepare('missing');
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

final class MemoryInstallerJobRepository implements JobRepositoryInterface
{
    /** @var array<string, Job> */
    private array $jobs = array();

    public function save(Job $job): void
    {
        $this->jobs[$job->id()] = $job;
    }

    public function find(string $id): ?Job
    {
        return $this->jobs[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->jobs);
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerPreparationManagerTest.php
```

Expected: FAIL because `InstallerPreparationManager` does not exist.

- [x] **Step 3: Add result object**

Create `super-sheep-copy/src/Restore/InstallerPreparationResult.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

final class InstallerPreparationResult
{
    private string $job_id;
    private string $installer_url;
    private string $launch_url;
    private string $token;
    private string $engine_directory_basename;
    private string $staged_archive_basename;
    private string $source_site_url;
    private string $source_home_url;

    public function __construct(
        string $job_id,
        string $installer_url,
        string $launch_url,
        string $token,
        string $engine_directory_basename,
        string $staged_archive_basename,
        string $source_site_url,
        string $source_home_url
    ) {
        $this->job_id = $job_id;
        $this->installer_url = $installer_url;
        $this->launch_url = $launch_url;
        $this->token = $token;
        $this->engine_directory_basename = $engine_directory_basename;
        $this->staged_archive_basename = $staged_archive_basename;
        $this->source_site_url = $source_site_url;
        $this->source_home_url = $source_home_url;
    }

    public function jobId(): string
    {
        return $this->job_id;
    }

    public function installerUrl(): string
    {
        return $this->installer_url;
    }

    public function launchUrl(): string
    {
        return $this->launch_url;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function engineDirectoryBasename(): string
    {
        return $this->engine_directory_basename;
    }

    public function stagedArchiveBasename(): string
    {
        return $this->staged_archive_basename;
    }

    public function sourceSiteUrl(): string
    {
        return $this->source_site_url;
    }

    public function sourceHomeUrl(): string
    {
        return $this->source_home_url;
    }
}
```

- [x] **Step 4: Add manager interface**

Create `super-sheep-copy/src/Restore/InstallerPreparationManagerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

interface InstallerPreparationManagerInterface
{
    public function prepare(string $restore_job_id): InstallerPreparationResult;
}
```

- [x] **Step 5: Add manager implementation**

Create `super-sheep-copy/src/Restore/InstallerPreparationManager.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

use RuntimeException;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class InstallerPreparationManager implements InstallerPreparationManagerInterface
{
    private const ENGINE_DIR = 'ssc-restore-engine';

    private string $source_installer_directory;
    private string $wordpress_root;
    private string $restore_staging_directory;
    private string $site_url;
    private JobRepositoryInterface $jobs;

    public function __construct(
        string $source_installer_directory,
        string $wordpress_root,
        string $restore_staging_directory,
        string $site_url,
        JobRepositoryInterface $jobs
    ) {
        $this->source_installer_directory = rtrim($source_installer_directory, '/\\');
        $this->wordpress_root = rtrim($wordpress_root, '/\\');
        $this->restore_staging_directory = rtrim($restore_staging_directory, '/\\');
        $this->site_url = rtrim($site_url, '/');
        $this->jobs = $jobs;
    }

    public function prepare(string $restore_job_id): InstallerPreparationResult
    {
        $job = $this->jobs->find($restore_job_id);
        if (!$job instanceof Job) {
            throw new RuntimeException('Restore job was not found.');
        }

        if ($job->type() !== 'restore' || $job->state() !== Job::COMPLETED) {
            throw new RuntimeException('Restore job is not ready for installer preparation.');
        }

        $payload = $job->payload();
        $staged_archive = isset($payload['staged_archive']) ? (string) $payload['staged_archive'] : '';
        if (!$this->isSafeBasename($staged_archive)) {
            throw new RuntimeException('Restore archive basename is invalid.');
        }

        $archive_path = $this->restore_staging_directory . '/' . $staged_archive;
        if (!is_readable($archive_path)) {
            throw new RuntimeException('Staged restore archive is not readable.');
        }

        $this->deployInstaller();

        $token = bin2hex(random_bytes(32));
        $installer_url = $this->site_url . '/installer.php';
        $launch_url = $installer_url . '?token=' . rawurlencode($token);
        $source_site_url = isset($payload['source_site_url']) ? (string) $payload['source_site_url'] : '';
        $source_home_url = isset($payload['source_home_url']) ? (string) $payload['source_home_url'] : '';
        $prepared_at = gmdate('c');

        $this->writeConfig(array(
            'restore_job_id' => $job->id(),
            'staged_archive_path' => $archive_path,
            'staged_archive_basename' => $staged_archive,
            'source_site_url' => $source_site_url,
            'source_home_url' => $source_home_url,
            'token_hash' => password_hash($token, PASSWORD_DEFAULT),
            'token_created_at' => $prepared_at,
            'locked' => false,
        ));

        $payload['installer_prepared'] = true;
        $payload['installer_url'] = $installer_url;
        $payload['installer_engine_dir'] = self::ENGINE_DIR;
        $payload['installer_prepared_at'] = $prepared_at;
        unset($payload['installer_token'], $payload['token_hash']);
        $this->jobs->save(new Job($job->id(), $job->type(), $job->state(), $payload));

        return new InstallerPreparationResult(
            $job->id(),
            $installer_url,
            $launch_url,
            $token,
            self::ENGINE_DIR,
            $staged_archive,
            $source_site_url,
            $source_home_url
        );
    }

    private function deployInstaller(): void
    {
        $source_installer = $this->source_installer_directory . '/installer.php';
        $source_engine = $this->source_installer_directory . '/restore-engine';
        if (!is_readable($source_installer) || !is_dir($source_engine)) {
            throw new RuntimeException('Installer source files are missing.');
        }

        if (!is_dir($this->wordpress_root) && !mkdir($this->wordpress_root, 0777, true) && !is_dir($this->wordpress_root)) {
            throw new RuntimeException('Unable to create WordPress root directory.');
        }

        $target_installer = $this->wordpress_root . '/installer.php';
        $target_engine = $this->wordpress_root . '/' . self::ENGINE_DIR;
        $this->ensureDirectory($target_engine);
        $this->copyDirectory($source_engine, $target_engine);

        $contents = file_get_contents($source_installer);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read installer source.');
        }

        $contents = str_replace('/restore-engine/Bootstrap.php', '/' . self::ENGINE_DIR . '/Bootstrap.php', $contents);
        if (file_put_contents($target_installer, $contents) === false) {
            throw new RuntimeException('Unable to write installer file.');
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    private function writeConfig(array $config): void
    {
        $path = $this->wordpress_root . '/' . self::ENGINE_DIR . '/config.php';
        $contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write installer config.');
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->ensureDirectory($target);
        $items = array_diff(scandir($source) ?: array(), array('.', '..'));
        foreach ($items as $item) {
            $source_path = $source . '/' . $item;
            $target_path = $target . '/' . $item;
            if (is_dir($source_path)) {
                $this->copyDirectory($source_path, $target_path);
                continue;
            }
            if (!copy($source_path, $target_path)) {
                throw new RuntimeException('Unable to copy installer engine file.');
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create installer directory.');
        }
    }

    private function isSafeBasename(string $basename): bool
    {
        return $basename !== ''
            && basename($basename) === $basename
            && strpos($basename, "\0") === false
            && strtolower(substr($basename, -4)) === '.zip';
    }
}
```

- [x] **Step 6: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerPreparationManagerTest.php
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
git add super-sheep-copy/src/Restore/InstallerPreparationResult.php super-sheep-copy/src/Restore/InstallerPreparationManagerInterface.php super-sheep-copy/src/Restore/InstallerPreparationManager.php super-sheep-copy/tests/Unit/InstallerPreparationManagerTest.php
git commit -m "feat: prepare standalone restore installer"
```

Expected: commit succeeds.

---

### Task 2: Restore Page Installer Preparation Flow

**Files:**
- Modify: `super-sheep-copy/src/Admin/RestorePage.php`
- Modify: `super-sheep-copy/templates/restore-page.php`
- Modify: `super-sheep-copy/src/Admin/AdminMenu.php`
- Modify: `super-sheep-copy/src/Plugin.php`
- Test: `super-sheep-copy/tests/Unit/RestorePageTest.php`

- [x] **Step 1: Write the failing test**

Modify `super-sheep-copy/tests/Unit/RestorePageTest.php`:

Add imports:

```php
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Restore\InstallerPreparationManagerInterface;
use SuperSheepCopy\Restore\InstallerPreparationResult;
```

Update each `new RestorePage(...)` call to pass a job repository and installer manager after the restore preparation manager:

```php
new RestorePageJobRepository(),
new RestorePageInstallerPreparationManager()
```

Update `testPostPreparesRestoreAndRedirectsWithSuccess()` expected redirect:

```php
self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-restore&super_sheep_copy_status=restore_prepared&super_sheep_copy_restore_job_id=restore-123', $GLOBALS['ssc_test_redirect']);
```

Add tests:

```php
public function testPreparedRestoreViewShowsInstallerPreparationForm(): void
{
    $_GET['super_sheep_copy_status'] = 'restore_prepared';
    $_GET['super_sheep_copy_restore_job_id'] = 'restore-123';

    $jobs = new RestorePageJobRepository();
    $jobs->save(new Job('restore-123', 'restore', Job::COMPLETED, array(
        'staged_archive' => 'restore-123.zip',
        'source_site_url' => 'https://source.example',
        'source_home_url' => 'https://source.example/home',
        'database_entry_count' => 2,
        'archive_entry_count' => 5,
    )));

    $page = new RestorePage(
        new Capability(),
        new Nonce(),
        new RestorePageEnvironmentChecker(),
        new RestorePageLogger(),
        new RestorePagePreparationManager(),
        $jobs,
        new RestorePageInstallerPreparationManager()
    );

    ob_start();
    $page->render();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('https://source.example', $html);
    self::assertStringContainsString('https://source.example/home', $html);
    self::assertStringContainsString('name="super_sheep_copy_restore_job_id"', $html);
    self::assertStringContainsString('value="restore-123"', $html);
    self::assertStringContainsString('value="prepare_installer"', $html);
    self::assertStringContainsString('Prepare Standalone Installer', $html);
}

public function testPostPreparesInstallerAndRedirectsWithToken(): void
{
    $_POST['super_sheep_copy_action'] = 'prepare_installer';
    $_POST['super_sheep_copy_restore_job_id'] = 'restore-123';
    $_REQUEST['super_sheep_copy_action'] = 'prepare_installer';
    $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

    $installer = new RestorePageInstallerPreparationManager();
    $page = new RestorePage(
        new Capability(),
        new Nonce(),
        new RestorePageEnvironmentChecker(),
        new RestorePageLogger(),
        new RestorePagePreparationManager(),
        new RestorePageJobRepository(),
        $installer
    );

    $page->render();

    self::assertSame('restore-123', $installer->jobId());
    self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-restore&super_sheep_copy_status=installer_prepared&super_sheep_copy_restore_job_id=restore-123&super_sheep_copy_installer_token=plain-token', $GLOBALS['ssc_test_redirect']);
}

public function testPostRedirectsWithFailureWhenInstallerPreparationFails(): void
{
    $_POST['super_sheep_copy_action'] = 'prepare_installer';
    $_POST['super_sheep_copy_restore_job_id'] = 'restore-123';
    $_REQUEST['super_sheep_copy_action'] = 'prepare_installer';
    $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

    $page = new RestorePage(
        new Capability(),
        new Nonce(),
        new RestorePageEnvironmentChecker(),
        new RestorePageLogger(),
        new RestorePagePreparationManager(),
        new RestorePageJobRepository(),
        new RestorePageInstallerPreparationManager(true)
    );

    $page->render();

    self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-restore&super_sheep_copy_status=installer_failed', $GLOBALS['ssc_test_redirect']);
}

public function testInstallerPreparedViewShowsLaunchLinkWithToken(): void
{
    $_GET['super_sheep_copy_status'] = 'installer_prepared';
    $_GET['super_sheep_copy_restore_job_id'] = 'restore-123';
    $_GET['super_sheep_copy_installer_token'] = 'plain-token';

    $jobs = new RestorePageJobRepository();
    $jobs->save(new Job('restore-123', 'restore', Job::COMPLETED, array(
        'staged_archive' => 'restore-123.zip',
        'source_site_url' => 'https://source.example',
        'source_home_url' => 'https://source.example/home',
        'installer_url' => 'https://example.com/installer.php',
    )));

    $page = new RestorePage(
        new Capability(),
        new Nonce(),
        new RestorePageEnvironmentChecker(),
        new RestorePageLogger(),
        new RestorePagePreparationManager(),
        $jobs,
        new RestorePageInstallerPreparationManager()
    );

    ob_start();
    $page->render();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('https://example.com/installer.php?token=plain-token', $html);
}
```

Add helper classes at the bottom:

```php
final class RestorePageInstallerPreparationManager implements InstallerPreparationManagerInterface
{
    private bool $throw;
    private ?string $job_id = null;

    public function __construct(bool $throw = false)
    {
        $this->throw = $throw;
    }

    public function prepare(string $restore_job_id): InstallerPreparationResult
    {
        if ($this->throw) {
            throw new \RuntimeException('installer failed');
        }

        $this->job_id = $restore_job_id;

        return new InstallerPreparationResult(
            $restore_job_id,
            'https://example.com/installer.php',
            'https://example.com/installer.php?token=plain-token',
            'plain-token',
            'ssc-restore-engine',
            'restore-123.zip',
            'https://source.example',
            'https://source.example/home'
        );
    }

    public function jobId(): ?string
    {
        return $this->job_id;
    }
}

final class RestorePageJobRepository implements JobRepositoryInterface
{
    /** @var array<string, Job> */
    private array $jobs = array();

    public function save(Job $job): void
    {
        $this->jobs[$job->id()] = $job;
    }

    public function find(string $id): ?Job
    {
        return $this->jobs[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->jobs);
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RestorePageTest.php
```

Expected: FAIL because `RestorePage` constructor and installer action do not exist yet.

- [x] **Step 3: Update `RestorePage`**

Modify `super-sheep-copy/src/Admin/RestorePage.php`:

Add imports:

```php
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Restore\InstallerPreparationManagerInterface;
```

Add constants:

```php
private const ACTION_PREPARE_INSTALLER = 'prepare_installer';
private const JOB_ID_FIELD = 'super_sheep_copy_restore_job_id';
private const INSTALLER_TOKEN_FIELD = 'super_sheep_copy_installer_token';
```

Add properties:

```php
private JobRepositoryInterface $jobs;
private InstallerPreparationManagerInterface $installer_preparation;
```

Update constructor to accept and store the new dependencies after `$restore_preparation`:

```php
JobRepositoryInterface $jobs,
InstallerPreparationManagerInterface $installer_preparation
```

Update `render()` so it handles both POST actions before rendering:

```php
if ($this->handlePrepareRestore() || $this->handlePrepareInstaller()) {
    return;
}

$environment = $this->environment_checker->check();
$status = $this->status();
$restore_job = $this->restoreJob();
$installer_token = $this->installerToken();
$installer_launch_url = $this->installerLaunchUrl($restore_job, $installer_token);
$nonce_field = $this->nonce->field();
include SUPER_SHEEP_COPY_DIR . 'templates/restore-page.php';
```

Update `handlePrepareRestore()` success branch:

```php
$result = $this->restore_preparation->prepare($upload);
$this->redirect('restore_prepared', array(self::JOB_ID_FIELD => $result->jobId()));
```

Add installer handler:

```php
private function handlePrepareInstaller(): bool
{
    if (!$this->isAction(self::ACTION_PREPARE_INSTALLER)) {
        return false;
    }

    $this->capability->assertManageBackups();
    $this->nonce->verifyRequest();

    try {
        $job_id = isset($_POST[self::JOB_ID_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::JOB_ID_FIELD])) : '';
        $result = $this->installer_preparation->prepare($job_id);
        $this->redirect('installer_prepared', array(
            self::JOB_ID_FIELD => $result->jobId(),
            self::INSTALLER_TOKEN_FIELD => $result->token(),
        ));
    } catch (Throwable $throwable) {
        $this->logger->warning('Installer preparation failed.');
        $this->redirect('installer_failed');
    }

    return true;
}
```

Replace `isPrepareRestoreRequest()` with:

```php
private function isPrepareRestoreRequest(): bool
{
    return $this->isAction(self::ACTION_PREPARE_RESTORE);
}

private function isAction(string $expected): bool
{
    $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

    return $action === $expected;
}
```

Update `redirect()` signature:

```php
/**
 * @param array<string,string> $extra
 */
private function redirect(string $status, array $extra = array()): void
{
    wp_safe_redirect(add_query_arg(
        array_merge(
            array(
                'page' => 'super-sheep-copy-restore',
                self::STATUS_FIELD => $status,
            ),
            $extra
        ),
        admin_url('admin.php')
    ));
}
```

Add helpers:

```php
private function restoreJob(): ?Job
{
    $job_id = isset($_GET[self::JOB_ID_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::JOB_ID_FIELD])) : '';
    if ($job_id === '') {
        return null;
    }

    $job = $this->jobs->find($job_id);
    return $job instanceof Job && $job->type() === 'restore' ? $job : null;
}

private function installerToken(): string
{
    return isset($_GET[self::INSTALLER_TOKEN_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::INSTALLER_TOKEN_FIELD])) : '';
}

private function installerLaunchUrl(?Job $restore_job, string $token): string
{
    if (!$restore_job instanceof Job || $token === '') {
        return '';
    }

    $payload = $restore_job->payload();
    $installer_url = isset($payload['installer_url']) ? (string) $payload['installer_url'] : site_url('/installer.php');

    return add_query_arg(array('token' => $token), $installer_url);
}
```

- [x] **Step 4: Update restore template**

Modify `super-sheep-copy/templates/restore-page.php`.

After existing success/failure notices, add installer failure/prepared notices:

```php
<?php if ($status === 'installer_prepared') : ?>
    <div class="notice notice-success"><p><?php echo esc_html__('Standalone installer prepared. Use the launch link below before leaving this page.', 'super-sheep-copy'); ?></p></div>
<?php elseif ($status === 'installer_failed') : ?>
    <div class="notice notice-error"><p><?php echo esc_html__('Installer preparation failed. Check server permissions and try again.', 'super-sheep-copy'); ?></p></div>
<?php endif; ?>
```

After the upload form, add prepared-job panel:

```php
<?php if ($restore_job instanceof \SuperSheepCopy\Jobs\Job) : ?>
    <?php $payload = $restore_job->payload(); ?>
    <h2><?php echo esc_html__('Prepared Restore Archive', 'super-sheep-copy'); ?></h2>
    <table class="widefat striped">
        <tbody>
            <tr>
                <th scope="row"><?php echo esc_html__('Source site URL', 'super-sheep-copy'); ?></th>
                <td><?php echo esc_html(isset($payload['source_site_url']) ? (string) $payload['source_site_url'] : ''); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Source home URL', 'super-sheep-copy'); ?></th>
                <td><?php echo esc_html(isset($payload['source_home_url']) ? (string) $payload['source_home_url'] : ''); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Archive entries', 'super-sheep-copy'); ?></th>
                <td><?php echo esc_html((string) (isset($payload['archive_entry_count']) ? (int) $payload['archive_entry_count'] : 0)); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Database entries', 'super-sheep-copy'); ?></th>
                <td><?php echo esc_html((string) (isset($payload['database_entry_count']) ? (int) $payload['database_entry_count'] : 0)); ?></td>
            </tr>
        </tbody>
    </table>

    <p><?php echo esc_html__('Preparing the installer does not restore, import, extract, or replace site data yet.', 'super-sheep-copy'); ?></p>

    <form method="post">
        <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <input type="hidden" name="super_sheep_copy_action" value="prepare_installer" />
        <input type="hidden" name="super_sheep_copy_restore_job_id" value="<?php echo esc_attr($restore_job->id()); ?>" />
        <button type="submit" class="button button-primary"><?php echo esc_html__('Prepare Standalone Installer', 'super-sheep-copy'); ?></button>
    </form>
<?php endif; ?>

<?php if ($installer_launch_url !== '') : ?>
    <h2><?php echo esc_html__('Installer Launch Link', 'super-sheep-copy'); ?></h2>
    <p><a class="button button-primary" href="<?php echo esc_attr($installer_launch_url); ?>"><?php echo esc_html__('Open Standalone Installer', 'super-sheep-copy'); ?></a></p>
    <p><code><?php echo esc_html($installer_launch_url); ?></code></p>
<?php endif; ?>
```

- [x] **Step 5: Wire admin dependencies**

Modify `super-sheep-copy/src/Admin/AdminMenu.php`:

Add imports:

```php
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Restore\InstallerPreparationManagerInterface;
```

Add properties:

```php
private JobRepositoryInterface $jobs;
private InstallerPreparationManagerInterface $installer_preparation;
```

Add constructor parameters after restore preparation:

```php
JobRepositoryInterface $jobs,
InstallerPreparationManagerInterface $installer_preparation
```

Store them and update `restorePage()`:

```php
new RestorePage(
    $this->capability,
    $this->nonce,
    $this->environment_checker,
    $this->logger,
    $this->restore_preparation,
    $this->jobs,
    $this->installer_preparation
)
```

Modify `super-sheep-copy/src/Plugin.php`:

Add import:

```php
use SuperSheepCopy\Restore\InstallerPreparationManager;
```

Update `new AdminMenu(...)` arguments after `RestorePreparationManager`:

```php
new RestorePreparationManager(
    new ArchiveValidator(),
    $jobs,
    trailingslashit(self::backupDirectory()) . 'restore'
),
$jobs,
new InstallerPreparationManager(
    SUPER_SHEEP_COPY_DIR . 'installer',
    ABSPATH,
    trailingslashit(self::backupDirectory()) . 'restore',
    site_url(),
    $jobs
)
```

- [x] **Step 6: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/RestorePageTest.php
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
git add super-sheep-copy/src/Admin/RestorePage.php super-sheep-copy/templates/restore-page.php super-sheep-copy/src/Admin/AdminMenu.php super-sheep-copy/src/Plugin.php super-sheep-copy/tests/Unit/RestorePageTest.php
git commit -m "feat: launch restore installer preparation"
```

Expected: commit succeeds.

---

### Task 3: Installer Token Security and Archive Validator

**Files:**
- Create: `super-sheep-copy/installer/restore-engine/ArchiveValidationResult.php`
- Create: `super-sheep-copy/installer/restore-engine/ArchiveValidator.php`
- Modify: `super-sheep-copy/installer/restore-engine/Security.php`
- Test: `super-sheep-copy/tests/Unit/InstallerSecurityTest.php`
- Test: `super-sheep-copy/tests/Unit/InstallerArchiveValidatorTest.php`

- [x] **Step 1: Write failing token security test**

Create `super-sheep-copy/tests/Unit/InstallerSecurityTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/Security.php';

final class InstallerSecurityTest extends TestCase
{
    public function testValidTokenVerifiesAgainstConfigHash(): void
    {
        $security = new \SuperSheepCopyInstaller\Security();

        self::assertTrue($security->verifyToken('plain-token', array(
            'token_hash' => password_hash('plain-token', PASSWORD_DEFAULT),
            'locked' => false,
        )));
    }

    public function testMissingTokenFails(): void
    {
        $security = new \SuperSheepCopyInstaller\Security();

        self::assertFalse($security->verifyToken('', array(
            'token_hash' => password_hash('plain-token', PASSWORD_DEFAULT),
            'locked' => false,
        )));
    }

    public function testInvalidTokenFails(): void
    {
        $security = new \SuperSheepCopyInstaller\Security();

        self::assertFalse($security->verifyToken('wrong-token', array(
            'token_hash' => password_hash('plain-token', PASSWORD_DEFAULT),
            'locked' => false,
        )));
    }

    public function testLockedInstallerFails(): void
    {
        $security = new \SuperSheepCopyInstaller\Security();

        self::assertFalse($security->verifyToken('plain-token', array(
            'token_hash' => password_hash('plain-token', PASSWORD_DEFAULT),
            'locked' => true,
        )));
    }
}
```

- [x] **Step 2: Write failing installer archive validator test**

Create `super-sheep-copy/tests/Unit/InstallerArchiveValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/ArchiveValidationResult.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/ArchiveValidator.php';

final class InstallerArchiveValidatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-installer-validator-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: array() as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testValidSuperSheepCopyArchivePasses(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $validator = new \SuperSheepCopyInstaller\ArchiveValidator();
        $result = $validator->validatePackage($this->archive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy', 'source_site_url' => 'https://source.example')),
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
        )));

        self::assertTrue($result->isValid());
        self::assertSame('https://source.example', $result->manifest()['source_site_url']);
        self::assertSame(3, $result->entryCount());
        self::assertSame(1, $result->databaseEntryCount());
    }

    public function testUnsafeEntryFails(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $validator = new \SuperSheepCopyInstaller\ArchiveValidator();
        $result = $validator->validatePackage($this->archive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            '../evil.php' => 'bad',
            'database/tables.json' => '{}',
        )));

        self::assertFalse($result->isValid());
        self::assertNotSame(array(), $result->errors());
    }

    public function testMissingManifestFails(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $validator = new \SuperSheepCopyInstaller\ArchiveValidator();
        $result = $validator->validatePackage($this->archive(array(
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
        )));

        self::assertFalse($result->isValid());
    }

    /**
     * @param array<string,string|false> $entries
     */
    private function archive(array $entries): string
    {
        $path = $this->root . '/backup-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, (string) $contents);
        }
        $zip->close();

        return $path;
    }
}
```

- [x] **Step 3: Run tests to verify they fail**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerSecurityTest.php tests/Unit/InstallerArchiveValidatorTest.php
```

Expected: FAIL because `verifyToken()` and installer archive validator classes do not exist yet.

- [x] **Step 4: Add installer archive validation result**

Create `super-sheep-copy/installer/restore-engine/ArchiveValidationResult.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class ArchiveValidationResult
{
    private bool $valid;
    /** @var string[] */
    private array $errors;
    /** @var array<string,mixed> */
    private array $manifest;
    private int $entry_count;
    private int $database_entry_count;

    /**
     * @param string[] $errors
     * @param array<string,mixed> $manifest
     */
    public function __construct(bool $valid, array $errors, array $manifest, int $entry_count, int $database_entry_count)
    {
        $this->valid = $valid;
        $this->errors = $errors;
        $this->manifest = $manifest;
        $this->entry_count = $entry_count;
        $this->database_entry_count = $database_entry_count;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    public function entryCount(): int
    {
        return $this->entry_count;
    }

    public function databaseEntryCount(): int
    {
        return $this->database_entry_count;
    }
}
```

- [x] **Step 5: Add installer archive validator**

Create `super-sheep-copy/installer/restore-engine/ArchiveValidator.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use ZipArchive;

final class ArchiveValidator
{
    public function isSafePath(string $path): bool
    {
        if ($path === '' || strpos($path, "\0") !== false) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);
        if ($normalized[0] === '/' || preg_match('#^[A-Za-z]:/#', $normalized) === 1) {
            return false;
        }

        foreach (explode('/', $normalized) as $part) {
            if ($part === '..') {
                return false;
            }
        }

        return true;
    }

    public function validatePackage(string $archive_path): ArchiveValidationResult
    {
        $errors = array();
        $manifest = array();
        $entry_count = 0;
        $database_entry_count = 0;
        $has_manifest = false;
        $has_checksums = false;

        if (!class_exists(ZipArchive::class)) {
            return new ArchiveValidationResult(false, array('ZipArchive is not available.'), array(), 0, 0);
        }

        $zip = new ZipArchive();
        if ($zip->open($archive_path) !== true) {
            return new ArchiveValidationResult(false, array('Unable to open archive.'), array(), 0, 0);
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name)) {
                $errors[] = 'Unable to read archive entry name.';
                continue;
            }

            $entry_count++;
            if (!$this->isSafePath($name)) {
                $errors[] = 'Unsafe archive entry.';
            }

            if ($name === 'manifest.json') {
                $has_manifest = true;
            }

            if ($name === 'checksums.json') {
                $has_checksums = true;
            }

            if (strpos($name, 'database/') === 0 && substr($name, -1) !== '/') {
                $database_entry_count++;
            }
        }

        if (!$has_manifest) {
            $errors[] = 'Missing manifest.json.';
        }

        if (!$has_checksums) {
            $errors[] = 'Missing checksums.json.';
        }

        if ($database_entry_count === 0) {
            $errors[] = 'No database entries were found.';
        }

        if ($has_manifest) {
            $manifest_json = $zip->getFromName('manifest.json');
            $decoded = is_string($manifest_json) ? json_decode($manifest_json, true) : null;
            if (!is_array($decoded)) {
                $errors[] = 'manifest.json is not valid JSON.';
            } else {
                $manifest = $decoded;
                if (($manifest['project'] ?? null) !== 'Super Sheep Copy') {
                    $errors[] = 'Archive manifest project is not Super Sheep Copy.';
                }
            }
        }

        $zip->close();

        return new ArchiveValidationResult($errors === array(), $errors, $manifest, $entry_count, $database_entry_count);
    }
}
```

- [x] **Step 6: Update installer security**

Replace `super-sheep-copy/installer/restore-engine/Security.php` with:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class Security
{
    /**
     * @param array<string,mixed> $config
     */
    public function verifyToken(string $token, array $config): bool
    {
        if ($token === '' || !empty($config['locked'])) {
            return false;
        }

        $hash = isset($config['token_hash']) ? (string) $config['token_hash'] : '';
        if ($hash === '') {
            return false;
        }

        return password_verify($token, $hash);
    }

    public function requestToken(): string
    {
        return isset($_GET['token']) && is_string($_GET['token']) ? (string) $_GET['token'] : '';
    }
}
```

- [x] **Step 7: Run focused tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerSecurityTest.php tests/Unit/InstallerArchiveValidatorTest.php
```

Expected: PASS.

- [x] **Step 8: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 9: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/ArchiveValidationResult.php super-sheep-copy/installer/restore-engine/ArchiveValidator.php super-sheep-copy/installer/restore-engine/Security.php super-sheep-copy/tests/Unit/InstallerSecurityTest.php super-sheep-copy/tests/Unit/InstallerArchiveValidatorTest.php
git commit -m "feat: protect installer with restore token"
```

Expected: commit succeeds.

---

### Task 4: Installer Bootstrap Token Gate and Metadata

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/Bootstrap.php`
- Test: `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

final class InstallerBootstrapTest extends TestCase
{
    private string $engine;
    private ?string $previous_engine;

    protected function setUp(): void
    {
        $this->engine = sys_get_temp_dir() . '/ssc-installer-bootstrap-' . bin2hex(random_bytes(4));
        mkdir($this->engine, 0777, true);
        $this->previous_engine = $GLOBALS['ssc_installer_engine_dir'] ?? null;
        $GLOBALS['ssc_installer_engine_dir'] = $this->engine;
        $_GET = array();
    }

    protected function tearDown(): void
    {
        if ($this->previous_engine === null) {
            unset($GLOBALS['ssc_installer_engine_dir']);
        } else {
            $GLOBALS['ssc_installer_engine_dir'] = $this->previous_engine;
        }
        $this->removeDirectory($this->engine);
    }

    public function testMissingTokenDoesNotShowArchiveDetails(): void
    {
        $this->writeConfig('plain-token', $this->validArchive());

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Restore token required', $html);
        self::assertStringNotContainsString('https://source.example', $html);
    }

    public function testValidTokenShowsArchiveDetails(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeConfig('plain-token', $this->validArchive());
        $_GET['token'] = 'plain-token';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Environment', $html);
        self::assertStringContainsString('Prepared Archive', $html);
        self::assertStringContainsString('https://source.example', $html);
        self::assertStringContainsString('Restore execution is not implemented yet', $html);
    }

    private function writeConfig(string $token, string $archive): void
    {
        file_put_contents($this->engine . '/config.php', "<?php\nreturn " . var_export(array(
            'restore_job_id' => 'restore-123',
            'staged_archive_path' => $archive,
            'staged_archive_basename' => basename($archive),
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
            'token_hash' => password_hash($token, PASSWORD_DEFAULT),
            'token_created_at' => gmdate('c'),
            'locked' => false,
        ), true) . ";\n");
    }

    private function validArchive(): string
    {
        $archive = $this->engine . '/backup.zip';
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

Add to the top of the file after namespace:

```php
require_once dirname(__DIR__, 2) . '/installer/restore-engine/Bootstrap.php';
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: FAIL because `Bootstrap` does not load config or validate tokens yet.

- [x] **Step 3: Update bootstrap dependencies**

Modify `super-sheep-copy/installer/restore-engine/Bootstrap.php`.

Add required files after the namespace:

```php
require_once __DIR__ . '/EnvironmentChecker.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/ArchiveValidationResult.php';
require_once __DIR__ . '/ArchiveValidator.php';
```

- [x] **Step 4: Replace bootstrap implementation**

Replace the `Bootstrap` class body with:

```php
final class Bootstrap
{
    public static function run(): void
    {
        $engine_dir = self::engineDirectory();
        $config = self::loadConfig($engine_dir);
        $security = new Security();
        $token = $security->requestToken();
        $verified = $security->verifyToken($token, $config);

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Super Sheep Copy Installer</title>';
        echo '<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:40px;max-width:880px;color:#1d2327}';
        echo '.status{padding:12px;border:1px solid #c3c4c7;margin:10px 0}.warning{border-color:#dba617;background:#fcf9e8}.ok{border-color:#00a32a;background:#edfaef}.error{border-color:#d63638;background:#fcf0f1}</style>';
        echo '</head><body>';
        echo '<h1>Super Sheep Copy Installer</h1>';

        if (!$verified) {
            echo '<div class="status warning"><strong>Restore token required.</strong> Enter the token generated by the WordPress admin restore page.</div>';
            echo '<form method="get"><p><input type="password" name="token" autocomplete="off" /></p><p><button type="submit">Unlock Installer</button></p></form>';
            echo '</body></html>';
            return;
        }

        $archive_path = isset($config['staged_archive_path']) ? (string) $config['staged_archive_path'] : '';
        $validation = (new ArchiveValidator())->validatePackage($archive_path);
        if (!$validation->isValid()) {
            echo '<div class="status error">Prepared archive could not be validated. Restore execution is unavailable.</div>';
            echo '</body></html>';
            return;
        }

        echo '<h2>Environment</h2>';
        foreach ((new EnvironmentChecker())->check() as $check) {
            $class = $check['status'] === 'ok' ? 'ok' : 'warning';
            echo '<div class="status ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
            echo '<strong>' . htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8') . ':</strong> ';
            echo htmlspecialchars($check['value'], ENT_QUOTES, 'UTF-8');
            echo '</div>';
        }

        $manifest = $validation->manifest();
        echo '<h2>Prepared Archive</h2>';
        echo '<div class="status ok"><strong>Source site URL:</strong> ' . htmlspecialchars((string) ($manifest['source_site_url'] ?? $config['source_site_url'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="status ok"><strong>Source home URL:</strong> ' . htmlspecialchars((string) ($manifest['source_home_url'] ?? $config['source_home_url'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="status ok"><strong>Archive entries:</strong> ' . htmlspecialchars((string) $validation->entryCount(), ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="status ok"><strong>Database entries:</strong> ' . htmlspecialchars((string) $validation->databaseEntryCount(), ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="status warning">Restore execution is not implemented yet. No database import, file extraction, or replacement will run in this milestone.</div>';
        echo '</body></html>';
    }

    private static function engineDirectory(): string
    {
        return isset($GLOBALS['ssc_installer_engine_dir']) && is_string($GLOBALS['ssc_installer_engine_dir'])
            ? $GLOBALS['ssc_installer_engine_dir']
            : __DIR__;
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadConfig(string $engine_dir): array
    {
        $path = rtrim($engine_dir, '/\\') . '/config.php';
        if (!is_readable($path)) {
            return array();
        }

        $config = require $path;

        return is_array($config) ? $config : array();
    }
}
```

- [x] **Step 5: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/InstallerBootstrapTest.php
```

Expected: PASS.

- [x] **Step 6: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 7: Commit**

Run:

```bash
git add super-sheep-copy/installer/restore-engine/Bootstrap.php super-sheep-copy/tests/Unit/InstallerBootstrapTest.php
git commit -m "feat: show token-gated installer preflight"
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
rg -n '\$_POST|\$_REQUEST|\$_GET|\$_FILES' super-sheep-copy/src super-sheep-copy/installer
```

Expected: direct request/global access is limited to:

- `super-sheep-copy/src/Admin/BackupPage.php`
- `super-sheep-copy/src/Admin/RestorePage.php`
- `super-sheep-copy/src/Security/Nonce.php`
- `super-sheep-copy/installer/restore-engine/Security.php`

- [x] **Step 4: Check git status**

Run:

```bash
git status --short
```

Expected: clean after committing plan checklist updates.

- [x] **Step 5: Commit checklist update**

Run:

```bash
git add docs/superpowers/plans/2026-05-16-installer-preparation-token.md
git commit -m "docs: mark installer preparation token complete"
```

Expected: commit succeeds after Task 5 checkboxes are marked complete.

---

## Self-Review

- Spec coverage: This plan covers WordPress-root installer deployment, plugin-side token generation and hashed config, restore page installer preparation, installer token verification, installer-side package validation, non-destructive metadata rendering, and final verification.
- Scope exclusions remain excluded: no database import, extraction, file restore, URL replacement, rollback, maintenance mode, token consumption after restore, installer self-delete, cache clearing, or health checks.
- Type consistency: `InstallerPreparationManagerInterface::prepare(string): InstallerPreparationResult` is used by `RestorePage`; job IDs are passed as strings; job payload stores safe fields only; installer config stores `token_hash` while job payload does not.
- Placeholder scan: no placeholder implementation steps are intentionally left open.
