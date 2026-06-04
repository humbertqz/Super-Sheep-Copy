<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Admin\BackupPage;
use SuperSheepCopy\Backup\BackupJobFileCleaner;
use SuperSheepCopy\Backup\BackupManagerFactoryInterface;
use SuperSheepCopy\Backup\BackupMetadataCollectorInterface;
use SuperSheepCopy\Backup\BackupOptions;
use SuperSheepCopy\Backup\BackupResult;
use SuperSheepCopy\Backup\BackupRunnerInterface;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Settings\BackupSettings;
use SuperSheepCopy\Settings\BackupSettingsRepository;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;

final class BackupPageTest extends TestCase
{
    private ?string $root = null;

    protected function setUp(): void
    {
        $_POST = array();
        $_REQUEST = array();
        $GLOBALS['ssc_test_redirect'] = null;
        $GLOBALS['ssc_test_current_user_can'] = true;
        $GLOBALS['ssc_test_nonce_valid'] = true;
        $GLOBALS['ssc_test_options'] = array();
    }

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }
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
        self::assertStringContainsString('Create and monitor full-site backup packages.', $html);
        self::assertStringContainsString('Create a full-site package.', $html);
        self::assertStringNotContainsString('disabled', $html);
        self::assertStringContainsString('class="super-sheep-copy-header"', $html);
        self::assertTextBefore($html, '<h1 class="super-sheep-copy-screen-title">Super Sheep Copy Backup</h1>', 'class="super-sheep-copy-header"');
        self::assertHeaderDoesNotContain($html, '<h1>Super Sheep Copy Backup</h1>');
        self::assertHeaderContains($html, 'class="super-sheep-copy-header-title">Super Sheep Copy Backup</span>');
        self::assertStringContainsString('assets/images/super-sheep-copy-logo.png', $html);
        self::assertStringContainsString('alt="Super Sheep Copy"', $html);
        self::assertTextBefore($html, 'Backups contain sensitive site data', 'class="super-sheep-copy-header"');
        self::assertTextBefore($html, 'Backups contain sensitive site data', 'class="wrap super-sheep-copy"');
        self::assertStringContainsString('class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-warning"', $html);
        self::assertStringNotContainsString('class="notice notice-warning"', $html);
    }

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

        self::assertStringContainsString('Cache folders excluded', $html);
        self::assertStringContainsString('Files over 75 MB skipped', $html);
        self::assertStringContainsString('Keeping last 3 successful backups', $html);
    }

    public function testRenderAutoCleansOldFailedBackupFilesWhenEnabled(): void
    {
        update_option(BackupSettingsRepository::OPTION_NAME, BackupSettings::fromArray(array(
            'auto_clean_failed_jobs' => true,
        ))->toArray(), false);
        $failed_dir = $this->root() . '/failed-job';
        mkdir($failed_dir, 0777, true);
        file_put_contents($failed_dir . '/partial.txt', 'partial');
        $jobs = new BackupPageJobRepository(array(new Job('backup-failed', 'backup', Job::FAILED, array(
            'working_directory' => $failed_dir,
            'updated_at' => gmdate('c', time() - 90000),
        ))));
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            $jobs,
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector(),
            new BackupJobFileCleaner($this->root())
        );

        ob_start();
        $page->render();
        ob_end_clean();

        self::assertDirectoryDoesNotExist($failed_dir);
    }

    public function testRenderDoesNotDeleteCompletedBackupsForRetention(): void
    {
        update_option(BackupSettingsRepository::OPTION_NAME, BackupSettings::fromArray(array(
            'retention_count' => 1,
        ))->toArray(), false);
        $completed_dir = $this->root() . '/completed-job';
        mkdir($completed_dir, 0777, true);
        $archive = $completed_dir . '/backup.zip';
        file_put_contents($archive, 'archive');
        $jobs = new BackupPageJobRepository(array(new Job('backup-completed', 'backup', Job::COMPLETED, array(
            'working_directory' => $completed_dir,
            'archive_path' => $archive,
            'backup_completed_at' => 1,
        ))));
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            $jobs,
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector(),
            new BackupJobFileCleaner($this->root())
        );

        ob_start();
        $page->render();
        ob_end_clean();

        self::assertFileExists($archive);
        self::assertNotNull($jobs->find('backup-completed'));
    }

    public function testRenderOrdersBackupPanelsByPrimaryBlocks(): void
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

        self::assertHeadingBefore($html, 'Create Backup', 'Jobs');
        self::assertTextBefore($html, 'Create Backup', 'Current Backup');
        self::assertTextBefore($html, 'Current Backup', 'Backup Details');
        self::assertTextBefore($html, 'Backup Details', 'Jobs');
        self::assertStringNotContainsString('Backup Workflow', $html);
        self::assertStringNotContainsString('Run backup steps', $html);
        self::assertStringNotContainsString('Download archive', $html);
        self::assertStringNotContainsString('full-site backup archives', $html);
    }

    public function testRenderUsesSimpleBackupBlockStyling(): void
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

        self::assertStringContainsString('class="super-sheep-copy-backup-dashboard"', $html);
        self::assertStringContainsString('class="super-sheep-copy-backup-block super-sheep-copy-backup-block-primary"', $html);
        self::assertStringContainsString('class="super-sheep-copy-backup-block"', $html);
        self::assertStringNotContainsString('super-sheep-copy-workflow-step', $html);
    }

    public function testRenderShowsLatestJobProgressMessage(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(
                new Job('backup-123', 'backup', Job::EXPORTING_DATABASE, array(
                    'message' => 'Exporting table <wp_posts>',
                )),
            )),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Progress', $html);
        self::assertStringContainsString('Exporting table &lt;wp_posts&gt;', $html);
        self::assertStringNotContainsString('Exporting table <wp_posts>', $html);
    }

    public function testRenderShowsBackupThroughputAndBottleneck(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::PACKAGING_ARCHIVE, array(
                'message' => 'Packaged 500 of 1000 archive entries.',
                'archive_entries_per_second' => 10.0,
                'archive_mb_per_second' => 2.0,
                'archive_eta_seconds' => 60,
                'backup_bottleneck' => 'archive',
            )))),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('600 entries/min', $html);
        self::assertStringContainsString('120.0 MB/min', $html);
        self::assertStringContainsString('ETA 1m', $html);
        self::assertStringContainsString('Bottleneck: archive', $html);
    }

    public function testRenderCompletedBackupShowsAverageAndHidesRunningMetrics(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::COMPLETED, array(
                'message' => 'Backup completed.',
                'archive_size' => 2684354560,
                'backup_total_seconds' => 600,
                'archive_entries_per_second' => 565.35,
                'archive_mb_per_second' => 65.845,
                'archive_eta_seconds' => 0,
                'backup_bottleneck' => 'archive',
            )))),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Completed in 10m | Avg 256.0 MB/min', $html);
        self::assertStringNotContainsString('ETA 0s', $html);
        self::assertStringNotContainsString('Bottleneck: archive', $html);
        self::assertStringNotContainsString('3,950.7 MB/min', $html);
    }

    public function testRenderShowsCurrentBackupProgressStatusFromQueuedJob(): void
    {
        $_GET['job_id'] = 'backup-123';

        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(
                new Job('backup-123', 'backup', Job::CREATED, array(
                    'message' => 'Backup queued.',
                )),
            )),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-super-sheep-copy-current-progress', $html);
        self::assertStringContainsString('data-super-sheep-copy-current-progress-job="backup-123"', $html);
        self::assertStringContainsString('Backup progress', $html);
        self::assertStringContainsString('Backup queued.', $html);
        self::assertStringContainsString('role="progressbar"', $html);
    }

    public function testRenderHighlightsRunningBackupJobs(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(
                new Job('backup-123', 'backup', Job::SCANNING_FILES, array(
                    'message' => 'Scanning uploads',
                )),
            )),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-super-sheep-copy-running-summary', $html);
        self::assertStringContainsString('Backup running', $html);
        self::assertStringContainsString('class="super-sheep-copy-job-row is-running"', $html);
        self::assertStringContainsString('data-super-sheep-copy-job-state-label', $html);
        self::assertStringContainsString('data-super-sheep-copy-job-progress-message', $html);
        self::assertStringContainsString('data-super-sheep-copy-progress-bar', $html);
        self::assertStringContainsString('role="progressbar"', $html);
        self::assertStringContainsString('Scanning uploads', $html);
    }

    public function testRenderHidesRunningBackupSummaryWhenNoJobIsRunning(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::COMPLETED))),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-super-sheep-copy-running-summary hidden', $html);
        self::assertStringNotContainsString('class="super-sheep-copy-job-row is-running"', $html);
        self::assertStringContainsString('data-super-sheep-copy-progress-bar hidden', $html);
    }

    public function testRenderStopsRunningBackupJobFromDifferentSite(): void
    {
        $jobs = new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::EXPORTING_DATABASE, array(
            'site_root' => '/home/shotpruebas/public_html/aliacer/',
            'working_directory' => '/home/shotpruebas/public_html/aliacer/wp-content/uploads/super-sheep-copy/backup-123',
            'message' => 'Exporting database.',
        ))));
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            $jobs,
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        $job = $jobs->find('backup-123');
        self::assertInstanceOf(Job::class, $job);
        self::assertSame(Job::FAILED, $job->state());
        self::assertSame(Job::EXPORTING_DATABASE, $job->payload()['failed_state']);
        self::assertStringContainsString('Backup failed: job belongs to a different site or upload directory.', $html);
        self::assertStringNotContainsString('class="super-sheep-copy-job-row is-running"', $html);
    }

    public function testRenderShowsDeleteJobAction(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::COMPLETED))),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Actions', $html);
        self::assertStringContainsString('super-sheep-copy-jobs-table', $html);
        self::assertStringContainsString('Job</th>', $html);
        self::assertStringContainsString('Status</th>', $html);
        self::assertStringContainsString('Archive</th>', $html);
        self::assertStringNotContainsString('Type</th>', $html);
        self::assertStringNotContainsString('Validation</th>', $html);
        self::assertStringContainsString('value="delete_job"', $html);
        self::assertStringContainsString('name="job_id" value="backup-123"', $html);
        self::assertStringContainsString('data-super-sheep-copy-delete-job', $html);
        self::assertStringContainsString('Delete', $html);
    }

    public function testRenderShowsArchiveSizeAndDownloadActionForCompletedJob(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::COMPLETED, array(
                'archive_path' => '/tmp/backup-123.zip',
                'archive_size' => 1536,
            )))),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Archive', $html);
        self::assertStringNotContainsString('Size</th>', $html);
        self::assertStringContainsString('1.5 KB', $html);
        self::assertStringContainsString('value="download_backup"', $html);
        self::assertStringContainsString('Download backup', $html);
    }

    public function testRenderIncludesHiddenDownloadActionForRunningBackupJob(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::PACKAGING_ARCHIVE, array(
                'message' => 'Packaging archive.',
            )))),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-super-sheep-copy-download-job="backup-123" hidden', $html);
        self::assertStringContainsString('value="download_backup"', $html);
        self::assertStringContainsString('Download backup', $html);
    }

    public function testRenderShowsArchiveValidationStatusForCompletedJob(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::COMPLETED, array(
                'archive_path' => '/tmp/backup-123.zip',
                'archive_size' => 1536,
                'archive_validation_status' => 'valid',
            )))),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Archive', $html);
        self::assertStringNotContainsString('Validation</th>', $html);
        self::assertStringContainsString('Valid', $html);
    }

    public function testRenderShowsRetryContinueActionForFailedJob(): void
    {
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::FAILED, array(
                'message' => 'Backup failed: timeout',
                'failed_state' => Job::PACKAGING_ARCHIVE,
            )))),
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-super-sheep-copy-retry-job="backup-123"', $html);
        self::assertStringContainsString('Retry / Continue backup', $html);
    }

    public function testRenderPrunesRestoreJobWhenStagedArchiveIsMissing(): void
    {
        $jobs = new BackupPageJobRepository(array(
            new Job('restore-123', 'restore', Job::COMPLETED, array(
                'staged_archive' => 'restore-123.zip',
                'message' => 'Prepared restore-123.zip',
            )),
            new Job('backup-123', 'backup', Job::COMPLETED, array(
                'message' => 'Backup ready.',
            )),
        ));
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            $jobs,
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertSame(array('restore-123'), $jobs->deleted());
        self::assertNull($jobs->find('restore-123'));
        self::assertStringNotContainsString('restore-123.zip', $html);
        self::assertStringContainsString('backup-123', $html);
    }

    public function testPostDeletesJobAndRedirects(): void
    {
        $_POST['super_sheep_copy_action'] = 'delete_job';
        $_POST['job_id'] = 'backup-123';
        $_REQUEST['super_sheep_copy_action'] = 'delete_job';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

        $backup_directory = $this->makeDirectory('backups/super-sheep-copy');
        $working_directory = $this->makeDirectory('backups/super-sheep-copy/backup-123/database');
        $working_directory = dirname($working_directory);
        $archive_path = $working_directory . '/backup-123.zip';
        file_put_contents($working_directory . '/database/chunk-000001.sql', 'rows');
        file_put_contents($archive_path, 'zip');

        $jobs = new BackupPageJobRepository(array(new Job('backup-123', 'backup', Job::COMPLETED, array(
            'working_directory' => $working_directory,
            'archive_path' => $archive_path,
        ))));
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            $jobs,
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector(),
            new BackupJobFileCleaner($backup_directory)
        );

        $page->handleActions();

        self::assertDirectoryDoesNotExist($working_directory);
        self::assertSame(array('backup-123'), $jobs->deleted());
        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy&super_sheep_copy_status=job_deleted', $GLOBALS['ssc_test_redirect']);
    }

    public function testPostQueuesBackupAndRedirectsWithSuccess(): void
    {
        $_POST['super_sheep_copy_action'] = 'create_backup';
        $_REQUEST['super_sheep_copy_action'] = 'create_backup';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

        $jobs = new BackupPageJobRepository();
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            $jobs,
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        $page->handleActions();

        $queued = $jobs->all()[0];
        self::assertSame(Job::CREATED, $queued->state());
        self::assertSame(ABSPATH, $queued->payload()['site_root']);
        self::assertSame('wp_', $queued->payload()['table_prefix']);
        self::assertSame('prefixed', $queued->payload()['table_selection_mode']);
        self::assertSame(5000, $queued->payload()['database_chunk_size']);
        self::assertStringContainsString('/super-sheep-copy/' . $queued->id(), $queued->payload()['working_directory']);
        self::assertSame('Backup queued.', $queued->payload()['message']);
        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy&super_sheep_copy_status=backup_queued&job_id=' . $queued->id(), $GLOBALS['ssc_test_redirect']);
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

        $jobs = new BackupPageJobRepository();
        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            $jobs,
            new BackupPageFactory(new BackupPageRunner()),
            new BackupPageMetadataCollector()
        );

        $page->handleActions();

        $queued = $jobs->all()[0];
        self::assertSame(array(
            'exclude_cache_files' => false,
            'skip_large_files' => true,
            'large_file_limit_mb' => 100,
            'retention_count' => 4,
            'auto_clean_failed_jobs' => true,
            'debug_logging' => true,
        ), $queued->payload()['backup_settings']);
    }

    public function testPostRedirectsWithFailureWhenServicesMissing(): void
    {
        $_POST['super_sheep_copy_action'] = 'create_backup';
        $_REQUEST['super_sheep_copy_action'] = 'create_backup';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

        $page = new BackupPage(
            new Capability(),
            new Nonce(),
            new BackupPageEnvironmentChecker(),
            new BackupPageJobRepository(),
            null,
            null
        );

        $page->handleActions();

        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy&super_sheep_copy_status=backup_failed', $GLOBALS['ssc_test_redirect']);
    }

    private function makeDirectory(string $path): string
    {
        $directory = $this->root() . '/' . $path;
        mkdir($directory, 0777, true);

        return $directory;
    }

    private static function assertHeadingBefore(string $html, string $first, string $second): void
    {
        $firstPosition = strpos($html, '<h2>' . $first . '</h2>');
        $secondPosition = strpos($html, '<h2>' . $second . '</h2>');

        self::assertNotFalse($firstPosition, 'Missing heading: ' . $first);
        self::assertNotFalse($secondPosition, 'Missing heading: ' . $second);
        self::assertLessThan($secondPosition, $firstPosition, sprintf('Expected "%s" before "%s".', $first, $second));
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

    private function root(): string
    {
        if ($this->root === null) {
            $this->root = sys_get_temp_dir() . '/ssc-backup-page-test-' . bin2hex(random_bytes(6));
            mkdir($this->root, 0777, true);
        }

        return $this->root;
    }

    private function removeDirectory(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
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
    /** @var list<Job> */
    private array $jobs;

    /**
     * @param list<Job> $jobs
     */
    public function __construct(array $jobs = array())
    {
        $this->jobs = $jobs;
    }

    public function save(Job $job): void
    {
        foreach ($this->jobs as $index => $existing) {
            if ($existing->id() === $job->id()) {
                $this->jobs[$index] = $job;
                return;
            }
        }

        $this->jobs[] = $job;
    }

    public function delete(string $id): void
    {
        $this->deleted[] = $id;
        $this->jobs = array_values(array_filter($this->jobs, static function (Job $job) use ($id): bool {
            return $job->id() !== $id;
        }));
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

    /** @var string[] */
    private array $deleted = array();

    /**
     * @return string[]
     */
    public function deleted(): array
    {
        return $this->deleted;
    }
}
