<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Standalone installer cannot rely on WordPress logging APIs during import preparation.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class DatabaseImportPreparationManager
{
    private WpConfigReader $wp_config;
    private DatabaseConnectionTester $connection_tester;
    private DatabaseImportManifestReader $manifest_reader;
    private SqlTableNameRewriter $rewriter;
    private DatabaseChunkImporter $importer;
    private DatabaseTableInspector $table_inspector;

    public function __construct(
        WpConfigReader $wp_config,
        DatabaseConnectionTester $connection_tester,
        DatabaseImportManifestReader $manifest_reader,
        SqlTableNameRewriter $rewriter,
        DatabaseChunkImporter $importer,
        ?DatabaseTableInspector $table_inspector = null
    ) {
        $this->wp_config = $wp_config;
        $this->connection_tester = $connection_tester;
        $this->manifest_reader = $manifest_reader;
        $this->rewriter = $rewriter;
        $this->importer = $importer;
        $this->table_inspector = $table_inspector ?: new DatabaseTableInspector();
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $server
     * @return array{staged:bool,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    public function stage(string $engine_dir, array $config, array $server): array
    {
        unset($server);

        if (empty($config['restore_confirmed'])) {
            return $this->result(false, 0, 0, 0, array('Restore is not confirmed.'));
        }

        if (empty($config['rollback_prepared'])) {
            return $this->result(false, 0, 0, 0, array('Rollback is not prepared.'));
        }

        if (empty($config['rollback_database_dump'])) {
            return $this->result(false, 0, 0, 0, array('Database import requires a database rollback dump.'));
        }

        if (!empty($config['database_import_staged'])) {
            return $this->result(false, 0, 0, 0, array('Database import is already staged.'));
        }

        if (!empty($config['locked'])) {
            return $this->result(false, 0, 0, 0, array('Installer is locked.'));
        }

        $engine_dir = rtrim($engine_dir, '/\\');
        $credentials = $this->wp_config->readDatabaseCredentials(dirname($engine_dir));
        if (empty($credentials['complete'])) {
            return $this->result(false, 0, 0, 0, array('Database credentials are incomplete.'));
        }

        $connection = $this->connection_tester->test($credentials);
        if (empty($connection['connected'])) {
            $message = isset($connection['message']) ? (string) $connection['message'] : 'Database connection failed.';

            return $this->result(false, 0, 0, 0, array($message));
        }

        $archive_path = isset($config['staged_archive_path']) ? (string) $config['staged_archive_path'] : '';
        $manifest = $this->manifest_reader->read($archive_path);
        if (empty($manifest['valid'])) {
            return $this->result(false, 0, 0, 0, $this->stringList($manifest['warnings']));
        }

        $table_map = $this->buildTableMap($manifest['tables'], isset($config['restore_job_id']) ? (string) $config['restore_job_id'] : 'restore');
        $import = $this->importer->import($credentials, $manifest['tables'], $manifest['chunks'], $table_map, $this->rewriter);
        if (empty($import['imported'])) {
            return $this->result(
                false,
                isset($import['table_count']) ? (int) $import['table_count'] : 0,
                isset($import['chunk_count']) ? (int) $import['chunk_count'] : 0,
                isset($import['statement_count']) ? (int) $import['statement_count'] : 0,
                $this->stringList(isset($import['warnings']) ? $import['warnings'] : array())
            );
        }

        $table_count = (int) $import['table_count'];
        $chunk_count = (int) $import['chunk_count'];
        $statement_count = (int) $import['statement_count'];

        $verification = $this->table_inspector->verifyTables($table_map, $credentials);
        if (empty($verification['valid'])) {
            return $this->result(false, $table_count, $chunk_count, $statement_count, $this->stringList(isset($verification['warnings']) ? $verification['warnings'] : array()));
        }

        $config['database_import_staged'] = true;
        $config['database_import_staged_at'] = gmdate('c');
        $config['database_import_table_count'] = $table_count;
        $config['database_import_chunk_count'] = $chunk_count;
        $config['database_import_statement_count'] = $statement_count;
        $config['database_import_staging_tables'] = $table_map;

        if (!$this->writeConfig($engine_dir, $config)) {
            return $this->result(false, $table_count, $chunk_count, $statement_count, array('Unable to update installer config.'));
        }

        return $this->result(true, $table_count, $chunk_count, $statement_count, $this->stringList($import['warnings']));
    }

    /**
     * @param array<int,array{name:string,chunks:array<int,string>}> $tables
     * @return array<string,string>
     */
    private function buildTableMap(array $tables, string $restore_job_id): array
    {
        $hash = substr(hash('sha256', $restore_job_id), 0, 8);
        $table_map = array();

        foreach ($tables as $table) {
            $source = $table['name'];
            $sanitized = preg_replace('/[^A-Za-z0-9_]/', '_', $source);
            $table_map[$source] = 'ssc_tmp_' . $hash . '_' . ($sanitized === null ? '' : $sanitized);
        }

        return $table_map;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function writeConfig(string $engine_dir, array $config): bool
    {
        $config_path = $engine_dir . '/config.php';

        return file_put_contents($config_path, "<?php\n\nreturn " . var_export($config, true) . ";\n") !== false;
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private function stringList($values): array
    {
        if (!is_array($values)) {
            return array();
        }

        $strings = array();
        foreach ($values as $value) {
            $strings[] = is_scalar($value) ? (string) $value : '';
        }

        return $strings;
    }

    /**
     * @param list<string> $warnings
     * @return array{staged:bool,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    private function result(bool $staged, int $table_count, int $chunk_count, int $statement_count, array $warnings): array
    {
        return array(
            'staged' => $staged,
            'table_count' => $table_count,
            'chunk_count' => $chunk_count,
            'statement_count' => $statement_count,
            'warnings' => $warnings,
        );
    }
}
