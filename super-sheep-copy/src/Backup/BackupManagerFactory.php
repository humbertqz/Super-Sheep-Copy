<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseBackupCoordinator;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\DatabaseExportWriter;
use SuperSheepCopy\Backup\Database\SqlDumpFormatter;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClient;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;
use SuperSheepCopy\Backup\Package\PackageWriterFactory;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class BackupManagerFactory implements BackupManagerFactoryInterface
{
    private JobRepositoryInterface $jobs;
    /** @var object */
    private $wpdb;

    /**
     * @param object $wpdb
     */
    public function __construct(JobRepositoryInterface $jobs, $wpdb)
    {
        $this->jobs = $jobs;
        $this->wpdb = $wpdb;
    }

    public function create(): BackupRunnerInterface
    {
        $wpdb_client = new WpdbClient($this->wpdb);
        $database_exporter = new WpdbDatabaseExporter($wpdb_client, new TableSelector());
        $database_writer = new DatabaseExportWriter(new DatabaseExportManifestBuilder());
        $progress = new JobBackupProgressReporter($this->jobs);
        $database = new DatabaseBackupCoordinator(
            $database_exporter,
            new ChunkPlanner(),
            new SqlDumpFormatter(),
            $database_writer,
            $progress
        );

        $packager = new BackupArchivePackager(
            new ArchiveWriter(),
            new ManifestBuilder(defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : '0.1.0', '1'),
            new PackageWriterFactory()
        );

        return new BackupManager($this->jobs, $database, new FileScanner(), $packager, $progress);
    }
}
