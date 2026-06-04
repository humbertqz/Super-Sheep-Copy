<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Standalone installer cannot rely on WordPress logging APIs during table swap.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class DatabaseTableSwapManager
{
    private WpConfigReader $wp_config;
    private DatabaseConnectionTester $connection_tester;
    private DatabaseTableInspector $table_inspector;
    private DatabaseUrlReplacementPlanBuilder $url_plan_builder;
    /** @var mixed */
    private $executor;

    /**
     * @param mixed $executor Object with execute(array $credentials, array $sql): bool.
     */
    public function __construct(
        WpConfigReader $wp_config,
        DatabaseConnectionTester $connection_tester,
        DatabaseTableInspector $table_inspector,
        DatabaseUrlReplacementPlanBuilder $url_plan_builder,
        $executor
    ) {
        $this->wp_config = $wp_config;
        $this->connection_tester = $connection_tester;
        $this->table_inspector = $table_inspector;
        $this->url_plan_builder = $url_plan_builder;
        $this->executor = $executor;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $server
     * @return array{swapped:bool,table_count:int,warnings:list<string>,sql:list<string>}
     */
    public function swap(string $engine_dir, array $config, array $server): array
    {
        if (empty($config['restore_confirmed'])) {
            return $this->result(false, 0, array('Restore is not confirmed.'), array());
        }
        if (empty($config['rollback_prepared'])) {
            return $this->result(false, 0, array('Rollback is not prepared.'), array());
        }
        if (empty($config['rollback_database_dump'])) {
            return $this->result(false, 0, array('Database table swap requires a database rollback dump.'), array());
        }
        if (empty($config['database_import_staged'])) {
            return $this->result(false, 0, array('Database import must be staged before table swap.'), array());
        }
        if (!empty($config['database_tables_swapped'])) {
            return $this->result(false, 0, array('Database tables are already swapped.'), array());
        }
        if (!empty($config['locked'])) {
            return $this->result(false, 0, array('Installer is locked.'), array());
        }

        $table_map = isset($config['database_import_staging_tables']) && is_array($config['database_import_staging_tables'])
            ? $this->stringMap($config['database_import_staging_tables'])
            : array();
        if ($table_map === array()) {
            return $this->result(false, 0, array('Staging table map is missing.'), array());
        }
        $invalid_identifier = $this->invalidIdentifier($table_map);
        if ($invalid_identifier !== '') {
            return $this->result(false, 0, array('Invalid database table identifier: ' . $invalid_identifier), array());
        }

        $credentials = $this->wp_config->readDatabaseCredentials(dirname(rtrim($engine_dir, '/\\')));
        if (empty($credentials['complete'])) {
            return $this->result(false, 0, array('Database credentials are incomplete.'), array());
        }

        $connection = $this->connection_tester->test($credentials);
        if (empty($connection['connected'])) {
            return $this->result(false, 0, array(isset($connection['message']) ? (string) $connection['message'] : 'Database connection failed.'), array());
        }

        $verification = $this->table_inspector->verifyTables($table_map, $credentials);
        if (empty($verification['valid'])) {
            $warnings = $this->stringList($verification['warnings']);
            if ($this->hasMissingStagingTableWarning($warnings)) {
                $this->clearStagedImport($engine_dir, $config);
            }

            return $this->result(false, 0, $warnings, array());
        }

        $restore_job_id = isset($config['restore_job_id']) ? (string) $config['restore_job_id'] : 'restore';
        $destination_table_map = $this->destinationTableMap($table_map, $config, $credentials);
        $planned_at = gmdate('c');
        $url_plan = $this->url_plan_builder->build(
            isset($config['source_site_url']) ? (string) $config['source_site_url'] : '',
            isset($config['source_home_url']) ? (string) $config['source_home_url'] : '',
            $this->destinationUrl($server),
            $destination_table_map,
            $planned_at
        );

        $destination_tables = array_keys($destination_table_map);
        $existing_result = $this->table_inspector->existingTables($destination_tables, $credentials);
        if (empty($existing_result['valid'])) {
            return $this->result(false, 0, $this->stringList(isset($existing_result['warnings']) ? $existing_result['warnings'] : array()), array());
        }

        $existing_destinations = isset($existing_result['tables']) && is_array($existing_result['tables'])
            ? $this->boolMap($existing_result['tables'])
            : array();
        $backup_map = $this->backupMap($destination_tables, $restore_job_id, $existing_destinations);
        $sql = $this->renameSql($destination_table_map, $backup_map);
        $sql = array_merge($sql, $this->prefixMetadataSql($destination_table_map, $config, $credentials));

        $config['database_url_replacement_plan'] = $url_plan;
        $config['database_url_replacement_planned_at'] = $planned_at;
        $config['database_tables_swap_pending'] = true;
        $config['database_tables_swap_started_at'] = gmdate('c');
        $config['database_swap_table_count'] = count($table_map);
        $config['database_swap_backup_tables'] = $backup_map;
        $config['locked'] = true;

        if (!$this->writeConfig(rtrim($engine_dir, '/\\'), $config)) {
            return $this->result(false, count($table_map), array('Unable to update installer config.'), array());
        }

        if (!is_object($this->executor) || !method_exists($this->executor, 'execute') || !$this->executor->execute($credentials, $sql)) {
            return $this->result(false, 0, array('Database table swap failed.'), $sql);
        }

        unset($config['database_tables_swap_pending']);
        $config['database_tables_swapped'] = true;
        $config['database_tables_swapped_at'] = gmdate('c');

        if (!$this->writeConfig(rtrim($engine_dir, '/\\'), $config)) {
            return $this->result(false, count($table_map), array('Unable to update installer config.'), $sql);
        }

        return $this->result(true, count($table_map), array(), $sql);
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    private function stringMap(array $values): array
    {
        $map = array();
        foreach ($values as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $map[$key] = (string) $value;
            }
        }

        return $map;
    }

    /**
     * @param array<string,string> $table_map
     */
    private function invalidIdentifier(array $table_map): string
    {
        foreach ($table_map as $destination_table => $staging_table) {
            if (!$this->isIdentifier($destination_table)) {
                return $destination_table;
            }

            if (!$this->isIdentifier($staging_table)) {
                return $staging_table;
            }
        }

        return '';
    }

    private function isIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }

    /**
     * @param array<string,string> $staging_table_map
     * @param array<string,mixed> $config
     * @param array<string,mixed> $credentials
     * @return array<string,string>
     */
    private function destinationTableMap(array $staging_table_map, array $config, array $credentials): array
    {
        $source_prefix = isset($config['source_table_prefix']) && is_scalar($config['source_table_prefix']) ? (string) $config['source_table_prefix'] : '';
        $destination_prefix = isset($credentials['table_prefix']) && is_scalar($credentials['table_prefix']) ? (string) $credentials['table_prefix'] : '';
        $destination_map = array();

        foreach ($staging_table_map as $source_table => $staging_table) {
            $destination_table = $source_table;
            if ($source_prefix !== '' && $destination_prefix !== '' && strpos($source_table, $source_prefix) === 0) {
                $destination_table = $destination_prefix . substr($source_table, strlen($source_prefix));
            }
            $destination_map[$destination_table] = $staging_table;
        }

        return $destination_map;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,bool>
     */
    private function boolMap(array $values): array
    {
        $map = array();
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $map[$key] = (bool) $value;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $tables
     * @param array<string,bool> $existing_destinations
     * @return array<string,string>
     */
    private function backupMap(array $tables, string $restore_job_id, array $existing_destinations): array
    {
        $hash = substr(hash('sha256', $restore_job_id), 0, 8);
        $backup_map = array();
        foreach ($tables as $table) {
            if (empty($existing_destinations[$table])) {
                continue;
            }

            $backup_map[$table] = 'ssc_old_' . $hash . '_' . $this->sanitizeIdentifier($table);
        }

        return $backup_map;
    }

    /**
     * @param array<string,string> $table_map
     * @param array<string,string> $backup_map
     * @return list<string>
     */
    private function renameSql(array $table_map, array $backup_map): array
    {
        $parts = array();
        foreach ($table_map as $destination_table => $staging_table) {
            if (isset($backup_map[$destination_table])) {
                $parts[] = $this->quoteIdentifier($destination_table) . ' TO ' . $this->quoteIdentifier($backup_map[$destination_table]);
            }
            $parts[] = $this->quoteIdentifier($staging_table) . ' TO ' . $this->quoteIdentifier($destination_table);
        }

        return array('RENAME TABLE ' . implode(', ', $parts));
    }

    /**
     * @param array<string,string> $destination_table_map
     * @param array<string,mixed> $config
     * @param array<string,mixed> $credentials
     * @return list<string>
     */
    private function prefixMetadataSql(array $destination_table_map, array $config, array $credentials): array
    {
        $source_prefix = isset($config['source_table_prefix']) && is_scalar($config['source_table_prefix']) ? (string) $config['source_table_prefix'] : '';
        $destination_prefix = isset($credentials['table_prefix']) && is_scalar($credentials['table_prefix']) ? (string) $credentials['table_prefix'] : '';
        if ($source_prefix === '' || $destination_prefix === '' || $source_prefix === $destination_prefix) {
            return array();
        }

        $sql = array();
        $options_table = $destination_prefix . 'options';
        if (isset($destination_table_map[$options_table])) {
            $sql[] = 'UPDATE ' . $this->quoteIdentifier($options_table)
                . ' SET `option_name` = ' . $this->quoteString($destination_prefix . 'user_roles')
                . ' WHERE `option_name` = ' . $this->quoteString($source_prefix . 'user_roles');
        }

        $usermeta_table = $destination_prefix . 'usermeta';
        if (isset($destination_table_map[$usermeta_table])) {
            $sql[] = 'UPDATE ' . $this->quoteIdentifier($usermeta_table)
                . ' SET `meta_key` = ' . $this->quoteString($destination_prefix . 'capabilities')
                . ' WHERE `meta_key` = ' . $this->quoteString($source_prefix . 'capabilities');
            $sql[] = 'UPDATE ' . $this->quoteIdentifier($usermeta_table)
                . ' SET `meta_key` = ' . $this->quoteString($destination_prefix . 'user_level')
                . ' WHERE `meta_key` = ' . $this->quoteString($source_prefix . 'user_level');
        }

        return $sql;
    }

    private function sanitizeIdentifier(string $identifier): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '_', $identifier);

        return $sanitized === null ? '' : $sanitized;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @param array<string,mixed> $server
     */
    private function destinationUrl(array $server): string
    {
        $destination_url = (new DestinationDetector())->detect($server);
        if ($destination_url !== '') {
            return $destination_url;
        }

        return 'http://localhost';
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
     * @param array<string,mixed> $config
     */
    private function writeConfig(string $engine_dir, array $config): bool
    {
        return file_put_contents($engine_dir . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n") !== false;
    }

    /**
     * @param list<string> $warnings
     */
    private function hasMissingStagingTableWarning(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            if (strpos($warning, 'Missing staging table: ') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function clearStagedImport(string $engine_dir, array $config): void
    {
        unset(
            $config['database_import_staged'],
            $config['database_import_staged_at'],
            $config['database_import_table_count'],
            $config['database_import_chunk_count'],
            $config['database_import_statement_count'],
            $config['database_import_staging_tables'],
            $config['database_tables_swap_pending'],
            $config['database_tables_swap_started_at']
        );

        $this->writeConfig(rtrim($engine_dir, '/\\'), $config);
    }

    /**
     * @param list<string> $warnings
     * @param list<string> $sql
     * @return array{swapped:bool,table_count:int,warnings:list<string>,sql:list<string>}
     */
    private function result(bool $swapped, int $table_count, array $warnings, array $sql): array
    {
        return array(
            'swapped' => $swapped,
            'table_count' => $table_count,
            'warnings' => $warnings,
            'sql' => $sql,
        );
    }
}
