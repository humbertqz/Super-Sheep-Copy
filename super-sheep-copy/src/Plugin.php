<?php

declare(strict_types=1);

namespace SuperSheepCopy;

use SuperSheepCopy\Admin\AdminMenu;
use SuperSheepCopy\Backup\BackupManagerFactory;
use SuperSheepCopy\Backup\BackupArchiveStepPackager;
use SuperSheepCopy\Backup\BackupMetadataCollector;
use SuperSheepCopy\Backup\BackupStepRunner;
use SuperSheepCopy\Backup\FileScanner;
use SuperSheepCopy\Backup\ManifestBuilder;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\SqlDumpFormatter;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClient;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;
use SuperSheepCopy\Jobs\OptionJobRepository;
use SuperSheepCopy\Restore\InstallerPreparationManager;
use SuperSheepCopy\Restore\RestorePreparationManager;
use SuperSheepCopy\Schedule\ScheduledBackupRunner;
use SuperSheepCopy\Schedule\ScheduleEventScheduler;
use SuperSheepCopy\Schedule\ScheduleSettingsRepository;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Settings\BackupSettingsRepository;
use SuperSheepCopy\Shared\Archive\ArchiveValidator;
use SuperSheepCopy\Support\EnvironmentChecker;
use SuperSheepCopy\Support\Filesystem;
use SuperSheepCopy\Support\NullLogger;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        Filesystem::ensureProtectedDirectory(self::backupDirectory());
    }

    public static function deactivate(): void
    {
        self::clearScheduledHooks();
    }

    public static function uninstall(): void
    {
        self::clearScheduledHooks();
        self::deletePluginOptions();
        Filesystem::removeDirectory(self::backupDirectory());
        self::deletePreparedInstallerFiles();
    }

    private static function clearScheduledHooks(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(ScheduleEventScheduler::DUE_HOOK);
            wp_clear_scheduled_hook(ScheduleEventScheduler::CONTINUE_HOOK);
        }
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        global $wpdb;

        $environment_checker = new EnvironmentChecker();
        $jobs = new OptionJobRepository();
        $metadata_collector = new BackupMetadataCollector($environment_checker);

        $scheduled_runner = new ScheduledBackupRunner(
            $jobs,
            new ScheduleSettingsRepository(),
            new BackupSettingsRepository(),
            $metadata_collector,
            $this->backupStepRunner($jobs, $wpdb),
            defined('ABSPATH') ? ABSPATH : '',
            self::backupDirectory()
        );
        $scheduled_runner->register();

        if (is_admin()) {
            $admin_menu = new AdminMenu(
                new Capability(),
                new Nonce(),
                $environment_checker,
                $jobs,
                new NullLogger(),
                new BackupManagerFactory($jobs, $wpdb),
                $metadata_collector,
                new RestorePreparationManager(
                    new ArchiveValidator(),
                    $jobs,
                    trailingslashit(self::backupDirectory()) . 'restore'
                ),
                new InstallerPreparationManager(
                    SUPER_SHEEP_COPY_DIR . 'installer',
                    ABSPATH,
                    trailingslashit(self::backupDirectory()) . 'restore',
                    site_url(),
                    $jobs
                )
            );
            $admin_menu->register();
        }
    }

    private function backupStepRunner(OptionJobRepository $jobs, $wpdb): BackupStepRunner
    {
        $wpdb_client = new WpdbClient($wpdb);
        $database_exporter = new WpdbDatabaseExporter($wpdb_client, new TableSelector());
        $packager = new BackupArchiveStepPackager(new ManifestBuilder(defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : '0.1.1', '1'));

        return new BackupStepRunner(
            $jobs,
            $database_exporter,
            new ChunkPlanner(),
            new SqlDumpFormatter(),
            new DatabaseExportManifestBuilder(),
            new FileScanner(),
            $packager
        );
    }

    public static function backupDirectory(): string
    {
        $uploads = wp_upload_dir(null, false);
        $base_dir = isset($uploads['basedir']) && is_string($uploads['basedir'])
            ? $uploads['basedir']
            : WP_CONTENT_DIR . '/uploads';

        return trailingslashit($base_dir) . 'super-sheep-copy';
    }

    private static function deletePluginOptions(): void
    {
        foreach (
            array(
                'super_sheep_copy_jobs',
                BackupSettingsRepository::OPTION_NAME,
                ScheduleSettingsRepository::OPTION_NAME,
            ) as $option
        ) {
            delete_option($option);
        }
    }

    private static function deletePreparedInstallerFiles(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        $root = rtrim((string) ABSPATH, '/\\');
        $installer = $root . '/installer.php';
        if (self::isPreparedInstallerFile($installer)) {
            Filesystem::deleteFile($installer);
        }

        Filesystem::removeDirectory($root . '/ssc-restore-engine');
    }

    private static function isPreparedInstallerFile(string $path): bool
    {
        if (!is_readable($path) || !is_file($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return false;
        }

        return strpos($contents, '/ssc-restore-engine/Bootstrap.php') !== false
            && strpos($contents, 'SuperSheepCopyInstaller\\Bootstrap::run') !== false;
    }
}
