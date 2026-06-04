<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;
use SuperSheepCopy\Backup\BackupProgressReporterInterface;
use SuperSheepCopy\Jobs\Job;

final class DatabaseBackupCoordinator implements DatabaseBackupCoordinatorInterface
{
    private WpdbDatabaseExporter $exporter;
    private ChunkPlanner $chunk_planner;
    private SqlDumpFormatter $formatter;
    private DatabaseExportWriter $writer;
    private ?BackupProgressReporterInterface $progress;

    public function __construct(
        WpdbDatabaseExporter $exporter,
        ChunkPlanner $chunk_planner,
        SqlDumpFormatter $formatter,
        DatabaseExportWriter $writer,
        ?BackupProgressReporterInterface $progress = null
    ) {
        $this->exporter = $exporter;
        $this->chunk_planner = $chunk_planner;
        $this->formatter = $formatter;
        $this->writer = $writer;
        $this->progress = $progress;
    }

    public function export(string $working_directory, string $table_prefix, string $selection_mode, int $chunk_size, ?string $job_id = null): void
    {
        if ($chunk_size < 1) {
            throw new InvalidArgumentException('Chunk size must be greater than zero.');
        }

        $schemas = array();
        $plans_by_table = array();
        $sql_by_chunk = array();

        foreach ($this->exporter->selectTables($table_prefix, $selection_mode) as $table) {
            $schema = $this->exporter->getSchema($table);
            $columns = $this->exporter->getColumns($table);
            $schemas[] = $schema;

            $chunk_count = max(1, (int) ceil($schema->rowCount() / $chunk_size));
            $this->report($job_id, array(
                'phase' => 'database',
                'step' => 'table_started',
                'table' => $table,
                'chunk_total' => $chunk_count,
                'message' => 'Exporting table ' . $table,
            ));

            $plans_by_table[$table] = array();
            $last_seen_id = null;

            for ($chunk_number = 1; $chunk_number <= $chunk_count; $chunk_number++) {
                $this->report($job_id, array(
                    'phase' => 'database',
                    'step' => 'chunk_started',
                    'table' => $table,
                    'chunk' => $chunk_number,
                    'chunk_total' => $chunk_count,
                    'message' => 'Exporting chunk ' . $chunk_number . ' of ' . $chunk_count . ' for table ' . $table,
                ));

                $plan = $this->chunk_planner->plan($schema, $chunk_size, $chunk_number, $last_seen_id);
                $rows = $this->exporter->fetchRows($plan, $columns);
                $plans_by_table[$table][] = $plan;

                $sql = $chunk_number === 1 ? $this->formatter->formatSchema($schema) : '';
                $sql .= $this->formatter->formatRows($rows);
                $sql_by_chunk[$plan->fileName()] = $sql;

                if ($plan->strategy() === ChunkPlan::STRATEGY_PRIMARY_KEY && $schema->primaryKey() !== null) {
                    $last_seen_id = $this->lastSeenId($rows, $schema->primaryKey(), $last_seen_id);
                }

                $this->report($job_id, array(
                    'phase' => 'database',
                    'step' => 'chunk_finished',
                    'table' => $table,
                    'chunk' => $chunk_number,
                    'chunk_total' => $chunk_count,
                    'message' => 'Finished chunk ' . $chunk_number . ' of ' . $chunk_count . ' for table ' . $table,
                ));
            }

            $this->report($job_id, array(
                'phase' => 'database',
                'step' => 'table_finished',
                'table' => $table,
                'chunk_total' => $chunk_count,
                'message' => 'Finished exporting table ' . $table,
            ));
        }

        $this->writer->write($working_directory, $schemas, $plans_by_table, $sql_by_chunk);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function report(?string $job_id, array $payload): void
    {
        if ($this->progress === null || $job_id === null) {
            return;
        }

        $this->progress->report($job_id, Job::EXPORTING_DATABASE, $payload);
    }

    private function lastSeenId(TableRows $rows, string $primary_key, ?int $current): ?int
    {
        foreach ($rows->rows() as $row) {
            if (isset($row[$primary_key])) {
                $current = (int) $row[$primary_key];
            }
        }

        return $current;
    }
}
