<?php

declare(strict_types=1);

namespace SuperSheepCopy;

use SuperSheepCopy\Admin\AdminMenu;
use SuperSheepCopy\Backup\BackupManagerFactory;
use SuperSheepCopy\Backup\BackupMetadataCollector;
use SuperSheepCopy\Jobs\OptionJobRepository;
use SuperSheepCopy\Restore\InstallerPreparationManager;
use SuperSheepCopy\Restore\RestorePreparationManager;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
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
        // Intentionally no cleanup. Backup and job data are private user data.
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        if (is_admin()) {
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
                new BackupMetadataCollector($environment_checker),
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

    public static function backupDirectory(): string
    {
        $uploads = wp_upload_dir(null, false);
        $base_dir = isset($uploads['basedir']) && is_string($uploads['basedir'])
            ? $uploads['basedir']
            : WP_CONTENT_DIR . '/uploads';

        return trailingslashit($base_dir) . 'super-sheep-copy';
    }
}
