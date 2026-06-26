<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperSheepCopy\Admin\AdminMenu;
use SuperSheepCopy\Admin\BackupStepAjaxHandler;
use SuperSheepCopy\Backup\BackupStepRunnerInterface;
use SuperSheepCopy\Backup\BackupManagerFactoryInterface;
use SuperSheepCopy\Backup\BackupMetadataCollectorInterface;
use SuperSheepCopy\Backup\BackupRunnerInterface;
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

final class BackupStepAjaxHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        $_REQUEST = array();
        $GLOBALS['ssc_test_actions'] = array();
        $GLOBALS['ssc_test_json_response'] = null;
        $GLOBALS['ssc_test_current_user_can'] = true;
        $GLOBALS['ssc_test_nonce_valid'] = true;
    }

    public function testCapabilityFailureDoesNotSendJson(): void
    {
        $GLOBALS['ssc_test_current_user_can'] = false;
        $_REQUEST[Nonce::FIELD] = 'test-nonce';
        $_REQUEST['job_id'] = 'backup-123';

        $handler = new BackupStepAjaxHandler(new Capability(), new Nonce(), new BackupStepAjaxJobRepository(), new BackupStepAjaxRunner());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to manage backups.');

        $handler->handle();

        self::assertNull($GLOBALS['ssc_test_json_response']);
    }

    public function testNonceFailureDoesNotSendJson(): void
    {
        $GLOBALS['ssc_test_nonce_valid'] = false;
        $_REQUEST[Nonce::FIELD] = 'bad-nonce';
        $_REQUEST['job_id'] = 'backup-123';

        $handler = new BackupStepAjaxHandler(new Capability(), new Nonce(), new BackupStepAjaxJobRepository(), new BackupStepAjaxRunner());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Super Sheep Copy nonce.');

        $handler->handle();

        self::assertNull($GLOBALS['ssc_test_json_response']);
    }

    public function testMissingJobSendsJsonErrorWithNotFoundStatus(): void
    {
        $_REQUEST[Nonce::FIELD] = 'test-nonce';
        $_REQUEST['job_id'] = 'missing-job';

        $handler = new BackupStepAjaxHandler(new Capability(), new Nonce(), new BackupStepAjaxJobRepository(), new BackupStepAjaxRunner());

        try {
            $handler->handle();
        } catch (RuntimeException $exception) {
            self::assertSame('wp_send_json_error', $exception->getMessage());
        }

        self::assertSame(false, $GLOBALS['ssc_test_json_response']['success']);
        self::assertSame(404, $GLOBALS['ssc_test_json_response']['status_code']);
        self::assertSame(array('job_id' => 'missing-job'), $GLOBALS['ssc_test_json_response']['data']);
    }

    public function testFoundJobSendsQueuedJsonSuccessPayload(): void
    {
        $_REQUEST[Nonce::FIELD] = 'test-nonce';
        $_REQUEST['job_id'] = ' backup-123\\ ';
        $jobs = new BackupStepAjaxJobRepository(array(
            new Job('backup-123', 'backup', Job::EXPORTING_DATABASE, array('message' => 'Exporting database')),
        ));

        $runner = new BackupStepAjaxRunner(new Job('backup-123', 'backup', Job::SCANNING_FILES, array('message' => 'Database export finished')));
        $handler = new BackupStepAjaxHandler(new Capability(), new Nonce(), $jobs, $runner);

        try {
            $handler->handle();
        } catch (RuntimeException $exception) {
            self::assertSame('wp_send_json_success', $exception->getMessage());
        }

        self::assertSame(true, $GLOBALS['ssc_test_json_response']['success']);
        self::assertSame(array(
            'job_id' => 'backup-123',
            'state' => Job::SCANNING_FILES,
            'message' => 'Database export finished',
            'status' => 'queued',
        ), $GLOBALS['ssc_test_json_response']['data']);
        self::assertSame('backup-123', $runner->receivedJob()->id());
    }

    public function testRetryFailedJobRunsPreviousFailedState(): void
    {
        $_REQUEST[Nonce::FIELD] = 'test-nonce';
        $_REQUEST['job_id'] = 'backup-123';
        $_REQUEST['retry'] = '1';
        $jobs = new BackupStepAjaxJobRepository(array(
            new Job('backup-123', 'backup', Job::FAILED, array(
                'failed_state' => Job::PACKAGING_ARCHIVE,
                'message' => 'Backup failed: timeout',
            )),
        ));
        $runner = new BackupStepAjaxRunner(new Job('backup-123', 'backup', Job::PACKAGING_ARCHIVE, array('message' => 'Packaged 1 of 2 archive entries.')));
        $handler = new BackupStepAjaxHandler(new Capability(), new Nonce(), $jobs, $runner);

        try {
            $handler->handle();
        } catch (RuntimeException $exception) {
            self::assertSame('wp_send_json_success', $exception->getMessage());
        }

        self::assertSame(Job::PACKAGING_ARCHIVE, $runner->receivedJob()->state());
        self::assertSame(Job::PACKAGING_ARCHIVE, $GLOBALS['ssc_test_json_response']['data']['state']);
    }

    public function testRunnerExceptionMarksJobFailedWithJsonResponse(): void
    {
        $_REQUEST[Nonce::FIELD] = 'test-nonce';
        $_REQUEST['job_id'] = 'backup-123';
        $jobs = new BackupStepAjaxJobRepository(array(
            new Job('backup-123', 'backup', Job::PACKAGING_ARCHIVE, array('message' => 'Packaging archive.')),
        ));
        $handler = new BackupStepAjaxHandler(new Capability(), new Nonce(), $jobs, new BackupStepAjaxThrowingRunner());

        try {
            $handler->handle();
        } catch (RuntimeException $exception) {
            self::assertSame('wp_send_json_success', $exception->getMessage());
        }

        self::assertSame(true, $GLOBALS['ssc_test_json_response']['success']);
        self::assertSame(Job::FAILED, $GLOBALS['ssc_test_json_response']['data']['state']);
        self::assertSame('failed', $GLOBALS['ssc_test_json_response']['data']['status']);
        self::assertSame('Backup failed: archive close timeout', $GLOBALS['ssc_test_json_response']['data']['message']);
        self::assertSame(Job::PACKAGING_ARCHIVE, $jobs->find('backup-123')->payload()['failed_state']);
    }

    public function testForeignRunningBackupJobFailsBeforeRunnerExecutes(): void
    {
        $_REQUEST[Nonce::FIELD] = 'test-nonce';
        $_REQUEST['job_id'] = 'backup-123';
        $jobs = new BackupStepAjaxJobRepository(array(
            new Job('backup-123', 'backup', Job::EXPORTING_DATABASE, array(
                'site_root' => '/home/shotpruebas/public_html/aliacer/',
                'working_directory' => '/home/shotpruebas/public_html/aliacer/wp-content/uploads/super-sheep-copy/backup-123',
                'message' => 'Exporting database.',
            )),
        ));
        $runner = new BackupStepAjaxRunner(new Job('backup-123', 'backup', Job::SCANNING_FILES, array('message' => 'Should not run')));
        $handler = new BackupStepAjaxHandler(new Capability(), new Nonce(), $jobs, $runner);

        try {
            $handler->handle();
        } catch (RuntimeException $exception) {
            self::assertSame('wp_send_json_success', $exception->getMessage());
        }

        self::assertNull($runner->receivedJob());
        self::assertSame(Job::FAILED, $GLOBALS['ssc_test_json_response']['data']['state']);
        self::assertSame('Backup failed: job belongs to a different site or upload directory.', $GLOBALS['ssc_test_json_response']['data']['message']);
    }

    public function testAdminMenuRegistersBackupStepAjaxAction(): void
    {
        $menu = new AdminMenu(
            new Capability(),
            new Nonce(),
            new BackupStepAjaxEnvironmentChecker(),
            new BackupStepAjaxJobRepository(),
            new BackupStepAjaxLogger(),
            new BackupStepAjaxFactory(),
            new BackupStepAjaxMetadataCollector(),
            new BackupStepAjaxRestorePreparationManager(),
            new BackupStepAjaxInstallerPreparationManager()
        );

        $menu->register();

        self::assertArrayHasKey('wp_ajax_super_sheep_copy_run_backup_step', $GLOBALS['ssc_test_actions']);
        self::assertIsArray($GLOBALS['ssc_test_actions']['wp_ajax_super_sheep_copy_run_backup_step'][0]['callback']);
        self::assertInstanceOf(
            BackupStepAjaxHandler::class,
            $GLOBALS['ssc_test_actions']['wp_ajax_super_sheep_copy_run_backup_step'][0]['callback'][0]
        );
        self::assertSame('handle', $GLOBALS['ssc_test_actions']['wp_ajax_super_sheep_copy_run_backup_step'][0]['callback'][1]);
    }
}

final class BackupStepAjaxRunner implements BackupStepRunnerInterface
{
    private ?Job $result;
    private ?Job $received = null;

    public function __construct(?Job $result = null)
    {
        $this->result = $result;
    }

    public function runStep(Job $job): Job
    {
        $this->received = $job;

        return $this->result ?? $job;
    }

    public function receivedJob(): ?Job
    {
        return $this->received;
    }
}

final class BackupStepAjaxThrowingRunner implements BackupStepRunnerInterface
{
    public function runStep(Job $job): Job
    {
        throw new RuntimeException('archive close timeout');
    }
}

final class BackupStepAjaxJobRepository implements JobRepositoryInterface
{
    /** @var array<string,Job> */
    private array $jobs = array();

    /**
     * @param list<Job> $jobs
     */
    public function __construct(array $jobs = array())
    {
        foreach ($jobs as $job) {
            $this->jobs[$job->id()] = $job;
        }
    }

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

final class BackupStepAjaxEnvironmentChecker implements EnvironmentCheckerInterface
{
    public function check(): array
    {
        return array();
    }
}

final class BackupStepAjaxLogger implements LoggerInterface
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

final class BackupStepAjaxFactory implements BackupManagerFactoryInterface
{
    public function create(): BackupRunnerInterface
    {
        throw new RuntimeException('Not used by this test.');
    }
}

final class BackupStepAjaxMetadataCollector implements BackupMetadataCollectorInterface
{
    public function collect(): array
    {
        return array();
    }
}

final class BackupStepAjaxRestorePreparationManager implements RestorePreparationManagerInterface
{
    public function prepare(array $file): RestorePreparationResult
    {
        throw new RuntimeException('Not used by this test.');
    }
}

final class BackupStepAjaxInstallerPreparationManager implements InstallerPreparationManagerInterface
{
    public function prepare(string $restore_job_id): InstallerPreparationResult
    {
        throw new RuntimeException('Not used by this test.');
    }
}
