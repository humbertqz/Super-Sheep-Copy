<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Admin\SettingsPage;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Plugin;
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
        self::assertStringContainsString('assets/images/super-sheep-copy-logo.png', $html);
        self::assertStringContainsString('alt="Super Sheep Copy"', $html);
        self::assertStringNotContainsString('Runtime dependencies', $html);
        self::assertStringNotContainsString('Composer autoloading with no runtime packages.', $html);
    }

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
