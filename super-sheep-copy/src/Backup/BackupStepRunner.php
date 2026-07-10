<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Step runner creates plugin-owned backup directories.

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RuntimeException;
use SuperSheepCopy\Backup\Database\ChunkPlan;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\SqlDumpFormatter;
use SuperSheepCopy\Backup\Database\TableSchema;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use Throwable;

final class BackupStepRunner implements BackupStepRunnerInterface
{
    private JobRepositoryInterface $jobs;
    private WpdbDatabaseExporter $database;
    private ChunkPlanner $chunk_planner;
    private SqlDumpFormatter $formatter;
    private DatabaseExportManifestBuilder $manifest_builder;
    private FileScanner $files;
    private BackupArchiveStepPackagerInterface $packager;
    private int $file_scan_batch_size;
    private AdaptiveBackupLimits $adaptive_limits;
    private IncrementalArchiveValidator $archive_validator;

    public function __construct(
        JobRepositoryInterface $jobs,
        WpdbDatabaseExporter $database,
        ChunkPlanner $chunk_planner,
        SqlDumpFormatter $formatter,
        DatabaseExportManifestBuilder $manifest_builder,
        FileScanner $files,
        BackupArchiveStepPackagerInterface $packager,
        int $file_scan_batch_size = 1000
    ) {
        $this->jobs = $jobs;
        $this->database = $database;
        $this->chunk_planner = $chunk_planner;
        $this->formatter = $formatter;
        $this->manifest_builder = $manifest_builder;
        $this->files = $files;
        $this->packager = $packager;
        $this->file_scan_batch_size = max(1, $file_scan_batch_size);
        $this->adaptive_limits = new AdaptiveBackupLimits();
        $this->archive_validator = new IncrementalArchiveValidator();
    }

    public function runStep(Job $job): Job
    {
        try {
            if ($job->state() === Job::CREATED) {
                return $this->startDatabaseExport($job);
            }

            if ($job->state() === Job::EXPORTING_DATABASE) {
                return $this->exportNextDatabaseChunk($job);
            }

            if ($job->state() === Job::SCANNING_FILES) {
                return $this->scanFiles($job);
            }

            if ($job->state() === Job::PACKAGING_ARCHIVE) {
                return $this->packageArchive($job);
            }

            if ($job->state() === Job::VALIDATING_BACKUP) {
                return $this->validateArchiveStep($job);
            }

            return $job;
        } catch (Throwable $throwable) {
            $payload = $job->payload();
            $payload['message'] = 'Backup failed: ' . $throwable->getMessage();
            $payload['error'] = $throwable->getMessage();
            $payload['failed_state'] = $job->state();

            return $this->save($job->id(), Job::FAILED, $payload);
        }
    }

    private function startDatabaseExport(Job $job): Job
    {
        $payload = $job->payload();
        if (!isset($payload['backup_started_at'])) {
            $payload['backup_started_at'] = time();
        }
        $working_directory = $this->stringPayload($payload, 'working_directory');
        $database_directory = $working_directory . '/database';
        $chunks_directory = $database_directory . '/chunks';

        $this->ensureDirectory($working_directory);
        $this->ensureDirectory($database_directory);
        $this->ensureDirectory($chunks_directory);

        $payload['database_directory'] = $database_directory;
        $payload['database_tables'] = $this->database->selectTables(
            $this->stringPayload($payload, 'table_prefix'),
            $this->stringPayload($payload, 'table_selection_mode')
        );
        $payload['database_table_index'] = 0;
        $payload['database_chunk_number'] = 1;
        $payload['database_last_seen_id'] = null;
        $payload['database_schemas'] = array();
        $payload['database_plans_by_table'] = array();
        $payload['message'] = 'Starting database export.';

        return $this->save($job->id(), Job::EXPORTING_DATABASE, $payload);
    }

    private function exportNextDatabaseChunk(Job $job): Job
    {
        $payload = $job->payload();
        $tables = isset($payload['database_tables']) && is_array($payload['database_tables']) ? array_values($payload['database_tables']) : array();
        $table_index = isset($payload['database_table_index']) ? (int) $payload['database_table_index'] : 0;

        if (!isset($tables[$table_index])) {
            $this->writeDatabaseManifest($payload);
            $payload['message'] = 'Database export finished.';

            return $this->save($job->id(), Job::SCANNING_FILES, $payload);
        }

        $table = (string) $tables[$table_index];
        $schema = $this->database->getSchema($table);
        $columns = $this->database->getColumns($table);
        $chunk_size = $schema->primaryKey() !== null && $schema->primaryKey() !== ''
            ? $this->adaptive_limits->databaseChunkSize($payload)
            : (int) $this->intPayload($payload, 'database_chunk_size');
        $payload['database_adaptive_chunk_size'] = $chunk_size;
        $chunk_count = max(1, (int) ceil($schema->rowCount() / $chunk_size));
        $chunk_number = isset($payload['database_chunk_number']) ? (int) $payload['database_chunk_number'] : 1;
        $last_seen_id = isset($payload['database_last_seen_id']) && $payload['database_last_seen_id'] !== null ? (int) $payload['database_last_seen_id'] : null;

        $plan = $this->chunk_planner->plan($schema, $chunk_size, $chunk_number, $last_seen_id);
        $step_start = microtime(true);
        $rows = $this->database->fetchRows($plan, $columns);
        $sql = $chunk_number === 1 ? $this->formatter->formatSchema($schema) : '';
        $sql .= $this->formatter->formatRows($rows);
        $this->writeChunk($this->stringPayload($payload, 'database_directory'), $plan->fileName(), $sql);
        $step_seconds = max(0.001, microtime(true) - $step_start);
        $step_rows = count($rows->rows());
        $payload['database_last_step_seconds'] = $step_seconds;
        $payload['database_last_step_rows'] = $step_rows;
        $payload['database_rows_per_second'] = $step_rows / $step_seconds;
        $payload['backup_bottleneck'] = (new BackupPerformanceMetrics())->bottleneck($payload);

        if (!isset($payload['database_schemas'][$schema->name()])) {
            $payload['database_schemas'][$schema->name()] = $this->schemaToArray($schema);
        }
        if (!isset($payload['database_plans_by_table'][$table]) || !is_array($payload['database_plans_by_table'][$table])) {
            $payload['database_plans_by_table'][$table] = array();
        }
        $payload['database_plans_by_table'][$table][] = $this->planToArray($plan);

        if ($plan->strategy() === ChunkPlan::STRATEGY_PRIMARY_KEY && $schema->primaryKey() !== null) {
            $last_seen_id = $this->lastSeenId($rows->rows(), $schema->primaryKey(), $last_seen_id);
        }

        if ($chunk_number >= $chunk_count) {
            $payload['database_table_index'] = $table_index + 1;
            $payload['database_chunk_number'] = 1;
            $payload['database_last_seen_id'] = null;
            $payload['message'] = 'Finished exporting table ' . $table . '.';
        } else {
            $payload['database_chunk_number'] = $chunk_number + 1;
            $payload['database_last_seen_id'] = $last_seen_id;
            $payload['message'] = 'Exported chunk ' . $chunk_number . ' of ' . $chunk_count . ' for table ' . $table . '.';
        }

        return $this->save($job->id(), Job::EXPORTING_DATABASE, $payload);
    }

    private function scanFiles(Job $job): Job
    {
        $payload = $job->payload();
        if (!isset($payload['scanned_files_path']) || !is_scalar($payload['scanned_files_path']) || (string) $payload['scanned_files_path'] === '') {
            $payload['scanned_files_path'] = rtrim($this->stringPayload($payload, 'working_directory'), '/\\') . '/files.jsonl';
        }
        $batch_size = $this->adaptive_limits->fileScanBatchSize($payload, $this->file_scan_batch_size);
        $payload['file_scan_adaptive_batch_size'] = $batch_size;
        $before_count = isset($payload['scanned_file_count']) ? (int) $payload['scanned_file_count'] : 0;
        $step_start = microtime(true);
        $payload = $this->files->scanStep($this->stringPayload($payload, 'site_root'), $payload, $batch_size);
        $step_seconds = max(0.001, microtime(true) - $step_start);
        $after_count = isset($payload['scanned_file_count']) ? (int) $payload['scanned_file_count'] : $before_count;
        $step_entries = max(0, $after_count - $before_count);
        $payload['file_scan_last_step_seconds'] = $step_seconds;
        $payload['file_scan_last_step_entries'] = $step_entries;
        $payload['file_scan_entries_per_second'] = $step_entries / $step_seconds;
        $payload['backup_bottleneck'] = (new BackupPerformanceMetrics())->bottleneck($payload);

        if (empty($payload['file_scan_complete'])) {
            return $this->save($job->id(), Job::SCANNING_FILES, $payload);
        }

        return $this->save($job->id(), Job::PACKAGING_ARCHIVE, $payload);
    }

    private function packageArchive(Job $job): Job
    {
        $payload = $job->payload();
        $metadata = isset($payload['manifest_metadata']) && is_array($payload['manifest_metadata']) ? $payload['manifest_metadata'] : array();
        $files = $this->scannedFilesFromPayload($payload);
        $payload = $this->packager->packageStep(
            $job->id(),
            $this->stringPayload($payload, 'working_directory'),
            $this->stringPayload($payload, 'database_directory'),
            $files,
            $metadata,
            $payload
        );

        if (empty($payload['archive_complete'])) {
            return $this->save($job->id(), Job::PACKAGING_ARCHIVE, $payload);
        }

        if (isset($payload['archive_validation_status']) && $payload['archive_validation_status'] === 'valid') {
            return $this->completeBackup($job->id(), $payload);
        }

        $payload['database_file_count'] = isset($payload['archive_database_file_count']) ? (int) $payload['archive_database_file_count'] : 0;
        $payload = $this->archive_validator->prepare($this->stringPayload($payload, 'archive_path'), $payload);
        $payload['message'] = 'Backup archive ready. Starting validation.';

        return $this->save($job->id(), Job::VALIDATING_BACKUP, $payload);
    }

    private function validateArchiveStep(Job $job): Job
    {
        $payload = $job->payload();
        $payload = $this->archive_validator->step($this->stringPayload($payload, 'archive_path'), $payload, 10.0);
        if (!$payload['validation_complete']) {
            return $this->save($job->id(), Job::VALIDATING_BACKUP, $payload);
        }

        if (!empty($payload['validation_errors'])) {
            $payload['archive_validation_status'] = 'invalid';
            $payload['archive_validation_errors'] = array_values(array_unique((array) $payload['validation_errors']));
            $payload['message'] = 'Backup archive validation failed.';

            return $this->save($job->id(), Job::FAILED, $payload);
        }

        $payload['archive_validation_status'] = 'valid';
        $payload['archive_validation_errors'] = array();
        $payload['message'] = 'Backup validation completed.';
        $payload['archive_complete'] = true;
        return $this->completeBackup($job->id(), $payload);
    }

    /** @param array<string,mixed> $payload */
    private function completeBackup(string $job_id, array $payload): Job
    {
        $completed_at = time();
        $started_at = isset($payload['backup_started_at']) && is_numeric($payload['backup_started_at']) ? (int) $payload['backup_started_at'] : $completed_at;
        $payload['backup_completed_at'] = $completed_at;
        $payload['backup_total_seconds'] = max(0, $completed_at - $started_at);
        $completed = $this->save($job_id, Job::COMPLETED, $payload);
        $this->cleanSuccessfulBackupRetention($payload);

        return $completed;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeDatabaseManifest(array $payload): void
    {
        $schemas = array();
        if (isset($payload['database_schemas']) && is_array($payload['database_schemas'])) {
            foreach ($payload['database_schemas'] as $schema) {
                if (is_array($schema)) {
                    $schemas[] = $this->schemaFromArray($schema);
                }
            }
        }

        $plans_by_table = array();
        if (isset($payload['database_plans_by_table']) && is_array($payload['database_plans_by_table'])) {
            foreach ($payload['database_plans_by_table'] as $table => $plans) {
                if (!is_array($plans)) {
                    continue;
                }
                $plans_by_table[(string) $table] = array();
                foreach ($plans as $plan) {
                    if (is_array($plan)) {
                        $plans_by_table[(string) $table][] = $this->planFromArray($plan);
                    }
                }
            }
        }

        $path = rtrim($this->stringPayload($payload, 'database_directory'), '/\\') . '/tables.json';
        $this->writeFile($path, (string) json_encode($this->manifest_builder->build($schemas, $plans_by_table), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function save(string $job_id, string $state, array $payload): Job
    {
        $payload['updated_at'] = gmdate('c');
        $job = new Job($job_id, 'backup', $state, $payload);
        $this->jobs->save($job);

        return $job;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function cleanSuccessfulBackupRetention(array $payload): void
    {
        $working_directory = isset($payload['working_directory']) && is_scalar($payload['working_directory'])
            ? (string) $payload['working_directory']
            : '';
        if ($working_directory === '') {
            return;
        }

        $settings = isset($payload['backup_settings']) && is_array($payload['backup_settings'])
            ? $payload['backup_settings']
            : array();
        $retention_count = isset($settings['retention_count']) && is_numeric($settings['retention_count'])
            ? (int) $settings['retention_count']
            : 5;

        (new BackupRetentionCleaner($this->jobs, dirname($working_directory)))->clean($retention_count);
    }

    private function writeChunk(string $database_directory, string $file_name, string $sql): void
    {
        if ($file_name === '' || strpos($file_name, '/') !== false || strpos($file_name, '\\') !== false || strpos($file_name, '..') !== false || substr($file_name, -4) !== '.sql') {
            throw new RuntimeException('Unsafe database chunk file name: ' . esc_html($file_name));
        }

        $this->writeFile(rtrim($database_directory, '/\\') . '/chunks/' . $file_name, $sql);
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . esc_html($directory));
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write file: ' . esc_html($path));
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function stringPayload(array $payload, string $key): string
    {
        if (!isset($payload[$key]) || !is_scalar($payload[$key]) || (string) $payload[$key] === '') {
            throw new RuntimeException('Missing backup payload value: ' . esc_html($key));
        }

        return (string) $payload[$key];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function intPayload(array $payload, string $key): int
    {
        if (!isset($payload[$key]) || (int) $payload[$key] < 1) {
            throw new RuntimeException('Missing backup payload value: ' . esc_html($key));
        }

        return (int) $payload[$key];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function fileFromArray(array $data): ScannedFile
    {
        return new ScannedFile(
            isset($data['absolute_path']) ? (string) $data['absolute_path'] : '',
            isset($data['relative_path']) ? (string) $data['relative_path'] : '',
            isset($data['size']) ? (int) $data['size'] : 0,
            isset($data['symlink']) ? (bool) $data['symlink'] : false
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return ScannedFile[]
     */
    private function scannedFilesFromPayload(array $payload): array
    {
        if (isset($payload['scanned_files_path']) && is_scalar($payload['scanned_files_path']) && (string) $payload['scanned_files_path'] !== '') {
            return $this->scannedFilesFromManifest((string) $payload['scanned_files_path']);
        }

        return array_map(array($this, 'fileFromArray'), isset($payload['scanned_files']) && is_array($payload['scanned_files']) ? $payload['scanned_files'] : array());
    }

    /**
     * @return ScannedFile[]
     */
    private function scannedFilesFromManifest(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Missing scanned files manifest: ' . esc_html($path));
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read scanned files manifest: ' . esc_html($path));
        }

        $files = array();
        foreach ($lines as $line) {
            $data = json_decode((string) $line, true);
            if (is_array($data)) {
                $files[] = $this->fileFromArray($data);
            }
        }

        usort($files, static function (ScannedFile $a, ScannedFile $b): int {
            return strcmp($a->relativePath(), $b->relativePath());
        });

        return $files;
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaToArray(TableSchema $schema): array
    {
        return array(
            'name' => $schema->name(),
            'create_sql' => $schema->createSql(),
            'primary_key' => $schema->primaryKey(),
            'row_count' => $schema->rowCount(),
            'charset' => $schema->charset(),
            'collation' => $schema->collation(),
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function schemaFromArray(array $data): TableSchema
    {
        return new TableSchema(
            isset($data['name']) ? (string) $data['name'] : '',
            isset($data['create_sql']) ? (string) $data['create_sql'] : '',
            isset($data['primary_key']) && $data['primary_key'] !== null ? (string) $data['primary_key'] : null,
            isset($data['row_count']) ? (int) $data['row_count'] : 0,
            isset($data['charset']) && $data['charset'] !== null ? (string) $data['charset'] : null,
            isset($data['collation']) && $data['collation'] !== null ? (string) $data['collation'] : null
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function planToArray(ChunkPlan $plan): array
    {
        return array(
            'table_name' => $plan->tableName(),
            'file_name' => $plan->fileName(),
            'strategy' => $plan->strategy(),
            'primary_key' => $plan->primaryKey(),
            'last_seen_id' => $plan->lastSeenId(),
            'limit' => $plan->limit(),
            'offset' => $plan->offset(),
            'chunk_number' => $plan->chunkNumber(),
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function planFromArray(array $data): ChunkPlan
    {
        return new ChunkPlan(
            isset($data['table_name']) ? (string) $data['table_name'] : '',
            isset($data['file_name']) ? (string) $data['file_name'] : '',
            isset($data['strategy']) ? (string) $data['strategy'] : ChunkPlan::STRATEGY_OFFSET,
            isset($data['primary_key']) && $data['primary_key'] !== null ? (string) $data['primary_key'] : null,
            isset($data['last_seen_id']) && $data['last_seen_id'] !== null ? (int) $data['last_seen_id'] : null,
            isset($data['limit']) ? (int) $data['limit'] : 1,
            isset($data['offset']) && $data['offset'] !== null ? (int) $data['offset'] : null,
            isset($data['chunk_number']) ? (int) $data['chunk_number'] : 1
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function lastSeenId(array $rows, string $primary_key, ?int $current): ?int
    {
        foreach ($rows as $row) {
            if (isset($row[$primary_key])) {
                $current = (int) $row[$primary_key];
            }
        }

        return $current;
    }
}
