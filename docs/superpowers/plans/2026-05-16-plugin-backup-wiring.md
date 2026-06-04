# Plugin Backup Wiring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a secured synchronous Backup admin action that gathers WordPress metadata, constructs the production backup pipeline, runs `BackupManager`, and redirects with a status notice.

**Architecture:** Add WordPress-layer backup services for metadata collection and manager construction. Add a small `BackupRunnerInterface` so `BackupPage` can run a real `BackupManager` in production and a fake runner in unit tests. Update `BackupPage`, `AdminMenu`, `Plugin`, and the backup template to expose a POST form protected by capability and nonce checks.

**Tech Stack:** PHP 7.4+, WordPress admin APIs, Composer PSR-4 autoloading, PHPUnit 9.6, existing test bootstrap WordPress shims.

---

## Scope Check

This plan implements `docs/superpowers/specs/2026-05-16-plugin-backup-wiring-design.md`.

Included:
- Test bootstrap WordPress shims required by new unit tests.
- `BackupMetadataCollectorInterface` and `BackupMetadataCollector`.
- `BackupRunnerInterface` implemented by `BackupManager`.
- `BackupManagerFactoryInterface` and `BackupManagerFactory`.
- `BackupPage` POST handling for `super_sheep_copy_action=create_backup`.
- `AdminMenu` and `Plugin` wiring for the new services.
- Backup page template form, nonce, notices, and enabled button.

Excluded:
- AJAX/REST backup runner.
- Progress UI.
- Download links.
- Backup list management beyond the existing jobs table.
- Cleanup/retention.
- Restore flow.

## File Structure

- Modify `super-sheep-copy/tests/bootstrap.php`
  - Add minimal WordPress function shims and test globals.
- Create `super-sheep-copy/src/Backup/BackupMetadataCollectorInterface.php`
  - Contract for collecting manifest metadata.
- Create `super-sheep-copy/src/Backup/BackupMetadataCollector.php`
  - Reads WordPress metadata and environment checks.
- Create `super-sheep-copy/tests/Unit/BackupMetadataCollectorTest.php`
  - Verifies metadata values and required keys.
- Create `super-sheep-copy/src/Backup/BackupRunnerInterface.php`
  - Contract with `run(BackupOptions): BackupResult`.
- Modify `super-sheep-copy/src/Backup/BackupManager.php`
  - Implement `BackupRunnerInterface`.
- Create `super-sheep-copy/src/Backup/BackupManagerFactoryInterface.php`
  - Contract for creating a backup runner.
- Create `super-sheep-copy/src/Backup/BackupManagerFactory.php`
  - Builds the production service graph.
- Create `super-sheep-copy/tests/Unit/BackupManagerFactoryTest.php`
  - Verifies the factory returns a `BackupRunnerInterface`.
- Modify `super-sheep-copy/src/Admin/BackupPage.php`
  - Add POST handling, backup option construction, redirects, and notices.
- Modify `super-sheep-copy/templates/backup-page.php`
  - Replace disabled button with POST form and show status notices.
- Modify `super-sheep-copy/src/Admin/AdminMenu.php`
  - Inject factory and metadata collector into `BackupPage`.
- Modify `super-sheep-copy/src/Plugin.php`
  - Build `BackupMetadataCollector` and `BackupManagerFactory`.
- Create `super-sheep-copy/tests/Unit/BackupPageTest.php`
  - Verifies render form and POST run behavior.

---

### Task 1: WordPress Test Shims

**Files:**
- Modify: `super-sheep-copy/tests/bootstrap.php`

- [x] **Step 1: Add a failing bootstrap smoke test**

Create a temporary test command by running:

```bash
cd super-sheep-copy && php -r "require 'tests/bootstrap.php'; if (!function_exists('wp_safe_redirect')) { exit(1); }"
```

Expected: exits with status `1` because the bootstrap does not define WordPress admin helper shims yet.

- [x] **Step 2: Add WordPress shims**

Append this block to `super-sheep-copy/tests/bootstrap.php`:

```php
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/ssc-test-site/');
}

if (!defined('SUPER_SHEEP_COPY_VERSION')) {
    define('SUPER_SHEEP_COPY_VERSION', '0.1.0');
}

if (!defined('SUPER_SHEEP_COPY_DIR')) {
    define('SUPER_SHEEP_COPY_DIR', dirname(__DIR__) . '/');
}

if (!defined('SUPER_SHEEP_COPY_URL')) {
    define('SUPER_SHEEP_COPY_URL', 'https://example.com/wp-content/plugins/super-sheep-copy/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/ssc-test-content');
}

$GLOBALS['ssc_test_options'] = array();
$GLOBALS['ssc_test_redirect'] = null;
$GLOBALS['ssc_test_current_user_can'] = true;
$GLOBALS['ssc_test_nonce_valid'] = true;
$GLOBALS['ssc_test_site_url'] = 'https://example.com';
$GLOBALS['ssc_test_home_url'] = 'https://example.com';
$GLOBALS['ssc_test_bloginfo_version'] = '6.5';
$GLOBALS['ssc_test_is_multisite'] = false;
$GLOBALS['ssc_test_stylesheet'] = 'twentytwentyfour';
$GLOBALS['ssc_test_mu_plugins'] = array();

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return (bool) $GLOBALS['ssc_test_current_user_can'];
    }
}

if (!function_exists('wp_die')) {
    function wp_die(string $message): void
    {
        throw new RuntimeException($message);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action)
    {
        return (bool) $GLOBALS['ssc_test_nonce_valid'] ? 1 : false;
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action, string $name, bool $referer = true, bool $display = true): string
    {
        $field = '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce" />';
        if ($display) {
            echo $field;
        }

        return $field;
    }
}

if (!function_exists('site_url')) {
    function site_url(): string
    {
        return (string) $GLOBALS['ssc_test_site_url'];
    }
}

if (!function_exists('home_url')) {
    function home_url(): string
    {
        return (string) $GLOBALS['ssc_test_home_url'];
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        return $show === 'version' ? (string) $GLOBALS['ssc_test_bloginfo_version'] : '';
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return (bool) $GLOBALS['ssc_test_is_multisite'];
    }
}

if (!function_exists('get_stylesheet')) {
    function get_stylesheet(): string
    {
        return (string) $GLOBALS['ssc_test_stylesheet'];
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['ssc_test_options']) ? $GLOBALS['ssc_test_options'][$name] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, $value, bool $autoload = true): bool
    {
        $GLOBALS['ssc_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('get_mu_plugins')) {
    function get_mu_plugins(): array
    {
        return (array) $GLOBALS['ssc_test_mu_plugins'];
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . http_build_query($args);
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        $GLOBALS['ssc_test_redirect'] = $location;
        return true;
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, bool $create_dir = true): array
    {
        return array('basedir' => sys_get_temp_dir() . '/ssc-test-uploads');
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}
```

- [x] **Step 3: Run bootstrap smoke test**

Run:

```bash
cd super-sheep-copy && php -r "require 'tests/bootstrap.php'; if (!function_exists('wp_safe_redirect')) { exit(1); }"
```

Expected: exits with status `0`.

- [x] **Step 4: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/tests/bootstrap.php
git commit -m "test: add wordpress admin shims"
```

Expected: commit succeeds.

---

### Task 2: Backup Metadata Collector

**Files:**
- Create: `super-sheep-copy/src/Backup/BackupMetadataCollectorInterface.php`
- Create: `super-sheep-copy/src/Backup/BackupMetadataCollector.php`
- Create: `super-sheep-copy/tests/Unit/BackupMetadataCollectorTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/BackupMetadataCollectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupMetadataCollector;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;

final class BackupMetadataCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new MetadataWpdbStub();
        $GLOBALS['ssc_test_options'] = array('active_plugins' => array('akismet/akismet.php'));
        $GLOBALS['ssc_test_site_url'] = 'https://source.example';
        $GLOBALS['ssc_test_home_url'] = 'https://home.example';
        $GLOBALS['ssc_test_bloginfo_version'] = '6.5.4';
        $GLOBALS['ssc_test_is_multisite'] = true;
        $GLOBALS['ssc_test_stylesheet'] = 'custom-theme';
        $GLOBALS['ssc_test_mu_plugins'] = array('mu-loader.php' => array('Name' => 'MU Loader'));
    }

    public function testCollectsWordPressBackupMetadata(): void
    {
        $collector = new BackupMetadataCollector(new MetadataEnvironmentChecker());

        $metadata = $collector->collect();

        self::assertSame('https://source.example', $metadata['source_site_url']);
        self::assertSame('https://home.example', $metadata['source_home_url']);
        self::assertSame('6.5.4', $metadata['wordpress_version']);
        self::assertSame(PHP_VERSION, $metadata['php_version']);
        self::assertSame('8.0.36', $metadata['database_version']);
        self::assertSame('wp_', $metadata['table_prefix']);
        self::assertTrue($metadata['is_multisite']);
        self::assertSame('custom-theme', $metadata['active_theme']);
        self::assertSame(array('akismet/akismet.php'), $metadata['active_plugins']);
        self::assertSame(array('mu-loader.php'), $metadata['must_use_plugins']);
        self::assertSame(0, $metadata['file_count']);
        self::assertSame(0, $metadata['database_table_count']);
        self::assertSame(0, $metadata['archive_size']);
        self::assertSame(array(), $metadata['checksums']);
        self::assertSame(array('wp-content/cache', 'wp-content/uploads/super-sheep-copy'), $metadata['exclusions']);
        self::assertSame(array('zip' => array('status' => 'ok')), $metadata['environment']);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\+00:00$/', $metadata['created_at']);
    }
}

final class MetadataWpdbStub
{
    public string $prefix = 'wp_';

    public function db_version(): string
    {
        return '8.0.36';
    }
}

final class MetadataEnvironmentChecker implements EnvironmentCheckerInterface
{
    public function check(): array
    {
        return array('zip' => array('status' => 'ok'));
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupMetadataCollectorTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\BackupMetadataCollector" not found`.

- [x] **Step 3: Add collector interface**

Create `super-sheep-copy/src/Backup/BackupMetadataCollectorInterface.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

interface BackupMetadataCollectorInterface
{
    /**
     * @return array<string,mixed>
     */
    public function collect(): array;
}
```

- [x] **Step 4: Add collector implementation**

Create `super-sheep-copy/src/Backup/BackupMetadataCollector.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use SuperSheepCopy\Support\EnvironmentCheckerInterface;

final class BackupMetadataCollector implements BackupMetadataCollectorInterface
{
    private EnvironmentCheckerInterface $environment_checker;

    public function __construct(EnvironmentCheckerInterface $environment_checker)
    {
        $this->environment_checker = $environment_checker;
    }

    public function collect(): array
    {
        global $wpdb;

        return array(
            'source_site_url' => function_exists('site_url') ? site_url() : '',
            'source_home_url' => function_exists('home_url') ? home_url() : '',
            'wordpress_version' => function_exists('get_bloginfo') ? get_bloginfo('version') : '',
            'php_version' => PHP_VERSION,
            'database_version' => is_object($wpdb) && method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : '',
            'table_prefix' => is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : '',
            'is_multisite' => function_exists('is_multisite') ? is_multisite() : false,
            'active_theme' => function_exists('get_stylesheet') ? get_stylesheet() : '',
            'active_plugins' => array_values((array) (function_exists('get_option') ? get_option('active_plugins', array()) : array())),
            'must_use_plugins' => function_exists('get_mu_plugins') ? array_keys((array) get_mu_plugins()) : array(),
            'created_at' => gmdate('c'),
            'file_count' => 0,
            'database_table_count' => 0,
            'archive_size' => 0,
            'checksums' => array(),
            'exclusions' => array('wp-content/cache', 'wp-content/uploads/super-sheep-copy'),
            'environment' => $this->environment_checker->check(),
        );
    }
}
```

- [x] **Step 5: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupMetadataCollectorTest.php
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
git add super-sheep-copy/src/Backup/BackupMetadataCollectorInterface.php super-sheep-copy/src/Backup/BackupMetadataCollector.php super-sheep-copy/tests/Unit/BackupMetadataCollectorTest.php
git commit -m "feat: collect backup metadata"
```

Expected: commit succeeds.

---

### Task 3: Backup Manager Factory and Runner Interface

**Files:**
- Create: `super-sheep-copy/src/Backup/BackupRunnerInterface.php`
- Create: `super-sheep-copy/src/Backup/BackupManagerFactoryInterface.php`
- Create: `super-sheep-copy/src/Backup/BackupManagerFactory.php`
- Modify: `super-sheep-copy/src/Backup/BackupManager.php`
- Create: `super-sheep-copy/tests/Unit/BackupManagerFactoryTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/BackupManagerFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupManagerFactory;
use SuperSheepCopy\Backup\BackupRunnerInterface;
use SuperSheepCopy\Jobs\OptionJobRepository;

final class BackupManagerFactoryTest extends TestCase
{
    public function testCreatesBackupRunner(): void
    {
        $factory = new BackupManagerFactory(new OptionJobRepository(), new FactoryWpdbStub());

        self::assertInstanceOf(BackupRunnerInterface::class, $factory->create());
    }
}

final class FactoryWpdbStub
{
    public string $prefix = 'wp_';
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupManagerFactoryTest.php
```

Expected: FAIL with `Class "SuperSheepCopy\Backup\BackupManagerFactory" not found`.

- [x] **Step 3: Add runner interface**

Create `super-sheep-copy/src/Backup/BackupRunnerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

interface BackupRunnerInterface
{
    public function run(BackupOptions $options): BackupResult;
}
```

- [x] **Step 4: Make `BackupManager` implement runner interface**

Modify `super-sheep-copy/src/Backup/BackupManager.php` class declaration:

```php
final class BackupManager implements BackupRunnerInterface
```

- [x] **Step 5: Add factory interface**

Create `super-sheep-copy/src/Backup/BackupManagerFactoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

interface BackupManagerFactoryInterface
{
    public function create(): BackupRunnerInterface;
}
```

- [x] **Step 6: Add factory implementation**

Create `super-sheep-copy/src/Backup/BackupManagerFactory.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseBackupCoordinator;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\DatabaseExportWriter;
use SuperSheepCopy\Backup\Database\SqlDumpFormatter;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClient;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class BackupManagerFactory implements BackupManagerFactoryInterface
{
    private JobRepositoryInterface $jobs;
    /** @var object */
    private $wpdb;

    /**
     * @param object $wpdb
     */
    public function __construct(JobRepositoryInterface $jobs, $wpdb)
    {
        $this->jobs = $jobs;
        $this->wpdb = $wpdb;
    }

    public function create(): BackupRunnerInterface
    {
        $wpdb_client = new WpdbClient($this->wpdb);
        $database_exporter = new WpdbDatabaseExporter($wpdb_client, new TableSelector());
        $database_writer = new DatabaseExportWriter(new DatabaseExportManifestBuilder());
        $database = new DatabaseBackupCoordinator(
            $database_exporter,
            new ChunkPlanner(),
            new SqlDumpFormatter(),
            $database_writer
        );

        $packager = new BackupArchivePackager(
            new ArchiveWriter(),
            new ManifestBuilder(defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : '0.1.0', '1')
        );

        return new BackupManager($this->jobs, $database, new FileScanner(), $packager);
    }
}
```

- [x] **Step 7: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupManagerFactoryTest.php
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
git add super-sheep-copy/src/Backup/BackupRunnerInterface.php super-sheep-copy/src/Backup/BackupManagerFactoryInterface.php super-sheep-copy/src/Backup/BackupManagerFactory.php super-sheep-copy/src/Backup/BackupManager.php super-sheep-copy/tests/Unit/BackupManagerFactoryTest.php
git commit -m "feat: build backup manager pipeline"
```

Expected: commit succeeds.

---

### Task 4: Backup Page POST Action

**Files:**
- Modify: `super-sheep-copy/src/Admin/BackupPage.php`
- Modify: `super-sheep-copy/tests/Unit/BackupPageTest.php`

- [x] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/BackupPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Admin\BackupPage;
use SuperSheepCopy\Backup\BackupManagerFactoryInterface;
use SuperSheepCopy\Backup\BackupMetadataCollectorInterface;
use SuperSheepCopy\Backup\BackupOptions;
use SuperSheepCopy\Backup\BackupResult;
use SuperSheepCopy\Backup\BackupRunnerInterface;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;

final class BackupPageTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = array();
        $_REQUEST = array();
        $GLOBALS['ssc_test_redirect'] = null;
        $GLOBALS['ssc_test_current_user_can'] = true;
        $GLOBALS['ssc_test_nonce_valid'] = true;
    }

    public function testRenderShowsCreateBackupForm(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('name="super_sheep_copy_action"', $html);
        self::assertStringContainsString('value="create_backup"', $html);
        self::assertStringContainsString('name="super_sheep_copy_nonce"', $html);
        self::assertStringContainsString('Create Backup', $html);
        self::assertStringNotContainsString('disabled', $html);
    }

    public function testPostCreatesBackupAndRedirectsWithSuccess(): void
    {
        $_POST['super_sheep_copy_action'] = 'create_backup';
        $_REQUEST['super_sheep_copy_action'] = 'create_backup';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

        $runner = new BackupPageRunner();
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(),
            new BackupPageFactory($runner),
            new BackupPageMetadataCollector()
        );

        $page->render();

        self::assertInstanceOf(BackupOptions::class, $runner->options());
        self::assertSame(ABSPATH, $runner->options()->siteRoot());
        self::assertSame(sys_get_temp_dir() . '/ssc-test-uploads/super-sheep-copy', $runner->options()->workingBaseDirectory());
        self::assertSame('wp_', $runner->options()->tablePrefix());
        self::assertSame('prefixed', $runner->options()->tableSelectionMode());
        self::assertSame(500, $runner->options()->databaseChunkSize());
        self::assertSame('https://example.com', $runner->options()->manifestMetadata()['source_site_url']);
        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy&super_sheep_copy_status=backup_created', $GLOBALS['ssc_test_redirect']);
    }

    public function testPostRedirectsWithFailureWhenRunnerThrows(): void
    {
        $_POST['super_sheep_copy_action'] = 'create_backup';
        $_REQUEST['super_sheep_copy_action'] = 'create_backup';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(),
            new BackupPageFactory(new BackupPageRunner(true)),
            new BackupPageMetadataCollector()
        );

        $page->render();

        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy&super_sheep_copy_status=backup_failed', $GLOBALS['ssc_test_redirect']);
    }
}

final class BackupPageFactory implements BackupManagerFactoryInterface
{
    private BackupRunnerInterface $runner;

    public function __construct(BackupRunnerInterface $runner)
    {
        $this->runner = $runner;
    }

    public function create(): BackupRunnerInterface
    {
        return $this->runner;
    }
}

final class BackupPageRunner implements BackupRunnerInterface
{
    private ?BackupOptions $options = null;
    private bool $throw;

    public function __construct(bool $throw = false)
    {
        $this->throw = $throw;
    }

    public function run(BackupOptions $options): BackupResult
    {
        if ($this->throw) {
            throw new \RuntimeException('backup failed');
        }

        $this->options = $options;

        return new BackupResult('backup-123', '/working', '/working/database', '/working/backup.zip', 100, 2, 1, Job::COMPLETED);
    }

    public function options(): ?BackupOptions
    {
        return $this->options;
    }
}

final class BackupPageMetadataCollector implements BackupMetadataCollectorInterface
{
    public function collect(): array
    {
        return array(
            'source_site_url' => 'https://example.com',
            'table_prefix' => 'wp_',
            'source_home_url' => 'https://example.com',
            'wordpress_version' => '6.5',
            'php_version' => PHP_VERSION,
            'database_version' => '8.0',
            'is_multisite' => false,
            'active_theme' => 'theme',
            'active_plugins' => array(),
            'must_use_plugins' => array(),
            'created_at' => '2026-05-16T12:00:00+00:00',
            'file_count' => 0,
            'database_table_count' => 0,
            'archive_size' => 0,
            'checksums' => array(),
            'exclusions' => array(),
            'environment' => array(),
        );
    }
}

final class BackupPageEnvironmentChecker implements EnvironmentCheckerInterface
{
    public function check(): array
    {
        return array('zip' => array('label' => 'ZIP', 'value' => 'Available', 'status' => 'ok'));
    }
}

final class BackupPageJobRepository implements JobRepositoryInterface
{
    public function save(Job $job): void
    {
    }

    public function find(string $id): ?Job
    {
        return null;
    }

    public function all(): array
    {
        return array();
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupPageTest.php
```

Expected: FAIL because `BackupPage` does not accept factory/collector dependencies and the template still has a disabled button.

- [x] **Step 3: Update `BackupPage`**

Replace `super-sheep-copy/src/Admin/BackupPage.php` with:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Admin;

use SuperSheepCopy\Backup\BackupManagerFactoryInterface;
use SuperSheepCopy\Backup\BackupMetadataCollectorInterface;
use SuperSheepCopy\Backup\BackupOptions;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Plugin;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;
use Throwable;

final class BackupPage
{
    private const ACTION_FIELD = 'super_sheep_copy_action';
    private const ACTION_CREATE_BACKUP = 'create_backup';
    private const STATUS_FIELD = 'super_sheep_copy_status';

    private Capability $capability;
    private Nonce $nonce;
    private EnvironmentCheckerInterface $environment_checker;
    private JobRepositoryInterface $jobs;
    private BackupManagerFactoryInterface $backup_factory;
    private BackupMetadataCollectorInterface $metadata_collector;

    public function __construct(
        Capability $capability,
        Nonce $nonce,
        EnvironmentCheckerInterface $environment_checker,
        JobRepositoryInterface $jobs,
        BackupManagerFactoryInterface $backup_factory,
        BackupMetadataCollectorInterface $metadata_collector
    ) {
        $this->capability = $capability;
        $this->nonce = $nonce;
        $this->environment_checker = $environment_checker;
        $this->jobs = $jobs;
        $this->backup_factory = $backup_factory;
        $this->metadata_collector = $metadata_collector;
    }

    public function render(): void
    {
        $this->capability->requireManageBackups();
        if ($this->handleCreateBackup()) {
            return;
        }

        $environment = $this->environment_checker->check();
        $jobs = $this->jobs->all();
        $manifest_preview = array(
            'project' => 'Super Sheep Copy',
            'plugin_version' => defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : '0.1.0',
            'backup_format_version' => '1',
            'source_site_url' => function_exists('site_url') ? site_url() : '',
            'source_home_url' => function_exists('home_url') ? home_url() : '',
            'is_multisite' => function_exists('is_multisite') ? is_multisite() : false,
        );
        $status = $this->status();
        $nonce_field = $this->nonce->field();
        include SUPER_SHEEP_COPY_DIR . 'templates/backup-page.php';
    }

    private function handleCreateBackup(): bool
    {
        if (!$this->isCreateBackupRequest()) {
            return false;
        }

        $this->capability->assertManageBackups();
        $this->nonce->verifyRequest();

        try {
            $metadata = $this->metadata_collector->collect();
            $runner = $this->backup_factory->create();
            $runner->run(new BackupOptions(
                defined('ABSPATH') ? ABSPATH : '',
                Plugin::backupDirectory(),
                isset($metadata['table_prefix']) ? (string) $metadata['table_prefix'] : '',
                'prefixed',
                500,
                $metadata
            ));
            $this->redirect('backup_created');
        } catch (Throwable $throwable) {
            $this->redirect('backup_failed');
        }

        return true;
    }

    private function isCreateBackupRequest(): bool
    {
        $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

        return $action === self::ACTION_CREATE_BACKUP;
    }

    private function redirect(string $status): void
    {
        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'super-sheep-copy',
                self::STATUS_FIELD => $status,
            ),
            admin_url('admin.php')
        ));
    }

    private function status(): string
    {
        return isset($_GET[self::STATUS_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::STATUS_FIELD])) : '';
    }
}
```

- [x] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupPageTest.php
```

Expected: still FAIL because the template still has a disabled button and no form.

- [x] **Step 5: Update backup page template**

In `super-sheep-copy/templates/backup-page.php`, update the docblock:

```php
 * @var string $nonce_field
 * @var string $status
```

Add this notice block below the sensitive-data warning:

```php
    <?php if ($status === 'backup_created') : ?>
        <div class="notice notice-success">
            <p><?php echo esc_html__('Backup created successfully.', 'super-sheep-copy'); ?></p>
        </div>
    <?php elseif ($status === 'backup_failed') : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html__('Backup creation failed. Check the latest job state and server logs.', 'super-sheep-copy'); ?></p>
        </div>
    <?php endif; ?>
```

Replace the Backup Scaffold panel body:

```php
        <p><?php echo esc_html__('Create a full backup archive for this site. The first implementation runs synchronously, so keep this page open until the request finishes.', 'super-sheep-copy'); ?></p>
        <form method="post">
            <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <input type="hidden" name="super_sheep_copy_action" value="create_backup" />
            <button class="button button-primary" type="submit"><?php echo esc_html__('Create Backup', 'super-sheep-copy'); ?></button>
        </form>
```

- [x] **Step 6: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupPageTest.php
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
git add super-sheep-copy/src/Admin/BackupPage.php super-sheep-copy/templates/backup-page.php super-sheep-copy/tests/Unit/BackupPageTest.php
git commit -m "feat: handle backup admin action"
```

Expected: commit succeeds.

---

### Task 5: Plugin Service Wiring

**Files:**
- Modify: `super-sheep-copy/src/Admin/AdminMenu.php`
- Modify: `super-sheep-copy/src/Plugin.php`

- [x] **Step 1: Run full suite to expose wiring failures**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: may FAIL if constructor call sites for `AdminMenu` or `BackupPage` are out of date. If it passes, continue; this step documents the pre-change state.

- [x] **Step 2: Update `AdminMenu`**

Modify `super-sheep-copy/src/Admin/AdminMenu.php`:

Add imports:

```php
use SuperSheepCopy\Backup\BackupManagerFactoryInterface;
use SuperSheepCopy\Backup\BackupMetadataCollectorInterface;
```

Add properties:

```php
private BackupManagerFactoryInterface $backup_factory;
private BackupMetadataCollectorInterface $metadata_collector;
```

Update the constructor signature:

```php
public function __construct(
    Capability $capability,
    Nonce $nonce,
    EnvironmentCheckerInterface $environment_checker,
    JobRepositoryInterface $jobs,
    LoggerInterface $logger,
    BackupManagerFactoryInterface $backup_factory,
    BackupMetadataCollectorInterface $metadata_collector
) {
    $this->capability = $capability;
    $this->nonce = $nonce;
    $this->environment_checker = $environment_checker;
    $this->jobs = $jobs;
    $this->logger = $logger;
    $this->backup_factory = $backup_factory;
    $this->metadata_collector = $metadata_collector;
}
```

Update `backupPage()`:

```php
return new BackupPage(
    $this->capability,
    $this->nonce,
    $this->environment_checker,
    $this->jobs,
    $this->backup_factory,
    $this->metadata_collector
);
```

- [x] **Step 3: Update `Plugin`**

Modify `super-sheep-copy/src/Plugin.php`:

Add imports:

```php
use SuperSheepCopy\Backup\BackupManagerFactory;
use SuperSheepCopy\Backup\BackupMetadataCollector;
```

In `boot()`, replace the current `AdminMenu` construction with:

```php
global $wpdb;

$environment_checker = new EnvironmentChecker();
$jobs = new OptionJobRepository();
$admin_menu = new AdminMenu(
    new Capability(),
    new Nonce(),
    $environment_checker,
    $jobs,
    new NullLogger(),
    new BackupManagerFactory($jobs, $wpdb),
    new BackupMetadataCollector($environment_checker)
);
$admin_menu->register();
```

- [x] **Step 4: Run full suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add super-sheep-copy/src/Admin/AdminMenu.php super-sheep-copy/src/Plugin.php
git commit -m "feat: wire backup services into plugin"
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

- [x] **Step 3: Confirm direct request access is scoped**

Run:

```bash
rg "\\$_POST|\\$_REQUEST|\\$_GET" super-sheep-copy/src
```

Expected: matches are limited to `src/Security/Nonce.php` and `src/Admin/BackupPage.php`.

- [x] **Step 4: Check git status**

Run:

```bash
git status --short
```

Expected: empty working tree after task commits.

## Self-Review

- Spec coverage: The plan covers metadata collection, factory/service construction, admin POST handling, nonce/capability checks, backup options, redirects, template form, notices, plugin wiring, and final verification.
- Placeholder scan: The plan has no TODO/TBD placeholders or vague implementation steps.
- Type consistency: `BackupPage` depends on `BackupManagerFactoryInterface` and `BackupMetadataCollectorInterface`; factories return `BackupRunnerInterface`; `BackupManager` implements `BackupRunnerInterface`; metadata arrays include `table_prefix` and all fields expected by `ManifestBuilder`.
