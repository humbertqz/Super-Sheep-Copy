<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Plugin;
use SuperSheepCopy\Schedule\ScheduleEventScheduler;
use SuperSheepCopy\Schedule\ScheduleSettingsRepository;
use SuperSheepCopy\Settings\BackupSettingsRepository;

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ssc_test_options'] = array();
        $GLOBALS['ssc_test_scheduled_events'] = array(
            ScheduleEventScheduler::DUE_HOOK => array('timestamp' => 100, 'hook' => ScheduleEventScheduler::DUE_HOOK, 'args' => array()),
            ScheduleEventScheduler::CONTINUE_HOOK => array('timestamp' => 200, 'hook' => ScheduleEventScheduler::CONTINUE_HOOK, 'args' => array()),
        );

        $this->removePath(sys_get_temp_dir() . '/ssc-test-uploads');
        $this->removePath(ABSPATH);
    }

    public function testDeactivateClearsScheduledBackupHooks(): void
    {
        Plugin::deactivate();

        self::assertArrayNotHasKey(ScheduleEventScheduler::DUE_HOOK, $GLOBALS['ssc_test_scheduled_events']);
        self::assertArrayNotHasKey(ScheduleEventScheduler::CONTINUE_HOOK, $GLOBALS['ssc_test_scheduled_events']);
    }

    public function testUninstallClearsScheduledHooksAndPluginOptions(): void
    {
        update_option('super_sheep_copy_jobs', array('backup-123' => array()));
        update_option(BackupSettingsRepository::OPTION_NAME, array('retention_count' => 3));
        update_option(ScheduleSettingsRepository::OPTION_NAME, array('enabled' => true));
        update_option('unrelated_option', 'keep');

        Plugin::uninstall();

        self::assertArrayNotHasKey(ScheduleEventScheduler::DUE_HOOK, $GLOBALS['ssc_test_scheduled_events']);
        self::assertArrayNotHasKey(ScheduleEventScheduler::CONTINUE_HOOK, $GLOBALS['ssc_test_scheduled_events']);
        self::assertArrayNotHasKey('super_sheep_copy_jobs', $GLOBALS['ssc_test_options']);
        self::assertArrayNotHasKey(BackupSettingsRepository::OPTION_NAME, $GLOBALS['ssc_test_options']);
        self::assertArrayNotHasKey(ScheduleSettingsRepository::OPTION_NAME, $GLOBALS['ssc_test_options']);
        self::assertSame('keep', $GLOBALS['ssc_test_options']['unrelated_option']);
    }

    public function testUninstallDeletesPluginUploadStorage(): void
    {
        $backup_directory = Plugin::backupDirectory();
        mkdir($backup_directory . '/restore/package/database', 0777, true);
        file_put_contents($backup_directory . '/archive.zip', 'zip');
        file_put_contents($backup_directory . '/restore/package/database/chunk.sql', 'sql');

        Plugin::uninstall();

        self::assertDirectoryDoesNotExist($backup_directory);
    }

    public function testUninstallDeletesPreparedInstallerFiles(): void
    {
        mkdir(ABSPATH . '/ssc-restore-engine/nested', 0777, true);
        file_put_contents(ABSPATH . '/installer.php', "<?php\nrequire_once __DIR__ . '/ssc-restore-engine/Bootstrap.php';\n\\SuperSheepCopyInstaller\\Bootstrap::run();\n");
        file_put_contents(ABSPATH . '/ssc-restore-engine/config.php', "<?php\nreturn array();\n");
        file_put_contents(ABSPATH . '/ssc-restore-engine/nested/file.txt', 'restore');

        Plugin::uninstall();

        self::assertFileDoesNotExist(ABSPATH . '/installer.php');
        self::assertDirectoryDoesNotExist(ABSPATH . '/ssc-restore-engine');
    }

    public function testUninstallKeepsUnrelatedRootInstallerFile(): void
    {
        mkdir(ABSPATH, 0777, true);
        file_put_contents(ABSPATH . '/installer.php', "<?php\necho 'custom installer';\n");

        Plugin::uninstall();

        self::assertFileExists(ABSPATH . '/installer.php');
        self::assertSame("<?php\necho 'custom installer';\n", file_get_contents(ABSPATH . '/installer.php'));
    }

    protected function tearDown(): void
    {
        $this->removePath(sys_get_temp_dir() . '/ssc-test-uploads');
        $this->removePath(ABSPATH);
    }

    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
            $this->removePath($path . '/' . $item);
        }

        rmdir($path);
    }
}
