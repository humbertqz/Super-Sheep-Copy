<?php

declare(strict_types=1);

namespace SuperSheepCopy\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use SuperSheepCopy\Backup\BackupManagerFactoryInterface;
use SuperSheepCopy\Backup\BackupMetadataCollectorInterface;
use SuperSheepCopy\Backup\BackupArchiveStepPackager;
use SuperSheepCopy\Backup\BackupStepRunner;
use SuperSheepCopy\Backup\FileScanner;
use SuperSheepCopy\Backup\ManifestBuilder;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\SqlDumpFormatter;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClient;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Restore\InstallerPreparationManagerInterface;
use SuperSheepCopy\Restore\RestorePreparationManagerInterface;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;
use SuperSheepCopy\Settings\BackupSettingsRepository;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;
use SuperSheepCopy\Support\LoggerInterface;

final class AdminMenu
{
    private Capability $capability;
    private Nonce $nonce;
    private EnvironmentCheckerInterface $environment_checker;
    private JobRepositoryInterface $jobs;
    private LoggerInterface $logger;
    private BackupManagerFactoryInterface $backup_factory;
    private BackupMetadataCollectorInterface $metadata_collector;
    private RestorePreparationManagerInterface $restore_preparation;
    private InstallerPreparationManagerInterface $installer_preparation;
    /** @var object */
    private $wpdb;

    public function __construct(
        Capability $capability,
        Nonce $nonce,
        EnvironmentCheckerInterface $environment_checker,
        JobRepositoryInterface $jobs,
        LoggerInterface $logger,
        BackupManagerFactoryInterface $backup_factory,
        BackupMetadataCollectorInterface $metadata_collector,
        RestorePreparationManagerInterface $restore_preparation,
        InstallerPreparationManagerInterface $installer_preparation,
        $wpdb = null
    ) {
        $this->capability = $capability;
        $this->nonce = $nonce;
        $this->environment_checker = $environment_checker;
        $this->jobs = $jobs;
        $this->logger = $logger;
        $this->backup_factory = $backup_factory;
        $this->metadata_collector = $metadata_collector;
        $this->restore_preparation = $restore_preparation;
        $this->installer_preparation = $installer_preparation;
        $this->wpdb = $wpdb !== null ? $wpdb : (isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : new \stdClass());
    }

    public function register(): void
    {
        add_action('admin_menu', array($this, 'addMenu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
        add_action('wp_ajax_super_sheep_copy_run_backup_step', array($this->backupStepAjaxHandler(), 'handle'));
    }

    public function addMenu(): void
    {
        $backup_page = $this->backupPage();
        $backup_hook = add_menu_page(
            __('Super Sheep Copy', 'super-sheep-copy'),
            __('Super Sheep Copy', 'super-sheep-copy'),
            Capability::MANAGE_BACKUPS,
            'super-sheep-copy',
            array($backup_page, 'render'),
            'dashicons-migrate',
            80
        );
        add_action('load-' . $backup_hook, array($backup_page, 'handleActions'));

        add_submenu_page(
            'super-sheep-copy',
            __('Backup', 'super-sheep-copy'),
            __('Backup', 'super-sheep-copy'),
            Capability::MANAGE_BACKUPS,
            'super-sheep-copy'
        );

        add_submenu_page(
            'super-sheep-copy',
            __('Restore', 'super-sheep-copy'),
            __('Restore', 'super-sheep-copy'),
            Capability::MANAGE_BACKUPS,
            'super-sheep-copy-restore',
            array($this->restorePage(), 'render')
        );

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
    }

    public function enqueueAssets(string $hook): void
    {
        if (strpos($hook, 'super-sheep-copy') === false) {
            return;
        }

        wp_enqueue_style(
            'super-sheep-copy-admin',
            SUPER_SHEEP_COPY_URL . 'assets/admin.css',
            array(),
            SUPER_SHEEP_COPY_VERSION
        );

        wp_enqueue_script(
            'super-sheep-copy-admin',
            SUPER_SHEEP_COPY_URL . 'assets/admin.js',
            array(),
            SUPER_SHEEP_COPY_VERSION,
            true
        );
    }

    private function backupPage(): BackupPage
    {
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
    }

    private function restorePage(): RestorePage
    {
        return new RestorePage(
            $this->capability,
            $this->nonce,
            $this->environment_checker,
            $this->logger,
            $this->restore_preparation,
            $this->jobs,
            $this->installer_preparation
        );
    }

    private function settingsPage(): SettingsPage
    {
        return new SettingsPage($this->capability, $this->nonce, new BackupSettingsRepository(), $this->jobs);
    }

    private function backupStepAjaxHandler(): BackupStepAjaxHandler
    {
        $wpdb_client = new WpdbClient($this->wpdb);
        $database_exporter = new WpdbDatabaseExporter($wpdb_client, new TableSelector());
        $packager = new BackupArchiveStepPackager(new ManifestBuilder(defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : '0.1.0', '1'));

        return new BackupStepAjaxHandler(
            $this->capability,
            $this->nonce,
            $this->jobs,
            new BackupStepRunner(
                $this->jobs,
                $database_exporter,
                new ChunkPlanner(),
                new SqlDumpFormatter(),
                new DatabaseExportManifestBuilder(),
                new FileScanner(),
                $packager
            )
        );
    }
}
