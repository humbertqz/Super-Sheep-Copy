<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Admin\SettingsPage;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Plugin;
use SuperSheepCopy\Schedule\ScheduleSettings;
use SuperSheepCopy\Schedule\ScheduleSettingsRepository;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Settings\BackupSettingsRepository;

final class SettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ssc_test_options'] = array();
        $GLOBALS['ssc_test_redirect'] = null;
        $GLOBALS['ssc_test_current_user_can'] = true;
        $GLOBALS['ssc_test_nonce_valid'] = true;
        $GLOBALS['ssc_test_scheduled_events'] = array();
        $_POST = array();
        $_REQUEST = array();
        $_GET = array();
    }

    protected function tearDown(): void
    {
        $directory = Plugin::backupDirectory();
        if (is_dir($directory)) {
            $this->removeDirectory($directory);
        }
    }

    public function testRenderDoesNotShowRuntimeDependencies(): void
    {
        $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository());

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Backup storage', $html);
        self::assertStringContainsString('class="super-sheep-copy-header"', $html);
        self::assertTextBefore($html, '<h1 class="super-sheep-copy-screen-title">Super Sheep Copy Settings</h1>', 'class="super-sheep-copy-header"');
        self::assertHeaderDoesNotContain($html, '<h1>Super Sheep Copy Settings</h1>');
        self::assertHeaderContains($html, 'class="super-sheep-copy-header-title">Super Sheep Copy Settings</span>');
        self::assertStringContainsString('assets/images/super-sheep-copy-logo.png', $html);
        self::assertStringContainsString('alt="Super Sheep Copy"', $html);
        self::assertStringContainsString('class="super-sheep-copy-footer"', $html);
        self::assertStringContainsString('Version 0.1.0', $html);
        self::assertStringContainsString('href="https://github.com/humbertqz/Super-Sheep-Copy"', $html);
        self::assertTextBefore($html, 'class="super-sheep-copy-header"', 'class="super-sheep-copy-footer"');
        self::assertStringNotContainsString('Runtime dependencies', $html);
        self::assertStringNotContainsString('Composer autoloading with no runtime packages.', $html);
    }

    public function testRenderPlacesSettingsNoticeBeforePluginHeader(): void
    {
        $_GET['super_sheep_copy_status'] = 'settings_saved';
        $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository());

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertTextBefore($html, 'Settings saved.', 'class="super-sheep-copy-header"');
        self::assertTextBefore($html, 'Settings saved.', 'class="wrap super-sheep-copy"');
    }

    public function testRenderShowsNormalUserSettingsSections(): void
    {
        $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository());

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Backup Defaults', $html);
        self::assertStringContainsString('Automatic Backups', $html);
        self::assertStringContainsString('Storage &amp; Cleanup', $html);
        self::assertStringContainsString('Diagnostics', $html);
        self::assertStringContainsString('name="super_sheep_copy_settings[exclude_cache_files]"', $html);
        self::assertStringContainsString('name="super_sheep_copy_settings[large_file_limit_mb]"', $html);
        self::assertStringContainsString('value="250"', $html);
    }

    public function testRenderShowsScheduleSettingsOnSettingsPage(): void
    {
        (new ScheduleSettingsRepository())->save(ScheduleSettings::fromArray(array(
            'enabled' => true,
            'frequency' => 'weekly',
            'time_of_day' => '03:30',
        )));

        $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository());

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Automatic Backups', $html);
        self::assertStringContainsString('name="super_sheep_copy_schedule[enabled]"', $html);
        self::assertStringContainsString('value="weekly" selected', $html);
        self::assertStringContainsString('value="03:30"', $html);
        self::assertStringContainsString('Next run', $html);
        self::assertStringContainsString('No scheduled backup has run yet.', $html);
    }

    public function testRenderShowsLastBackupSummaryAndDiagnosticsButton(): void
    {
        $jobs = new InMemoryJobRepositoryForSettings(array(
            new Job('backup-123', 'backup', Job::COMPLETED, array(
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

    public function testRenderShowsCurrentStorageUsed(): void
    {
        $directory = Plugin::backupDirectory();
        mkdir($directory . '/nested', 0777, true);
        file_put_contents($directory . '/nested/file.bin', str_repeat('x', 2048));
        $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository());

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Current storage used', $html);
        self::assertStringContainsString('2 KB', $html);
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

    public function testHandleActionsSavesScheduleSettingsFromSettingsPage(): void
    {
        $_POST['super_sheep_copy_action'] = 'save_settings';
        $_REQUEST['super_sheep_copy_action'] = 'save_settings';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';
        $_POST['super_sheep_copy_settings'] = array(
            'exclude_cache_files' => '1',
            'skip_large_files' => '1',
            'large_file_limit_mb' => '250',
            'retention_count' => '2',
            'auto_clean_failed_jobs' => '1',
            'debug_logging' => '0',
        );
        $_POST['super_sheep_copy_schedule'] = array(
            'enabled' => '1',
            'frequency' => 'monthly',
            'time_of_day' => '04:15',
        );

        $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository());
        $page->handleActions();

        $schedule = (new ScheduleSettingsRepository())->get();
        self::assertTrue($schedule->enabled());
        self::assertSame('monthly', $schedule->frequency());
        self::assertSame('04:15', $schedule->timeOfDay());
        self::assertArrayHasKey('super_sheep_copy_scheduled_backup_due', $GLOBALS['ssc_test_scheduled_events']);
        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-settings&super_sheep_copy_status=settings_saved', $GLOBALS['ssc_test_redirect']);
    }

    public function testHandleActionsClearsScheduleWhenDisabledFromSettingsPage(): void
    {
        $GLOBALS['ssc_test_scheduled_events']['super_sheep_copy_scheduled_backup_due'] = array(
            'timestamp' => strtotime('2026-06-13 02:00:00 UTC'),
            'hook' => 'super_sheep_copy_scheduled_backup_due',
            'args' => array(),
        );
        $_POST['super_sheep_copy_action'] = 'save_settings';
        $_REQUEST['super_sheep_copy_action'] = 'save_settings';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';
        $_POST['super_sheep_copy_settings'] = array(
            'exclude_cache_files' => '1',
            'skip_large_files' => '1',
            'large_file_limit_mb' => '250',
            'retention_count' => '2',
            'auto_clean_failed_jobs' => '1',
            'debug_logging' => '0',
        );
        $_POST['super_sheep_copy_schedule'] = array(
            'enabled' => '0',
            'frequency' => 'daily',
            'time_of_day' => '02:00',
        );

        $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository());
        $page->handleActions();

        self::assertFalse((new ScheduleSettingsRepository())->get()->enabled());
        self::assertArrayNotHasKey('super_sheep_copy_scheduled_backup_due', $GLOBALS['ssc_test_scheduled_events']);
    }

    public function testHandleActionsAppliesLowerRetentionImmediately(): void
    {
        $_POST['super_sheep_copy_action'] = 'save_settings';
        $_REQUEST['super_sheep_copy_action'] = 'save_settings';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';
        $_POST['super_sheep_copy_settings'] = array(
            'exclude_cache_files' => '1',
            'skip_large_files' => '1',
            'large_file_limit_mb' => '250',
            'retention_count' => '1',
            'auto_clean_failed_jobs' => '1',
            'debug_logging' => '0',
        );

        $backup_directory = Plugin::backupDirectory();
        mkdir($backup_directory, 0777, true);
        $old_archive = $backup_directory . '/backup-old.zip';
        $new_archive = $backup_directory . '/backup-new.zip';
        file_put_contents($old_archive, 'old');
        file_put_contents($new_archive, 'new');
        $jobs = new InMemoryJobRepositoryForSettings(array(
            new Job('backup-old', 'backup', Job::COMPLETED, array('backup_completed_at' => 100, 'archive_path' => $old_archive)),
            new Job('backup-new', 'backup', Job::COMPLETED, array('backup_completed_at' => 200, 'archive_path' => $new_archive)),
        ));

        $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository(), $jobs);
        $page->handleActions();

        self::assertNull($jobs->find('backup-old'));
        self::assertNotNull($jobs->find('backup-new'));
        self::assertFileDoesNotExist($old_archive);
        self::assertFileExists($new_archive);
    }

    public function testHandleActionsCleansFailedBackupFiles(): void
    {
        $_POST['super_sheep_copy_action'] = 'clean_failed_jobs';
        $_REQUEST['super_sheep_copy_action'] = 'clean_failed_jobs';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

        $failed_directory = Plugin::backupDirectory() . '/backup-failed';
        mkdir($failed_directory, 0777, true);
        file_put_contents($failed_directory . '/partial.txt', 'partial');
        $jobs = new InMemoryJobRepositoryForSettings(array(
            new Job('backup-failed', 'backup', Job::FAILED, array(
                'working_directory' => $failed_directory,
                'updated_at' => gmdate('c'),
            )),
        ));
        $page = new SettingsPage(new Capability(), new Nonce(), new BackupSettingsRepository(), $jobs);
        $page->handleActions();

        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-settings&super_sheep_copy_status=failed_jobs_cleaned', $GLOBALS['ssc_test_redirect']);
        self::assertDirectoryDoesNotExist($failed_directory);
    }

    public function testHandleActionsDownloadsDiagnosticsWithoutSavingSettings(): void
    {
        $_POST['super_sheep_copy_action'] = 'download_diagnostics';
        $_REQUEST['super_sheep_copy_action'] = 'download_diagnostics';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';
        $_POST['super_sheep_copy_settings'] = array(
            'debug_logging' => '1',
        );

        $sent_report = null;
        $page = new SettingsPage(
            new Capability(),
            new Nonce(),
            new BackupSettingsRepository(),
            new InMemoryJobRepositoryForSettings(array()),
            static function (string $report) use (&$sent_report): void {
                $sent_report = $report;
            }
        );

        $page->handleActions();

        self::assertIsString($sent_report);
        self::assertStringContainsString('Super Sheep Copy Diagnostics', $sent_report);
        self::assertArrayNotHasKey(BackupSettingsRepository::OPTION_NAME, $GLOBALS['ssc_test_options']);
    }

    private static function assertTextBefore(string $html, string $first, string $second): void
    {
        $firstPosition = strpos($html, $first);
        $secondPosition = strpos($html, $second);

        self::assertNotFalse($firstPosition, 'Missing text: ' . $first);
        self::assertNotFalse($secondPosition, 'Missing text: ' . $second);
        self::assertLessThan($secondPosition, $firstPosition, sprintf('Expected "%s" before "%s".', $first, $second));
    }

    private static function assertHeaderDoesNotContain(string $html, string $needle): void
    {
        $headerPosition = strpos($html, 'class="super-sheep-copy-header"');
        self::assertNotFalse($headerPosition, 'Header was not found in HTML.');

        $headerEnd = strpos($html, '</div>', $headerPosition);
        self::assertNotFalse($headerEnd, 'Header end was not found in HTML.');

        $headerHtml = substr($html, $headerPosition, $headerEnd - $headerPosition);
        self::assertStringNotContainsString($needle, $headerHtml);
    }

    private static function assertHeaderContains(string $html, string $needle): void
    {
        $headerPosition = strpos($html, 'class="super-sheep-copy-header"');
        self::assertNotFalse($headerPosition, 'Header was not found in HTML.');

        $headerEnd = strpos($html, '</div>', $headerPosition);
        self::assertNotFalse($headerEnd, 'Header end was not found in HTML.');

        $headerHtml = substr($html, $headerPosition, $headerEnd - $headerPosition);
        self::assertStringContainsString($needle, $headerHtml);
    }

    private function removeDirectory(string $path): void
    {
        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
                continue;
            }
            unlink($child);
        }
        rmdir($path);
    }
}

final class InMemoryJobRepositoryForSettings implements JobRepositoryInterface
{
    /** @var list<Job> */
    private array $jobs;

    /**
     * @param list<Job> $jobs
     */
    public function __construct(array $jobs)
    {
        $this->jobs = $jobs;
    }

    public function save(Job $job): void
    {
        $this->jobs[] = $job;
    }

    public function delete(string $id): void
    {
        $this->jobs = array_values(array_filter(
            $this->jobs,
            static fn (Job $job): bool => $job->id() !== $id
        ));
    }

    public function find(string $id): ?Job
    {
        foreach ($this->jobs as $job) {
            if ($job->id() === $id) {
                return $job;
            }
        }

        return null;
    }

    public function all(): array
    {
        return $this->jobs;
    }
}
