# Normal User Settings V1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first normal-user Settings page for safe future-backup defaults, storage cleanup, and diagnostics.

**Architecture:** Add a small settings domain under `src/Settings/` with defaults, sanitization, and WordPress option persistence. Admin pages receive a settings repository, copy settings into new backup job payloads, and keep backup runners reading job payload snapshots. Keep UI simple: three sections, no per-backup overrides, no advanced restore/performance knobs.

**Tech Stack:** WordPress plugin PHP 7.4+, PHPUnit 9, existing option-backed job repository, existing admin templates.

---

## File Map

- Create `super-sheep-copy/src/Settings/BackupSettings.php`: immutable value object with defaults, validation, payload conversion, and summary labels.
- Create `super-sheep-copy/src/Settings/BackupSettingsRepository.php`: loads/saves `super_sheep_copy_settings` through `get_option()`/`update_option()`.
- Create `super-sheep-copy/src/Settings/DiagnosticsReportBuilder.php`: builds sanitized diagnostics text.
- Modify `super-sheep-copy/src/Admin/SettingsPage.php`: handle save, failed cleanup, diagnostics download, notices, and template variables.
- Modify `super-sheep-copy/templates/settings-page.php`: replace placeholder with form sections and buttons.
- Modify `super-sheep-copy/src/Admin/AdminMenu.php`: pass settings repository into Settings and Backup pages.
- Modify `super-sheep-copy/src/Admin/BackupPage.php`: load settings summary, snapshot settings into new job payload, and run retention cleanup after successful backups in later task.
- Modify `super-sheep-copy/templates/backup-page.php`: show concise settings summary near create button.
- Modify `super-sheep-copy/src/Backup/FileScanner.php`: accept payload settings for cache exclusion and large-file skipping during step scanning.
- Modify `super-sheep-copy/src/Backup/BackupStepRunner.php`: pass backup settings payload into scanner and preserve skipped-file counts.
- Modify `super-sheep-copy/src/Backup/BackupJobFileCleaner.php`: add safe failed-job cleanup helper.
- Add/update focused tests in `super-sheep-copy/tests/Unit/`.
- Modify `super-sheep-copy/tests/bootstrap.php`: add WordPress helper stubs used by the settings form.

---

### Task 1: Settings Value Object And Repository

**Files:**
- Create: `super-sheep-copy/src/Settings/BackupSettings.php`
- Create: `super-sheep-copy/src/Settings/BackupSettingsRepository.php`
- Test: `super-sheep-copy/tests/Unit/BackupSettingsTest.php`

- [ ] **Step 1: Write failing tests**

Create `super-sheep-copy/tests/Unit/BackupSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Settings\BackupSettings;
use SuperSheepCopy\Settings\BackupSettingsRepository;

final class BackupSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ssc_test_options'] = array();
    }

    public function testDefaultsAreSafeForNormalUsers(): void
    {
        $settings = BackupSettings::defaults();

        self::assertTrue($settings->excludeCacheFiles());
        self::assertTrue($settings->skipLargeFiles());
        self::assertSame(250, $settings->largeFileLimitMb());
        self::assertSame(5, $settings->retentionCount());
        self::assertTrue($settings->autoCleanFailedJobs());
        self::assertFalse($settings->debugLogging());
    }

    public function testFromArraySanitizesAndClampsValues(): void
    {
        $settings = BackupSettings::fromArray(array(
            'exclude_cache_files' => '0',
            'skip_large_files' => '1',
            'large_file_limit_mb' => '9999',
            'retention_count' => '-4',
            'auto_clean_failed_jobs' => '',
            'debug_logging' => '1',
        ));

        self::assertFalse($settings->excludeCacheFiles());
        self::assertTrue($settings->skipLargeFiles());
        self::assertSame(2048, $settings->largeFileLimitMb());
        self::assertSame(1, $settings->retentionCount());
        self::assertFalse($settings->autoCleanFailedJobs());
        self::assertTrue($settings->debugLogging());
    }

    public function testToArrayUsesStableOptionKeys(): void
    {
        self::assertSame(array(
            'exclude_cache_files' => true,
            'skip_large_files' => true,
            'large_file_limit_mb' => 250,
            'retention_count' => 5,
            'auto_clean_failed_jobs' => true,
            'debug_logging' => false,
        ), BackupSettings::defaults()->toArray());
    }

    public function testSummaryLabelsDescribeFutureBackupDefaults(): void
    {
        self::assertSame(array(
            'Cache folders excluded',
            'Files over 250 MB skipped',
            'Keeping last 5 successful backups',
        ), BackupSettings::defaults()->summaryLabels());
    }

    public function testRepositoryLoadsDefaultsWhenOptionIsMissing(): void
    {
        $repository = new BackupSettingsRepository();

        self::assertSame(BackupSettings::defaults()->toArray(), $repository->get()->toArray());
    }

    public function testRepositorySavesSanitizedSettings(): void
    {
        $repository = new BackupSettingsRepository();

        $repository->save(BackupSettings::fromArray(array(
            'exclude_cache_files' => '0',
            'skip_large_files' => '1',
            'large_file_limit_mb' => '75',
            'retention_count' => '3',
            'auto_clean_failed_jobs' => '1',
            'debug_logging' => '0',
        )));

        self::assertSame(array(
            'exclude_cache_files' => false,
            'skip_large_files' => true,
            'large_file_limit_mb' => 75,
            'retention_count' => 3,
            'auto_clean_failed_jobs' => true,
            'debug_logging' => false,
        ), $GLOBALS['ssc_test_options'][BackupSettingsRepository::OPTION_NAME]);
    }
}
```

- [ ] **Step 2: Run failing test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupSettingsTest.php
```

Expected: fail because `SuperSheepCopy\Settings\BackupSettings` does not exist.

- [ ] **Step 3: Implement settings value object**

Create `super-sheep-copy/src/Settings/BackupSettings.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Settings;

final class BackupSettings
{
    private bool $exclude_cache_files;
    private bool $skip_large_files;
    private int $large_file_limit_mb;
    private int $retention_count;
    private bool $auto_clean_failed_jobs;
    private bool $debug_logging;

    public function __construct(
        bool $exclude_cache_files,
        bool $skip_large_files,
        int $large_file_limit_mb,
        int $retention_count,
        bool $auto_clean_failed_jobs,
        bool $debug_logging
    ) {
        $this->exclude_cache_files = $exclude_cache_files;
        $this->skip_large_files = $skip_large_files;
        $this->large_file_limit_mb = self::clamp($large_file_limit_mb, 10, 2048);
        $this->retention_count = self::clamp($retention_count, 1, 20);
        $this->auto_clean_failed_jobs = $auto_clean_failed_jobs;
        $this->debug_logging = $debug_logging;
    }

    public static function defaults(): self
    {
        return new self(true, true, 250, 5, true, false);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $defaults = self::defaults();

        return new self(
            self::boolValue($data, 'exclude_cache_files', $defaults->excludeCacheFiles()),
            self::boolValue($data, 'skip_large_files', $defaults->skipLargeFiles()),
            self::intValue($data, 'large_file_limit_mb', $defaults->largeFileLimitMb()),
            self::intValue($data, 'retention_count', $defaults->retentionCount()),
            self::boolValue($data, 'auto_clean_failed_jobs', $defaults->autoCleanFailedJobs()),
            self::boolValue($data, 'debug_logging', $defaults->debugLogging())
        );
    }

    public function excludeCacheFiles(): bool
    {
        return $this->exclude_cache_files;
    }

    public function skipLargeFiles(): bool
    {
        return $this->skip_large_files;
    }

    public function largeFileLimitMb(): int
    {
        return $this->large_file_limit_mb;
    }

    public function largeFileLimitBytes(): int
    {
        return $this->large_file_limit_mb * 1024 * 1024;
    }

    public function retentionCount(): int
    {
        return $this->retention_count;
    }

    public function autoCleanFailedJobs(): bool
    {
        return $this->auto_clean_failed_jobs;
    }

    public function debugLogging(): bool
    {
        return $this->debug_logging;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'exclude_cache_files' => $this->exclude_cache_files,
            'skip_large_files' => $this->skip_large_files,
            'large_file_limit_mb' => $this->large_file_limit_mb,
            'retention_count' => $this->retention_count,
            'auto_clean_failed_jobs' => $this->auto_clean_failed_jobs,
            'debug_logging' => $this->debug_logging,
        );
    }

    /**
     * @return string[]
     */
    public function summaryLabels(): array
    {
        $labels = array();
        $labels[] = $this->exclude_cache_files ? 'Cache folders excluded' : 'Cache folders included';
        $labels[] = $this->skip_large_files
            ? 'Files over ' . $this->large_file_limit_mb . ' MB skipped'
            : 'Large files included';
        $labels[] = 'Keeping last ' . $this->retention_count . ' successful backups';

        return $labels;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function boolValue(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return $value === 'on' || $value === 'yes' || $value === 'true';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function intValue(array $data, string $key, int $default): int
    {
        if (!isset($data[$key]) || !is_numeric($data[$key])) {
            return $default;
        }

        return (int) $data[$key];
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
```

- [ ] **Step 4: Implement repository**

Create `super-sheep-copy/src/Settings/BackupSettingsRepository.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Settings;

final class BackupSettingsRepository
{
    public const OPTION_NAME = 'super_sheep_copy_settings';

    public function get(): BackupSettings
    {
        $value = get_option(self::OPTION_NAME, array());
        if (!is_array($value)) {
            return BackupSettings::defaults();
        }

        return BackupSettings::fromArray($value);
    }

    public function save(BackupSettings $settings): bool
    {
        return update_option(self::OPTION_NAME, $settings->toArray(), false);
    }
}
```

- [ ] **Step 5: Run test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupSettingsTest.php
```

Expected: OK.

- [ ] **Step 6: Commit**

```bash
git add super-sheep-copy/src/Settings/BackupSettings.php super-sheep-copy/src/Settings/BackupSettingsRepository.php super-sheep-copy/tests/Unit/BackupSettingsTest.php
git commit -m "Add backup settings defaults"
```

---

### Task 2: Settings Page Form And Save Handler

**Files:**
- Modify: `super-sheep-copy/src/Admin/SettingsPage.php`
- Modify: `super-sheep-copy/templates/settings-page.php`
- Modify: `super-sheep-copy/src/Admin/AdminMenu.php`
- Test: `super-sheep-copy/tests/Unit/SettingsPageTest.php`

- [ ] **Step 1: Add failing render and save tests**

Update `super-sheep-copy/tests/Unit/SettingsPageTest.php` with these tests and helper setup:

```php
use SuperSheepCopy\Settings\BackupSettingsRepository;
```

Add to `setUp()`:

```php
$GLOBALS['ssc_test_options'] = array();
$GLOBALS['ssc_test_redirect'] = null;
$GLOBALS['ssc_test_nonce_valid'] = true;
$_POST = array();
$_REQUEST = array();
```

Replace construction in existing tests with:

```php
$page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository());
```

Add tests:

```php
public function testRenderShowsNormalUserSettingsSections(): void
{
    $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository());

    ob_start();
    $page->render();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Backup Defaults', $html);
    self::assertStringContainsString('Storage &amp; Cleanup', $html);
    self::assertStringContainsString('Diagnostics', $html);
    self::assertStringContainsString('name="super_sheep_copy_settings[exclude_cache_files]"', $html);
    self::assertStringContainsString('name="super_sheep_copy_settings[large_file_limit_mb]"', $html);
    self::assertStringContainsString('value="250"', $html);
}

public function testHandleActionsSavesSettings(): void
{
    $_POST['super_sheep_copy_action'] = 'save_settings';
    $_REQUEST['super_sheep_copy_action'] = 'save_settings';
    $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';
    $_POST['super_sheep_copy_settings'] = array(
        'exclude_cache_files' => '0',
        'skip_large_files' => '1',
        'large_file_limit_mb' => '75',
        'retention_count' => '3',
        'auto_clean_failed_jobs' => '1',
        'debug_logging' => '1',
    );

    $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository());
    $page->handleActions();

    self::assertSame(array(
        'exclude_cache_files' => false,
        'skip_large_files' => true,
        'large_file_limit_mb' => 75,
        'retention_count' => 3,
        'auto_clean_failed_jobs' => true,
        'debug_logging' => true,
    ), $GLOBALS['ssc_test_options'][BackupSettingsRepository::OPTION_NAME]);
    self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-settings&super_sheep_copy_status=settings_saved', $GLOBALS['ssc_test_redirect']);
}
```

- [ ] **Step 2: Run failing tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/SettingsPageTest.php
```

Expected: fail because constructor and `handleActions()` do not match.

- [ ] **Step 3: Update SettingsPage**

Modify `super-sheep-copy/src/Admin/SettingsPage.php`:

```php
use SuperSheepCopy\Plugin;
use SuperSheepCopy\Settings\BackupSettings;
use SuperSheepCopy\Settings\BackupSettingsRepository;
```

Add constants and repository property:

```php
private const ACTION_FIELD = 'super_sheep_copy_action';
private const ACTION_SAVE_SETTINGS = 'save_settings';
private const STATUS_FIELD = 'super_sheep_copy_status';

private BackupSettingsRepository $settings;
```

Change constructor:

```php
public function __construct(Capability $capability, Nonce $nonce, BackupSettingsRepository $settings)
{
    $this->capability = $capability;
    $this->nonce = $nonce;
    $this->settings = $settings;
}
```

Change `render()`:

```php
public function render(): void
{
    $this->capability->requireManageBackups();
    $settings = $this->settings->get();
    $status = isset($_GET[self::STATUS_FIELD]) ? sanitize_text_field(wp_unslash($_GET[self::STATUS_FIELD])) : '';
    $nonce_field = $this->nonce->field();
    $backup_storage_path = Plugin::backupDirectory();
    include SUPER_SHEEP_COPY_DIR . 'templates/settings-page.php';
}
```

Add action handling:

```php
public function handleActions(): void
{
    if (!$this->isSaveSettingsRequest()) {
        return;
    }

    $this->capability->assertManageBackups();
    $this->nonce->verifyRequest();

    $submitted = isset($_POST['super_sheep_copy_settings']) && is_array($_POST['super_sheep_copy_settings'])
        ? wp_unslash($_POST['super_sheep_copy_settings'])
        : array();

    $this->settings->save(BackupSettings::fromArray(is_array($submitted) ? $submitted : array()));
    $this->redirect('settings_saved');
}

private function isSaveSettingsRequest(): bool
{
    $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

    return $action === self::ACTION_SAVE_SETTINGS;
}

private function redirect(string $status): void
{
    wp_safe_redirect(add_query_arg(
        array(
            'page' => 'super-sheep-copy-settings',
            self::STATUS_FIELD => $status,
        ),
        admin_url('admin.php')
    ));
}
```

- [ ] **Step 4: Update settings template**

Replace `super-sheep-copy/templates/settings-page.php` body content after header with:

```php
<?php if ($status === 'settings_saved') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-success">
        <p><?php echo esc_html__('Settings saved.', 'super-sheep-copy'); ?></p>
    </div>
<?php endif; ?>

<form method="post">
    <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <input type="hidden" name="super_sheep_copy_action" value="save_settings" />

    <div class="super-sheep-copy-panel">
        <h2><?php echo esc_html__('Backup Defaults', 'super-sheep-copy'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__('Cache folders', 'super-sheep-copy'); ?></th>
                <td>
                    <label>
                        <input type="hidden" name="super_sheep_copy_settings[exclude_cache_files]" value="0" />
                        <input type="checkbox" name="super_sheep_copy_settings[exclude_cache_files]" value="1" <?php checked($settings->excludeCacheFiles()); ?> />
                        <?php echo esc_html__('Exclude cache folders', 'super-sheep-copy'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Large files', 'super-sheep-copy'); ?></th>
                <td>
                    <label>
                        <input type="hidden" name="super_sheep_copy_settings[skip_large_files]" value="0" />
                        <input type="checkbox" name="super_sheep_copy_settings[skip_large_files]" value="1" <?php checked($settings->skipLargeFiles()); ?> />
                        <?php echo esc_html__('Skip very large files', 'super-sheep-copy'); ?>
                    </label>
                    <input type="number" min="10" max="2048" name="super_sheep_copy_settings[large_file_limit_mb]" value="<?php echo esc_attr((string) $settings->largeFileLimitMb()); ?>" class="small-text" />
                    <span><?php echo esc_html__('MB', 'super-sheep-copy'); ?></span>
                    <p class="description"><?php echo esc_html__('Large media, archives, and logs can make backups fail on shared hosting.', 'super-sheep-copy'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Retention', 'super-sheep-copy'); ?></th>
                <td>
                    <input type="number" min="1" max="20" name="super_sheep_copy_settings[retention_count]" value="<?php echo esc_attr((string) $settings->retentionCount()); ?>" class="small-text" />
                    <span><?php echo esc_html__('successful backups', 'super-sheep-copy'); ?></span>
                </td>
            </tr>
        </table>
    </div>

    <div class="super-sheep-copy-panel">
        <h2><?php echo esc_html__('Storage & Cleanup', 'super-sheep-copy'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__('Backup storage', 'super-sheep-copy'); ?></th>
                <td><input type="text" class="regular-text" value="<?php echo esc_attr($backup_storage_path); ?>" readonly></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Failed backup files', 'super-sheep-copy'); ?></th>
                <td>
                    <label>
                        <input type="hidden" name="super_sheep_copy_settings[auto_clean_failed_jobs]" value="0" />
                        <input type="checkbox" name="super_sheep_copy_settings[auto_clean_failed_jobs]" value="1" <?php checked($settings->autoCleanFailedJobs()); ?> />
                        <?php echo esc_html__('Auto-clean failed backup files after 24 hours', 'super-sheep-copy'); ?>
                    </label>
                </td>
            </tr>
        </table>
    </div>

    <div class="super-sheep-copy-panel">
        <h2><?php echo esc_html__('Diagnostics', 'super-sheep-copy'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__('Debug logging', 'super-sheep-copy'); ?></th>
                <td>
                    <label>
                        <input type="hidden" name="super_sheep_copy_settings[debug_logging]" value="0" />
                        <input type="checkbox" name="super_sheep_copy_settings[debug_logging]" value="1" <?php checked($settings->debugLogging()); ?> />
                        <?php echo esc_html__('Enable debug logging', 'super-sheep-copy'); ?>
                    </label>
                </td>
            </tr>
        </table>
    </div>

    <?php submit_button(__('Save Settings', 'super-sheep-copy')); ?>
</form>
```

- [ ] **Step 5: Add WordPress helper stubs for form rendering**

Add to `super-sheep-copy/tests/bootstrap.php`:

```php
if (!function_exists('checked')) {
    function checked($checked, $current = true, bool $display = true): string
    {
        $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
        if ($display) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('submit_button')) {
    function submit_button($text = null): void
    {
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html((string) ($text ?: 'Save Changes')) . '</button></p>';
    }
}
```

- [ ] **Step 6: Wire AdminMenu**

Modify `super-sheep-copy/src/Admin/AdminMenu.php` imports:

```php
use SuperSheepCopy\Settings\BackupSettingsRepository;
```

Update `addMenu()` settings submenu:

```php
$settings_page = $this->settingsPage();
$settings_hook = add_submenu_page(
    'super-sheep-copy',
    __('Settings', 'super-sheep-copy'),
    __('Settings', 'super-sheep-copy'),
    Capability::MANAGE_BACKUPS,
    'super-sheep-copy-settings',
    array($settings_page, 'render')
);
add_action('load-' . $settings_hook, array($settings_page, 'handleActions'));
```

Update `settingsPage()`:

```php
private function settingsPage(): SettingsPage
{
    return new SettingsPage($this->capability, $this->nonce, new BackupSettingsRepository());
}
```

- [ ] **Step 7: Run tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/SettingsPageTest.php tests/Unit/AdminMenuTest.php
```

Expected: OK.

- [ ] **Step 8: Commit**

```bash
git add super-sheep-copy/src/Admin/SettingsPage.php super-sheep-copy/templates/settings-page.php super-sheep-copy/src/Admin/AdminMenu.php super-sheep-copy/tests/Unit/SettingsPageTest.php super-sheep-copy/tests/bootstrap.php
git commit -m "Add normal user settings form"
```

---

### Task 3: Backup Page Summary And Job Payload Snapshot

**Files:**
- Modify: `super-sheep-copy/src/Admin/BackupPage.php`
- Modify: `super-sheep-copy/templates/backup-page.php`
- Modify: `super-sheep-copy/src/Admin/AdminMenu.php`
- Test: `super-sheep-copy/tests/Unit/BackupPageTest.php`

- [ ] **Step 1: Add failing tests**

In `super-sheep-copy/tests/Unit/BackupPageTest.php`, import:

```php
use SuperSheepCopy\Settings\BackupSettings;
use SuperSheepCopy\Settings\BackupSettingsRepository;
```

Where `BackupPage` is constructed in tests, pass `new BackupSettingsRepository()` as the final constructor argument after existing optional services.

Add tests:

```php
public function testRenderShowsBackupSettingsSummary(): void
{
    update_option(BackupSettingsRepository::OPTION_NAME, BackupSettings::fromArray(array(
        'exclude_cache_files' => true,
        'skip_large_files' => true,
        'large_file_limit_mb' => 75,
        'retention_count' => 3,
        'auto_clean_failed_jobs' => true,
        'debug_logging' => false,
    ))->toArray(), false);

    $page = $this->makePage();

    ob_start();
    $page->render();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('Cache folders excluded', $html);
    self::assertStringContainsString('Files over 75 MB skipped', $html);
    self::assertStringContainsString('Keeping last 3 successful backups', $html);
}

public function testCreateBackupCopiesSettingsSnapshotIntoJobPayload(): void
{
    update_option(BackupSettingsRepository::OPTION_NAME, BackupSettings::fromArray(array(
        'exclude_cache_files' => false,
        'skip_large_files' => true,
        'large_file_limit_mb' => 100,
        'retention_count' => 4,
        'auto_clean_failed_jobs' => true,
        'debug_logging' => true,
    ))->toArray(), false);
    $_POST['super_sheep_copy_action'] = 'create_backup';
    $_REQUEST['super_sheep_copy_action'] = 'create_backup';
    $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

    $page = $this->makePage();
    $page->handleActions();

    $queued = $this->jobs->all()[0];
    self::assertSame(array(
        'exclude_cache_files' => false,
        'skip_large_files' => true,
        'large_file_limit_mb' => 100,
        'retention_count' => 4,
        'auto_clean_failed_jobs' => true,
        'debug_logging' => true,
    ), $queued->payload()['backup_settings']);
}
```

If the test class lacks `makePage()`, add or update a helper that returns:

```php
return new BackupPage(
    new Capability(),
    new Nonce(),
    $this->environment,
    $this->jobs,
    $this->backup_factory,
    $this->metadata_collector,
    null,
    new BackupSettingsRepository()
);
```

- [ ] **Step 2: Run failing tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupPageTest.php
```

Expected: fail because constructor/template lack settings summary and payload key.

- [ ] **Step 3: Update BackupPage constructor and render**

Modify `super-sheep-copy/src/Admin/BackupPage.php` imports:

```php
use SuperSheepCopy\Settings\BackupSettingsRepository;
```

Add property:

```php
private BackupSettingsRepository $settings;
```

Update constructor:

```php
public function __construct(
    Capability $capability,
    Nonce $nonce,
    EnvironmentCheckerInterface $environment_checker,
    JobRepositoryInterface $jobs,
    ?BackupManagerFactoryInterface $backup_factory = null,
    ?BackupMetadataCollectorInterface $metadata_collector = null,
    ?BackupJobFileCleaner $job_file_cleaner = null,
    ?BackupSettingsRepository $settings = null
) {
    $this->capability = $capability;
    $this->nonce = $nonce;
    $this->environment_checker = $environment_checker;
    $this->jobs = $jobs;
    $this->backup_factory = $backup_factory;
    $this->metadata_collector = $metadata_collector;
    $this->job_file_cleaner = $job_file_cleaner;
    $this->settings = $settings !== null ? $settings : new BackupSettingsRepository();
}
```

In `render()` before include:

```php
$backup_settings = $this->settings->get();
$backup_settings_summary = $backup_settings->summaryLabels();
```

In `handleCreateBackup()` before saving job:

```php
$backup_settings = $this->settings->get();
```

Add payload key:

```php
'backup_settings' => $backup_settings->toArray(),
```

- [ ] **Step 4: Update backup template summary**

In `super-sheep-copy/templates/backup-page.php` docblock add:

```php
 * @var string[] $backup_settings_summary
```

Inside the Create Backup block, before the form, add:

```php
<?php if ($backup_settings_summary !== array()) : ?>
    <ul class="super-sheep-copy-settings-summary">
        <?php foreach ($backup_settings_summary as $summary_item) : ?>
            <li><?php echo esc_html($summary_item); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
```

- [ ] **Step 5: Wire AdminMenu BackupPage**

In `super-sheep-copy/src/Admin/AdminMenu.php`, update `backupPage()`:

```php
return new BackupPage(
    $this->capability,
    $this->nonce,
    $this->environment_checker,
    $this->jobs,
    $this->backup_factory,
    $this->metadata_collector,
    null,
    new BackupSettingsRepository()
);
```

- [ ] **Step 6: Run tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupPageTest.php tests/Unit/AdminMenuTest.php
```

Expected: OK.

- [ ] **Step 7: Commit**

```bash
git add super-sheep-copy/src/Admin/BackupPage.php super-sheep-copy/templates/backup-page.php super-sheep-copy/src/Admin/AdminMenu.php super-sheep-copy/tests/Unit/BackupPageTest.php
git commit -m "Snapshot backup settings into jobs"
```

---

### Task 4: Apply Cache And Large-File Settings During File Scan

**Files:**
- Modify: `super-sheep-copy/src/Backup/FileScanner.php`
- Modify: `super-sheep-copy/src/Backup/BackupStepRunner.php`
- Test: `super-sheep-copy/tests/Unit/FileScannerTest.php`
- Test: `super-sheep-copy/tests/Unit/BackupStepRunnerTest.php`

- [ ] **Step 1: Add failing FileScanner tests**

In `super-sheep-copy/tests/Unit/FileScannerTest.php`, add:

```php
public function testScanStepCanIncludeCacheWhenSettingIsDisabled(): void
{
    $scanner = new FileScanner();
    $payload = array(
        'backup_settings' => array(
            'exclude_cache_files' => false,
            'skip_large_files' => false,
            'large_file_limit_mb' => 250,
        ),
    );

    while (empty($payload['file_scan_complete'])) {
        $payload = $scanner->scanStep($this->root, $payload, 10);
    }

    $paths = array_map(static function (array $file): string {
        return (string) $file['relative_path'];
    }, $payload['scanned_files']);
    sort($paths);

    self::assertContains('wp-content/cache/page.html', $paths);
}

public function testScanStepSkipsLargeFilesAndRecordsCount(): void
{
    file_put_contents($this->root . '/wp-content/uploads/large.bin', str_repeat('x', 12));

    $scanner = new FileScanner();
    $payload = array(
        'backup_settings' => array(
            'exclude_cache_files' => true,
            'skip_large_files' => true,
            'large_file_limit_mb' => 0,
        ),
    );

    while (empty($payload['file_scan_complete'])) {
        $payload = $scanner->scanStep($this->root, $payload, 10);
    }

    $paths = array_map(static function (array $file): string {
        return (string) $file['relative_path'];
    }, $payload['scanned_files']);

    self::assertNotContains('wp-content/uploads/large.bin', $paths);
    self::assertSame(3, $payload['skipped_large_file_count']);
    self::assertNotEmpty($payload['skipped_large_files']);
}
```

Note: `large_file_limit_mb` of `0` is used here to force all non-empty files through the skip path without creating huge test files.

- [ ] **Step 2: Run failing scanner tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/FileScannerTest.php
```

Expected: fail because scanner always excludes cache and does not skip large files from payload settings.

- [ ] **Step 3: Update FileScanner exclusion logic**

Modify `scanStep()` in `super-sheep-copy/src/Backup/FileScanner.php`:

```php
$settings = isset($payload['backup_settings']) && is_array($payload['backup_settings']) ? $payload['backup_settings'] : array();
$exclude_cache = !array_key_exists('exclude_cache_files', $settings) || (bool) $settings['exclude_cache_files'];
$skip_large_files = isset($settings['skip_large_files']) ? (bool) $settings['skip_large_files'] : true;
$large_file_limit_mb = isset($settings['large_file_limit_mb']) && is_numeric($settings['large_file_limit_mb']) ? (int) $settings['large_file_limit_mb'] : 250;
$large_file_limit_bytes = max(0, $large_file_limit_mb) * 1024 * 1024;
```

Change:

```php
if ($this->isExcluded($relative)) {
```

to:

```php
if ($this->isExcluded($relative, $exclude_cache)) {
```

Before appending `$files[]`, add:

```php
$size = (int) filesize($absolute);
if ($skip_large_files && $size > $large_file_limit_bytes) {
    if (!isset($payload['skipped_large_files']) || !is_array($payload['skipped_large_files'])) {
        $payload['skipped_large_files'] = array();
    }
    $payload['skipped_large_files'][] = array(
        'relative_path' => str_replace('\\', '/', $relative),
        'size' => $size,
    );
    $payload['skipped_large_file_count'] = count($payload['skipped_large_files']);
    continue;
}
```

Change file size assignment:

```php
'size' => $size,
```

Change `isExcluded()` signature and cache check:

```php
private function isExcluded(string $relative, bool $exclude_cache = true): bool
{
    $relative = trim(str_replace('\\', '/', $relative), '/');
    $name = basename($relative);
    if (in_array($name, $this->excluded_names, true)) {
        return true;
    }

    foreach ($this->excluded_segments as $segment) {
        if (!$exclude_cache && $segment === 'wp-content/cache') {
            continue;
        }

        if ($relative === $segment || strpos($relative, $segment . '/') === 0) {
            return true;
        }
    }

    return false;
}
```

- [ ] **Step 4: Update non-step scan method safely**

In `scan()`, keep existing safe behavior by calling:

```php
if ($this->isExcluded($relative, true)) {
    continue;
}
```

- [ ] **Step 5: Run scanner tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/FileScannerTest.php
```

Expected: OK.

- [ ] **Step 6: Add BackupStepRunner regression test**

In `super-sheep-copy/tests/Unit/BackupStepRunnerTest.php`, add or adapt a test around the file scanning state:

```php
public function testScanFilesUsesSettingsSnapshotFromPayload(): void
{
    mkdir($this->root . '/site/wp-content/uploads', 0777, true);
    file_put_contents($this->root . '/site/wp-content/uploads/large.bin', str_repeat('x', 12));
    $payload = $this->payload();
    $payload['database_directory'] = $this->root . '/work/backup-123/database';
    $payload['backup_settings'] = array(
        'exclude_cache_files' => true,
        'skip_large_files' => true,
        'large_file_limit_mb' => 0,
    );
    mkdir($payload['database_directory'] . '/chunks', 0777, true);
    file_put_contents($payload['database_directory'] . '/tables.json', '{}');
    file_put_contents($payload['database_directory'] . '/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');

    $runner = $this->runnerWithPackager(new BackupStepRunnerJobRepository(), new BackupStepRunnerPackager(), 100);
    $job = new Job('backup-123', 'backup', Job::SCANNING_FILES, $payload);
    do {
        $job = $runner->runStep($job);
    } while ($job->state() === Job::SCANNING_FILES);

    self::assertSame(3, $job->payload()['skipped_large_file_count']);
}
```

- [ ] **Step 7: Run runner tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupStepRunnerTest.php
```

Expected: OK.

- [ ] **Step 8: Commit**

```bash
git add super-sheep-copy/src/Backup/FileScanner.php super-sheep-copy/src/Backup/BackupStepRunner.php super-sheep-copy/tests/Unit/FileScannerTest.php super-sheep-copy/tests/Unit/BackupStepRunnerTest.php
git commit -m "Apply backup file scan settings"
```

---

### Task 5: Retention Cleanup After Successful Backups

**Files:**
- Create: `super-sheep-copy/src/Backup/BackupRetentionCleaner.php`
- Modify: `super-sheep-copy/src/Admin/BackupPage.php`
- Test: `super-sheep-copy/tests/Unit/BackupRetentionCleanerTest.php`

- [ ] **Step 1: Write failing retention cleaner tests**

Create `super-sheep-copy/tests/Unit/BackupRetentionCleanerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupRetentionCleaner;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class BackupRetentionCleanerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-retention-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testKeepsNewestSuccessfulBackupsAndDeletesOlderArchives(): void
    {
        $jobs = new InMemoryJobRepositoryForRetention(array(
            $this->job('backup-old', 100),
            $this->job('backup-mid', 200),
            $this->job('backup-new', 300),
        ));

        (new BackupRetentionCleaner($jobs, $this->root))->clean(2);

        self::assertNull($jobs->find('backup-old'));
        self::assertNotNull($jobs->find('backup-mid'));
        self::assertNotNull($jobs->find('backup-new'));
        self::assertFileDoesNotExist($this->root . '/backup-old.zip');
        self::assertFileExists($this->root . '/backup-mid.zip');
        self::assertFileExists($this->root . '/backup-new.zip');
    }

    public function testDoesNotDeleteFailedOrRunningJobs(): void
    {
        $failed = new Job('backup-failed', 'backup', Job::FAILED, array(
            'backup_completed_at' => 50,
            'archive_path' => $this->archive('backup-failed'),
        ));
        $jobs = new InMemoryJobRepositoryForRetention(array($failed, $this->job('backup-new', 300)));

        (new BackupRetentionCleaner($jobs, $this->root))->clean(1);

        self::assertNotNull($jobs->find('backup-failed'));
        self::assertFileExists($this->root . '/backup-failed.zip');
    }

    private function job(string $id, int $completed_at): Job
    {
        return new Job($id, 'backup', Job::COMPLETED, array(
            'backup_completed_at' => $completed_at,
            'archive_path' => $this->archive($id),
        ));
    }

    private function archive(string $id): string
    {
        $path = $this->root . '/' . $id . '.zip';
        file_put_contents($path, 'archive');

        return $path;
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

final class InMemoryJobRepositoryForRetention implements JobRepositoryInterface
{
    /** @var array<string,Job> */
    private array $jobs = array();

    /**
     * @param Job[] $jobs
     */
    public function __construct(array $jobs)
    {
        foreach ($jobs as $job) {
            $this->jobs[$job->id()] = $job;
        }
    }

    public function save(Job $job): void
    {
        $this->jobs[$job->id()] = $job;
    }

    public function find(string $id): ?Job
    {
        return $this->jobs[$id] ?? null;
    }

    public function delete(string $id): void
    {
        unset($this->jobs[$id]);
    }

    public function all(): array
    {
        return array_values($this->jobs);
    }
}
```

- [ ] **Step 2: Run failing test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupRetentionCleanerTest.php
```

Expected: fail because `BackupRetentionCleaner` does not exist.

- [ ] **Step 3: Implement retention cleaner**

Create `super-sheep-copy/src/Backup/BackupRetentionCleaner.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class BackupRetentionCleaner
{
    private JobRepositoryInterface $jobs;
    private BackupJobFileCleaner $file_cleaner;

    public function __construct(JobRepositoryInterface $jobs, string $backup_directory)
    {
        $this->jobs = $jobs;
        $this->file_cleaner = new BackupJobFileCleaner($backup_directory);
    }

    public function clean(int $keep): int
    {
        $keep = max(1, $keep);
        $completed = array_values(array_filter($this->jobs->all(), static function (Job $job): bool {
            return $job->type() === 'backup' && $job->state() === Job::COMPLETED && isset($job->payload()['archive_path']);
        }));

        usort($completed, static function (Job $a, Job $b): int {
            $a_payload = $a->payload();
            $b_payload = $b->payload();
            $a_time = isset($a_payload['backup_completed_at']) ? (int) $a_payload['backup_completed_at'] : 0;
            $b_time = isset($b_payload['backup_completed_at']) ? (int) $b_payload['backup_completed_at'] : 0;

            return $b_time <=> $a_time;
        });

        $deleted = 0;
        foreach (array_slice($completed, $keep) as $job) {
            $this->file_cleaner->clean($job);
            $this->jobs->delete($job->id());
            $deleted++;
        }

        return $deleted;
    }
}
```

- [ ] **Step 4: Wire cleanup after completed backup page render**

In `super-sheep-copy/src/Admin/BackupPage.php`, import:

```php
use SuperSheepCopy\Backup\BackupRetentionCleaner;
```

Add at start of `render()` after guard calls:

```php
$this->cleanSuccessfulBackupRetention();
```

Add method:

```php
private function cleanSuccessfulBackupRetention(): void
{
    $settings = $this->settings->get();
    (new BackupRetentionCleaner($this->jobs, Plugin::backupDirectory()))->clean($settings->retentionCount());
}
```

- [ ] **Step 5: Run tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupRetentionCleanerTest.php tests/Unit/BackupPageTest.php
```

Expected: OK.

- [ ] **Step 6: Commit**

```bash
git add super-sheep-copy/src/Backup/BackupRetentionCleaner.php super-sheep-copy/src/Admin/BackupPage.php super-sheep-copy/tests/Unit/BackupRetentionCleanerTest.php
git commit -m "Add successful backup retention cleanup"
```

---

### Task 6: Failed Backup Cleanup Controls

**Files:**
- Modify: `super-sheep-copy/src/Backup/BackupJobFileCleaner.php`
- Modify: `super-sheep-copy/src/Admin/SettingsPage.php`
- Modify: `super-sheep-copy/templates/settings-page.php`
- Test: `super-sheep-copy/tests/Unit/BackupJobFileCleanerTest.php`
- Test: `super-sheep-copy/tests/Unit/SettingsPageTest.php`

- [ ] **Step 1: Add failing cleaner test**

In `super-sheep-copy/tests/Unit/BackupJobFileCleanerTest.php`, add:

```php
public function testCleanFailedJobsDeletesOnlyFailedWorkingDirectories(): void
{
    $failed_dir = $this->makeDirectory('backup-failed');
    file_put_contents($failed_dir . '/partial.txt', 'partial');
    $completed_dir = $this->makeDirectory('backup-complete');
    file_put_contents($completed_dir . '/archive.zip', 'archive');

    $failed = new Job('backup-failed', 'backup', Job::FAILED, array(
        'working_directory' => $failed_dir,
        'updated_at' => gmdate('c', time() - 90000),
    ));
    $completed = new Job('backup-complete', 'backup', Job::COMPLETED, array(
        'working_directory' => $completed_dir,
        'archive_path' => $completed_dir . '/archive.zip',
        'updated_at' => gmdate('c', time() - 90000),
    ));

    $cleaner = new BackupJobFileCleaner($this->root);

    self::assertSame(1, $cleaner->cleanFailedJobs(array($failed, $completed), 86400));
    self::assertDirectoryDoesNotExist($failed_dir);
    self::assertDirectoryExists($completed_dir);
}
```

- [ ] **Step 2: Run failing cleaner test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupJobFileCleanerTest.php
```

Expected: fail because `cleanFailedJobs()` does not exist.

- [ ] **Step 3: Implement failed cleanup helper**

Add to `BackupJobFileCleaner`:

```php
/**
 * @param Job[] $jobs
 */
public function cleanFailedJobs(array $jobs, int $older_than_seconds = 86400): int
{
    $deleted = 0;
    $cutoff = time() - max(0, $older_than_seconds);

    foreach ($jobs as $job) {
        if (!$job instanceof Job || $job->type() !== 'backup' || $job->state() !== Job::FAILED) {
            continue;
        }

        $payload = $job->payload();
        $updated_at = isset($payload['updated_at']) && is_scalar($payload['updated_at']) ? strtotime((string) $payload['updated_at']) : false;
        if ($updated_at !== false && $updated_at > $cutoff) {
            continue;
        }

        $this->clean($job);
        $deleted++;
    }

    return $deleted;
}
```

- [ ] **Step 4: Add SettingsPage cleanup action tests**

In `SettingsPageTest`, add:

```php
public function testHandleActionsCleansFailedBackupFiles(): void
{
    $_POST['super_sheep_copy_action'] = 'clean_failed_jobs';
    $_REQUEST['super_sheep_copy_action'] = 'clean_failed_jobs';
    $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

    $jobs = new InMemoryJobRepositoryForSettings(array(
        new \SuperSheepCopy\Jobs\Job('backup-failed', 'backup', \SuperSheepCopy\Jobs\Job::FAILED, array(
            'working_directory' => sys_get_temp_dir() . '/ssc-missing-failed-job',
            'updated_at' => gmdate('c', time() - 90000),
        )),
    ));
    $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository(), $jobs);
    $page->handleActions();

    self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-settings&super_sheep_copy_status=failed_jobs_cleaned', $GLOBALS['ssc_test_redirect']);
}
```

- [ ] **Step 5: Implement cleanup action**

In `SettingsPage.php`, add constant:

```php
private const ACTION_CLEAN_FAILED_JOBS = 'clean_failed_jobs';
```

Add imports and property:

```php
use SuperSheepCopy\Backup\BackupJobFileCleaner;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
```

```php
private ?JobRepositoryInterface $jobs;
```

Update constructor:

```php
public function __construct(Capability $capability, Nonce $nonce, BackupSettingsRepository $settings, ?JobRepositoryInterface $jobs = null)
{
    $this->capability = $capability;
    $this->nonce = $nonce;
    $this->settings = $settings;
    $this->jobs = $jobs;
}
```

In `handleActions()`, before save branch:

```php
if ($this->isCleanFailedJobsRequest()) {
    $this->capability->assertManageBackups();
    $this->nonce->verifyRequest();
    $jobs = $this->jobs !== null ? $this->jobs->all() : array();
    (new BackupJobFileCleaner(Plugin::backupDirectory()))->cleanFailedJobs($jobs);
    $this->redirect('failed_jobs_cleaned');
    return;
}
```

Add method:

```php
private function isCleanFailedJobsRequest(): bool
{
    $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

    return $action === self::ACTION_CLEAN_FAILED_JOBS;
}
```

Update `AdminMenu::settingsPage()`:

```php
private function settingsPage(): SettingsPage
{
    return new SettingsPage($this->capability, $this->nonce, new BackupSettingsRepository(), $this->jobs);
}
```

- [ ] **Step 6: Update template with cleanup button and notice**

In `settings-page.php`, add notice:

```php
<?php elseif ($status === 'failed_jobs_cleaned') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-success">
        <p><?php echo esc_html__('Failed backup files cleaned.', 'super-sheep-copy'); ?></p>
    </div>
<?php endif; ?>
```

In Storage & Cleanup panel, add separate form after the settings form or use button with `formaction`:

```php
<form method="post">
    <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <input type="hidden" name="super_sheep_copy_action" value="clean_failed_jobs" />
    <button class="button" type="submit"><?php echo esc_html__('Clean failed backup files', 'super-sheep-copy'); ?></button>
</form>
```

- [ ] **Step 7: Run tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/BackupJobFileCleanerTest.php tests/Unit/SettingsPageTest.php
```

Expected: OK.

- [ ] **Step 8: Commit**

```bash
git add super-sheep-copy/src/Backup/BackupJobFileCleaner.php super-sheep-copy/src/Admin/SettingsPage.php super-sheep-copy/templates/settings-page.php super-sheep-copy/tests/Unit/BackupJobFileCleanerTest.php super-sheep-copy/tests/Unit/SettingsPageTest.php
git commit -m "Add failed backup cleanup control"
```

---

### Task 7: Diagnostics Report And Last Backup Summary

**Files:**
- Create: `super-sheep-copy/src/Settings/DiagnosticsReportBuilder.php`
- Modify: `super-sheep-copy/src/Admin/SettingsPage.php`
- Modify: `super-sheep-copy/templates/settings-page.php`
- Test: `super-sheep-copy/tests/Unit/DiagnosticsReportBuilderTest.php`
- Test: `super-sheep-copy/tests/Unit/SettingsPageTest.php`

- [ ] **Step 1: Write failing diagnostics tests**

Create `super-sheep-copy/tests/Unit/DiagnosticsReportBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Settings\DiagnosticsReportBuilder;

final class DiagnosticsReportBuilderTest extends TestCase
{
    public function testBuildReportIncludesSafeEnvironmentAndLastBackup(): void
    {
        $report = (new DiagnosticsReportBuilder())->build('/tmp/backups', array(
            new Job('backup-123', 'backup', Job::COMPLETED, array(
                'archive_size' => 1048576,
                'backup_total_seconds' => 12,
                'skipped_large_file_count' => 2,
            )),
        ));

        self::assertStringContainsString('Plugin version: 0.1.0', $report);
        self::assertStringContainsString('WordPress version: 6.5', $report);
        self::assertStringContainsString('PHP version:', $report);
        self::assertStringContainsString('Backup storage writable:', $report);
        self::assertStringContainsString('ZIP support:', $report);
        self::assertStringContainsString('Last backup: completed', $report);
        self::assertStringContainsString('Skipped large files: 2', $report);
    }

    public function testBuildReportDoesNotIncludeSecretLikePayloadValues(): void
    {
        $report = (new DiagnosticsReportBuilder())->build('/tmp/backups', array(
            new Job('backup-123', 'backup', Job::COMPLETED, array(
                'db_password' => 'secret-pass',
                'restore_token' => 'secret-token',
                'nonce' => 'secret-nonce',
            )),
        ));

        self::assertStringNotContainsString('secret-pass', $report);
        self::assertStringNotContainsString('secret-token', $report);
        self::assertStringNotContainsString('secret-nonce', $report);
    }
}
```

- [ ] **Step 2: Run failing tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DiagnosticsReportBuilderTest.php
```

Expected: fail because builder does not exist.

- [ ] **Step 3: Implement diagnostics report builder**

Create `super-sheep-copy/src/Settings/DiagnosticsReportBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Settings;

use SuperSheepCopy\Jobs\Job;

final class DiagnosticsReportBuilder
{
    /**
     * @param Job[] $jobs
     */
    public function build(string $backup_directory, array $jobs): string
    {
        $last_backup = $this->lastBackup($jobs);
        $lines = array(
            'Super Sheep Copy Diagnostics',
            'Plugin version: ' . (defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : 'unknown'),
            'WordPress version: ' . (function_exists('get_bloginfo') ? get_bloginfo('version') : 'unknown'),
            'PHP version: ' . PHP_VERSION,
            'Backup storage writable: ' . (is_writable($backup_directory) ? 'yes' : 'no'),
            'ZIP support: ' . (class_exists('ZipArchive') ? 'yes' : 'no'),
            'Memory limit: ' . (string) ini_get('memory_limit'),
            'Max execution time: ' . (string) ini_get('max_execution_time'),
        );

        if ($last_backup instanceof Job) {
            $payload = $last_backup->payload();
            $lines[] = 'Last backup: ' . $last_backup->state();
            $lines[] = 'Last backup size: ' . (isset($payload['archive_size']) ? (string) $payload['archive_size'] : 'unknown');
            $lines[] = 'Last backup duration: ' . (isset($payload['backup_total_seconds']) ? (string) $payload['backup_total_seconds'] : 'unknown');
            $lines[] = 'Skipped large files: ' . (isset($payload['skipped_large_file_count']) ? (string) (int) $payload['skipped_large_file_count'] : '0');
        } else {
            $lines[] = 'Last backup: none';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param Job[] $jobs
     */
    public function lastBackupSummary(array $jobs): string
    {
        $last_backup = $this->lastBackup($jobs);
        if (!$last_backup instanceof Job) {
            return 'No backups yet.';
        }

        $payload = $last_backup->payload();
        $size = isset($payload['archive_size']) ? (int) $payload['archive_size'] : 0;
        $seconds = isset($payload['backup_total_seconds']) ? (int) $payload['backup_total_seconds'] : 0;

        return $last_backup->state() . ' backup, ' . $size . ' bytes, ' . $seconds . ' seconds.';
    }

    /**
     * @param Job[] $jobs
     */
    private function lastBackup(array $jobs): ?Job
    {
        $backups = array_values(array_filter($jobs, static function ($job): bool {
            return $job instanceof Job && $job->type() === 'backup';
        }));

        usort($backups, static function (Job $a, Job $b): int {
            $a_payload = $a->payload();
            $b_payload = $b->payload();
            $a_time = isset($a_payload['backup_completed_at']) ? (int) $a_payload['backup_completed_at'] : 0;
            $b_time = isset($b_payload['backup_completed_at']) ? (int) $b_payload['backup_completed_at'] : 0;

            return $b_time <=> $a_time;
        });

        return $backups[0] ?? null;
    }
}
```

- [ ] **Step 4: Wire SettingsPage diagnostics variables and download action**

Add diagnostics import to `SettingsPage`. The `JobRepositoryInterface` property and constructor argument already exist from Task 6.

```php
use SuperSheepCopy\Settings\DiagnosticsReportBuilder;
```

In `render()`:

```php
$jobs = $this->jobs !== null ? $this->jobs->all() : array();
$diagnostics = new DiagnosticsReportBuilder();
$last_backup_summary = $diagnostics->lastBackupSummary($jobs);
```

Add constants/action:

```php
private const ACTION_DOWNLOAD_DIAGNOSTICS = 'download_diagnostics';
```

In `handleActions()` before save:

```php
if ($this->isDownloadDiagnosticsRequest()) {
    $this->capability->assertManageBackups();
    $this->nonce->verifyRequest();
    $jobs = $this->jobs !== null ? $this->jobs->all() : array();
    $report = (new DiagnosticsReportBuilder())->build(Plugin::backupDirectory(), $jobs);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="super-sheep-copy-diagnostics.txt"');
    echo $report;
    exit;
}
```

Add method:

```php
private function isDownloadDiagnosticsRequest(): bool
{
    $action = isset($_POST[self::ACTION_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::ACTION_FIELD])) : '';

    return $action === self::ACTION_DOWNLOAD_DIAGNOSTICS;
}
```

- [ ] **Step 5: Update Settings template diagnostics controls**

In Diagnostics panel, add:

```php
<tr>
    <th scope="row"><?php echo esc_html__('Last backup', 'super-sheep-copy'); ?></th>
    <td><?php echo esc_html($last_backup_summary); ?></td>
</tr>
<tr>
    <th scope="row"><?php echo esc_html__('Diagnostic report', 'super-sheep-copy'); ?></th>
    <td>
        <button class="button" type="submit" name="super_sheep_copy_action" value="download_diagnostics"><?php echo esc_html__('Download diagnostic report', 'super-sheep-copy'); ?></button>
    </td>
</tr>
```

Change the save submit button from `submit_button()` to an explicit button so diagnostics does not save settings:

```php
<p class="submit">
    <button class="button button-primary" type="submit" name="super_sheep_copy_action" value="save_settings"><?php echo esc_html__('Save Settings', 'super-sheep-copy'); ?></button>
</p>
```

- [ ] **Step 6: Wire AdminMenu jobs into SettingsPage**

Update `settingsPage()`:

```php
private function settingsPage(): SettingsPage
{
    return new SettingsPage($this->capability, $this->nonce, new BackupSettingsRepository(), $this->jobs);
}
```

- [ ] **Step 7: Add SettingsPage render test for diagnostics**

In `SettingsPageTest`, add:

```php
public function testRenderShowsLastBackupSummaryAndDiagnosticsButton(): void
{
    $jobs = new InMemoryJobRepositoryForSettings(array(
        new \SuperSheepCopy\Jobs\Job('backup-123', 'backup', \SuperSheepCopy\Jobs\Job::COMPLETED, array(
            'archive_size' => 1024,
            'backup_total_seconds' => 4,
            'backup_completed_at' => 10,
        )),
    ));
    $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository(), $jobs);

    ob_start();
    $page->render();
    $html = (string) ob_get_clean();

    self::assertStringContainsString('completed backup, 1024 bytes, 4 seconds.', $html);
    self::assertStringContainsString('Download diagnostic report', $html);
}
```

Add this test repository to the bottom of `SettingsPageTest.php`:

```php
final class InMemoryJobRepositoryForSettings implements \SuperSheepCopy\Jobs\JobRepositoryInterface
{
    /** @var array<string,\SuperSheepCopy\Jobs\Job> */
    private array $jobs = array();

    /**
     * @param \SuperSheepCopy\Jobs\Job[] $jobs
     */
    public function __construct(array $jobs = array())
    {
        foreach ($jobs as $job) {
            $this->jobs[$job->id()] = $job;
        }
    }

    public function save(\SuperSheepCopy\Jobs\Job $job): void
    {
        $this->jobs[$job->id()] = $job;
    }

    public function find(string $id): ?\SuperSheepCopy\Jobs\Job
    {
        return $this->jobs[$id] ?? null;
    }

    public function delete(string $id): void
    {
        unset($this->jobs[$id]);
    }

    public function all(): array
    {
        return array_values($this->jobs);
    }
}
```

- [ ] **Step 8: Run tests and lint**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/DiagnosticsReportBuilderTest.php tests/Unit/SettingsPageTest.php && composer run lint
```

Expected: OK and no PHP syntax errors.

- [ ] **Step 9: Run full test suite**

Run:

```bash
cd super-sheep-copy && composer test
```

Expected: OK.

- [ ] **Step 10: Commit**

```bash
git add super-sheep-copy/src/Settings/DiagnosticsReportBuilder.php super-sheep-copy/src/Admin/SettingsPage.php super-sheep-copy/templates/settings-page.php super-sheep-copy/src/Admin/AdminMenu.php super-sheep-copy/tests/Unit/DiagnosticsReportBuilderTest.php super-sheep-copy/tests/Unit/SettingsPageTest.php
git commit -m "Add settings diagnostics report"
```

---

## Final Verification

- [ ] Run full PHPUnit suite:

```bash
cd super-sheep-copy && composer test
```

Expected: OK.

- [ ] Run PHP lint:

```bash
cd super-sheep-copy && composer run lint
```

Expected: every PHP file reports `No syntax errors detected`.

- [ ] Check git status:

```bash
git status --short
```

Expected: only user-owned unrelated files remain modified, or clean if none existed.
