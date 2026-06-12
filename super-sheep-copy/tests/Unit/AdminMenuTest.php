<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Admin\AdminMenu;
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

final class AdminMenuTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ssc_test_menu_pages'] = array();
        $GLOBALS['ssc_test_submenu_pages'] = array();
        $GLOBALS['ssc_test_admin_page_callbacks'] = array();
    }

    public function testBackupPageRegistersOneRenderCallbackForSharedTopLevelHook(): void
    {
        $menu = new AdminMenu(
            new Capability(),
            new Nonce(),
            new AdminMenuEnvironmentChecker(),
            new AdminMenuJobRepository(),
            new AdminMenuLogger(),
            new AdminMenuBackupFactory(),
            new AdminMenuMetadataCollector(),
            new AdminMenuRestorePreparationManager(),
            new AdminMenuInstallerPreparationManager()
        );

        $menu->addMenu();

        self::assertCount(1, $GLOBALS['ssc_test_admin_page_callbacks']['toplevel_page_super-sheep-copy']);
        self::assertSame('Backup', $GLOBALS['ssc_test_submenu_pages'][0]['menu_title']);
    }

    public function testBackupActionsRunOnLoadHookBeforeAdminOutput(): void
    {
        $menu = new AdminMenu(
            new Capability(),
            new Nonce(),
            new AdminMenuEnvironmentChecker(),
            new AdminMenuJobRepository(),
            new AdminMenuLogger(),
            new AdminMenuBackupFactory(),
            new AdminMenuMetadataCollector(),
            new AdminMenuRestorePreparationManager(),
            new AdminMenuInstallerPreparationManager()
        );

        $menu->addMenu();

        self::assertArrayHasKey('load-toplevel_page_super-sheep-copy', $GLOBALS['ssc_test_actions']);
        self::assertIsArray($GLOBALS['ssc_test_actions']['load-toplevel_page_super-sheep-copy'][0]['callback']);
        self::assertSame('handleActions', $GLOBALS['ssc_test_actions']['load-toplevel_page_super-sheep-copy'][0]['callback'][1]);
    }

    public function testDoesNotRegisterSeparateScheduleSubmenu(): void
    {
        $menu = new AdminMenu(
            new Capability(),
            new Nonce(),
            new AdminMenuEnvironmentChecker(),
            new AdminMenuJobRepository(),
            new AdminMenuLogger(),
            new AdminMenuBackupFactory(),
            new AdminMenuMetadataCollector(),
            new AdminMenuRestorePreparationManager(),
            new AdminMenuInstallerPreparationManager()
        );

        $menu->addMenu();

        $schedule_pages = array_values(array_filter($GLOBALS['ssc_test_submenu_pages'], static function (array $page): bool {
            return $page['menu_slug'] === 'super-sheep-copy-schedule';
        }));

        self::assertSame(array(), $schedule_pages);
        self::assertArrayNotHasKey('load-super-sheep-copy_page_super-sheep-copy-schedule', $GLOBALS['ssc_test_actions']);
    }
}

final class AdminMenuEnvironmentChecker implements EnvironmentCheckerInterface
{
    public function check(): array
    {
        return array();
    }
}

final class AdminMenuJobRepository implements JobRepositoryInterface
{
    public function save(Job $job): void
    {
    }

    public function delete(string $id): void
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

final class AdminMenuLogger implements LoggerInterface
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

final class AdminMenuBackupFactory implements BackupManagerFactoryInterface
{
    public function create(): BackupRunnerInterface
    {
        throw new \RuntimeException('Not used by this test.');
    }
}

final class AdminMenuMetadataCollector implements BackupMetadataCollectorInterface
{
    public function collect(): array
    {
        return array();
    }
}

final class AdminMenuRestorePreparationManager implements RestorePreparationManagerInterface
{
    public function prepare(array $file): RestorePreparationResult
    {
        throw new \RuntimeException('Not used by this test.');
    }
}

final class AdminMenuInstallerPreparationManager implements InstallerPreparationManagerInterface
{
    public function prepare(string $restore_job_id): InstallerPreparationResult
    {
        throw new \RuntimeException('Not used by this test.');
    }
}
