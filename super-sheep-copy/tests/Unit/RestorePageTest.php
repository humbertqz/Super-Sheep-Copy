<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Admin\RestorePage;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Restore\InstallerPreparationManagerInterface;
use SuperSheepCopy\Restore\InstallerPreparationResult;
use SuperSheepCopy\Restore\RestorePreparationManagerInterface;
use SuperSheepCopy\Restore\RestorePreparationResult;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;
use SuperSheepCopy\Support\LoggerInterface;

final class RestorePageTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = array();
        $_POST = array();
        $_REQUEST = array();
        $_FILES = array();
        $GLOBALS['ssc_test_redirect'] = null;
        $GLOBALS['ssc_test_current_user_can'] = true;
        $GLOBALS['ssc_test_nonce_valid'] = true;
        $this->cleanRestoreDirectory();
    }

    public function testRenderShowsRestoreUploadForm(): void
    {
        $restore_directory = trailingslashit(\SuperSheepCopy\Plugin::backupDirectory()) . 'restore';
        if (!is_dir($restore_directory)) {
            mkdir($restore_directory, 0777, true);
        }
        file_put_contents($restore_directory . '/ftp-uploaded-backup.zip', 'zip');
        file_put_contents($restore_directory . '/ftp-uploaded-backup.tar.gz', 'tar');
        if (!is_dir($restore_directory . '/ftp-uploaded-package')) {
            mkdir($restore_directory . '/ftp-uploaded-package', 0777, true);
        }
        file_put_contents($restore_directory . '/ftp-uploaded-package/manifest.json', '{}');

        $page = new RestorePage(
            new Capability(),
            new Nonce(),
            new RestorePageEnvironmentChecker(),
            new RestorePageLogger(),
            new RestorePagePreparationManager(),
            new RestorePageJobRepository(),
            new RestorePageInstallerPreparationManager()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('name="super_sheep_copy_action"', $html);
        self::assertStringContainsString('value="prepare_restore"', $html);
        self::assertStringContainsString('name="super_sheep_copy_restore_archive"', $html);
        self::assertStringContainsString('Validate Backup', $html);
        self::assertStringContainsString('class="super-sheep-copy-backup-source-grid"', $html);
        self::assertStringContainsString('class="super-sheep-copy-backup-source-card"', $html);
        self::assertStringContainsString('Upload backup package', $html);
        self::assertStringContainsString('accept=".zip,.tar,.tar.gz,application/zip,application/gzip,application/x-tar"', $html);
        self::assertStringContainsString('Best for backups up to 64 MB.', $html);
        self::assertStringContainsString('Use package already in restore folder', $html);
        self::assertStringContainsString('Restore folder:', $html);
        self::assertStringContainsString($restore_directory, $html);
        self::assertStringNotContainsString('<select name="super_sheep_copy_staged_archive">', $html);
        self::assertStringContainsString('class="super-sheep-copy-staged-archive-list"', $html);
        self::assertStringContainsString('class="super-sheep-copy-staged-archive-row"', $html);
        self::assertStringContainsString('name="super_sheep_copy_staged_archive"', $html);
        self::assertStringContainsString('ftp-uploaded-backup.zip', $html);
        self::assertStringContainsString('ftp-uploaded-backup.tar.gz', $html);
        self::assertStringContainsString('ftp-uploaded-package', $html);
        self::assertStringContainsString('Use this backup', $html);
        self::assertStringContainsString('value="delete_staged_archive"', $html);
        self::assertStringContainsString('Delete', $html);
        self::assertStringNotContainsString('disabled', $html);
        self::assertStringContainsString('class="super-sheep-copy-header"', $html);
        self::assertStringContainsString('assets/images/super-sheep-copy-logo.png', $html);
        self::assertStringContainsString('alt="Super Sheep Copy"', $html);
        self::assertStringContainsString('Site Restore Workflow', $html);
        self::assertStringContainsString('class="super-sheep-copy-restore-workflow"', $html);
        self::assertStringContainsString('class="super-sheep-copy-workflow-step is-current"', $html);
        self::assertStringContainsString('Select backup', $html);
        self::assertTextBefore($html, 'Restore starts here by validating a backup package', 'class="super-sheep-copy-header"');
        self::assertTextBefore($html, 'Restore starts here by validating a backup package', 'class="wrap super-sheep-copy"');
        self::assertStringContainsString('class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-warning"', $html);
        self::assertStringNotContainsString('class="notice notice-warning"', $html);
    }

    public function testPostPreparesRestoreAndRedirectsWithSuccess(): void
    {
        $_POST['super_sheep_copy_action'] = 'prepare_restore';
        $_REQUEST['super_sheep_copy_action'] = 'prepare_restore';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';
        $_FILES['super_sheep_copy_restore_archive'] = array('name' => 'backup.zip', 'tmp_name' => '/tmp/backup.zip', 'error' => UPLOAD_ERR_OK, 'size' => 123);

        $jobs = new RestorePageJobRepository();
        $manager = new RestorePagePreparationManager(false, $jobs);
        $page = new RestorePage(
            new Capability(),
            new Nonce(),
            new RestorePageEnvironmentChecker(),
            new RestorePageLogger(),
            $manager,
            $jobs,
            new RestorePageInstallerPreparationManager()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertSame($_FILES['super_sheep_copy_restore_archive'], $manager->upload());
        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-restore&super_sheep_copy_status=restore_prepared&super_sheep_copy_restore_job_id=restore-123', $GLOBALS['ssc_test_redirect']);
        self::assertStringContainsString('Restore package validated and staged successfully.', $html);
        self::assertStringContainsString('Prepare installer', $html);
        self::assertStringContainsString('Validated Backup Details', $html);
        self::assertStringNotContainsString('name="super_sheep_copy_staged_archive"', $html);
        self::assertStringNotContainsString('class="super-sheep-copy-next-step"', $html);
        self::assertStringNotContainsString('class="super-sheep-copy-restore-steps"', $html);
    }

    public function testPostPreparesRestoreFromFtpUploadedArchive(): void
    {
        $restore_directory = trailingslashit(\SuperSheepCopy\Plugin::backupDirectory()) . 'restore';
        if (!is_dir($restore_directory)) {
            mkdir($restore_directory, 0777, true);
        }
        file_put_contents($restore_directory . '/ftp-uploaded-backup.zip', 'zip');

        $_POST['super_sheep_copy_action'] = 'prepare_restore';
        $_POST['super_sheep_copy_staged_archive'] = 'ftp-uploaded-backup.zip';
        $_REQUEST['super_sheep_copy_action'] = 'prepare_restore';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

        $manager = new RestorePagePreparationManager();
        $page = new RestorePage(
            new Capability(),
            new Nonce(),
            new RestorePageEnvironmentChecker(),
            new RestorePageLogger(),
            $manager,
            new RestorePageJobRepository(),
            new RestorePageInstallerPreparationManager()
        );

        ob_start();
        $page->render();
        ob_end_clean();

        self::assertSame(array(
            'name' => 'ftp-uploaded-backup.zip',
            'tmp_name' => $restore_directory . '/ftp-uploaded-backup.zip',
            'error' => UPLOAD_ERR_OK,
            'size' => 3,
        ), $manager->upload());
        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-restore&super_sheep_copy_status=restore_prepared&super_sheep_copy_restore_job_id=restore-123', $GLOBALS['ssc_test_redirect']);
    }

    public function testPostPreparesRestoreFromFtpUploadedTarGzPackage(): void
    {
        $restore_directory = trailingslashit(\SuperSheepCopy\Plugin::backupDirectory()) . 'restore';
        if (!is_dir($restore_directory)) {
            mkdir($restore_directory, 0777, true);
        }
        file_put_contents($restore_directory . '/ftp-uploaded-backup.tar.gz', 'tar');

        $_POST['super_sheep_copy_action'] = 'prepare_restore';
        $_POST['super_sheep_copy_staged_archive'] = 'ftp-uploaded-backup.tar.gz';
        $_REQUEST['super_sheep_copy_action'] = 'prepare_restore';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

        $manager = new RestorePagePreparationManager();
        $page = new RestorePage(
            new Capability(),
            new Nonce(),
            new RestorePageEnvironmentChecker(),
            new RestorePageLogger(),
            $manager,
            new RestorePageJobRepository(),
            new RestorePageInstallerPreparationManager()
        );

        ob_start();
        $page->render();
        ob_end_clean();

        self::assertSame(array(
            'name' => 'ftp-uploaded-backup.tar.gz',
            'tmp_name' => $restore_directory . '/ftp-uploaded-backup.tar.gz',
            'error' => UPLOAD_ERR_OK,
            'size' => 3,
        ), $manager->upload());
    }

    public function testPostDeletesFtpUploadedArchiveAndRedirectsWithSuccess(): void
    {
        $restore_directory = $this->restoreDirectory();
        file_put_contents($restore_directory . '/ftp-uploaded-backup.zip', 'zip');

        $_POST['super_sheep_copy_action'] = 'delete_staged_archive';
        $_POST['super_sheep_copy_staged_archive'] = 'ftp-uploaded-backup.zip';
        $_REQUEST['super_sheep_copy_action'] = 'delete_staged_archive';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

        $page = new RestorePage(
            new Capability(),
            new Nonce(),
            new RestorePageEnvironmentChecker(),
            new RestorePageLogger(),
            new RestorePagePreparationManager(),
            new RestorePageJobRepository(),
            new RestorePageInstallerPreparationManager()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertFileDoesNotExist($restore_directory . '/ftp-uploaded-backup.zip');
        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-restore&super_sheep_copy_status=backup_deleted', $GLOBALS['ssc_test_redirect']);
        self::assertStringContainsString('Backup package deleted from the restore folder.', $html);
    }

    public function testPostRejectsDeleteOutsideRestoreFolder(): void
    {
        $restore_directory = $this->restoreDirectory();
        $outside_path = dirname($restore_directory) . '/outside.zip';
        file_put_contents($restore_directory . '/ftp-uploaded-backup.zip', 'zip');
        file_put_contents($outside_path, 'outside');

        $_POST['super_sheep_copy_action'] = 'delete_staged_archive';
        $_POST['super_sheep_copy_staged_archive'] = '../outside.zip';
        $_REQUEST['super_sheep_copy_action'] = 'delete_staged_archive';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';

        $page = new RestorePage(
            new Capability(),
            new Nonce(),
            new RestorePageEnvironmentChecker(),
            new RestorePageLogger(),
            new RestorePagePreparationManager(),
            new RestorePageJobRepository(),
            new RestorePageInstallerPreparationManager()
        );

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertFileExists($outside_path);
        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-restore&super_sheep_copy_status=backup_delete_failed', $GLOBALS['ssc_test_redirect']);
        self::assertStringContainsString('Backup package deletion failed.', $html);

        unlink($outside_path);
    }

    public function testPostRedirectsWithFailureWhenPreparationFails(): void
    {
        $_POST['super_sheep_copy_action'] = 'prepare_restore';
        $_REQUEST['super_sheep_copy_action'] = 'prepare_restore';
        $_REQUEST['super_sheep_copy_nonce'] = 'test-nonce';
        $_FILES['super_sheep_copy_restore_archive'] = array('name' => 'backup.zip', 'tmp_name' => '/tmp/backup.zip', 'error' => UPLOAD_ERR_OK, 'size' => 123);

        $page = new RestorePage(
            new Capability(),
            new Nonce(),
            new RestorePageEnvironmentChecker(),
            new RestorePageLogger(),
            new RestorePagePreparationManager(true),
            new RestorePageJobRepository(),
            new RestorePageInstallerPreparationManager()
        );

        ob_start();
        $page->render();
        ob_end_clean();

        self::assertSame('https://example.com/wp-admin/admin.php?page=super-sheep-copy-restore&super_sheep_copy_status=restore_failed', $GLOBALS['ssc_test_redirect']);
    }

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
        self::assertStringContainsString('class="super-sheep-copy-restore-workflow"', $html);
        self::assertStringContainsString('Prepare installer', $html);
        self::assertStringContainsString('Backup is validated. Restore has not started.', $html);
        self::assertStringContainsString('Create the secure standalone installer before opening restore controls.', $html);
        self::assertStringContainsString('class="super-sheep-copy-workflow-step is-current"', $html);
        self::assertStringNotContainsString('class="super-sheep-copy-next-step"', $html);
        self::assertStringNotContainsString('class="super-sheep-copy-restore-steps"', $html);
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

        ob_start();
        $page->render();
        ob_end_clean();

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

        ob_start();
        $page->render();
        ob_end_clean();

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
        self::assertStringContainsString('Open installer', $html);
        self::assertStringContainsString('Confirm restore in standalone installer', $html);
        self::assertStringContainsString('class="super-sheep-copy-workflow-step is-current"', $html);
        self::assertStringNotContainsString('Installer Launch Link', $html);
    }

    private static function assertTextBefore(string $html, string $first, string $second): void
    {
        $firstPosition = strpos($html, $first);
        $secondPosition = strpos($html, $second);

        self::assertNotFalse($firstPosition, 'Missing text: ' . $first);
        self::assertNotFalse($secondPosition, 'Missing text: ' . $second);
        self::assertLessThan($secondPosition, $firstPosition, sprintf('Expected "%s" before "%s".', $first, $second));
    }

    private function restoreDirectory(): string
    {
        $restore_directory = trailingslashit(\SuperSheepCopy\Plugin::backupDirectory()) . 'restore';
        if (!is_dir($restore_directory)) {
            mkdir($restore_directory, 0777, true);
        }

        return $restore_directory;
    }

    private function cleanRestoreDirectory(): void
    {
        $restore_directory = trailingslashit(\SuperSheepCopy\Plugin::backupDirectory()) . 'restore';
        if (!is_dir($restore_directory)) {
            return;
        }

        $entries = scandir($restore_directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $restore_directory . '/' . $entry;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

final class RestorePagePreparationManager implements RestorePreparationManagerInterface
{
    /** @var array<string,mixed>|null */
    private ?array $upload = null;
    private bool $throw;
    private ?JobRepositoryInterface $jobs;

    public function __construct(bool $throw = false, ?JobRepositoryInterface $jobs = null)
    {
        $this->throw = $throw;
        $this->jobs = $jobs;
    }

    public function prepare(array $upload): RestorePreparationResult
    {
        if ($this->throw) {
            throw new \RuntimeException('restore failed');
        }

        $this->upload = $upload;
        if ($this->jobs instanceof JobRepositoryInterface) {
            $this->jobs->save(new Job('restore-123', 'restore', Job::COMPLETED, array(
                'staged_archive' => 'restore-123.zip',
                'source_site_url' => 'https://source.example',
                'source_home_url' => 'https://source.example',
                'database_entry_count' => 1,
                'archive_entry_count' => 3,
            )));
        }

        return new RestorePreparationResult('restore-123', 'restore-123.zip', 'https://source.example', 'https://source.example', 1, 3, 'completed');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function upload(): ?array
    {
        return $this->upload;
    }
}

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

    public function delete(string $id): void
    {
        unset($this->jobs[$id]);
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

final class RestorePageEnvironmentChecker implements EnvironmentCheckerInterface
{
    public function check(): array
    {
        return array('zip' => array('label' => 'ZIP', 'value' => 'Available', 'status' => 'ok'));
    }
}

final class RestorePageLogger implements LoggerInterface
{
    public function info(string $message, array $context = array()): void
    {
    }

    public function warning(string $message, array $context = array()): void
    {
    }

    public function error(string $message, array $context = array()): void
    {
    }
}
