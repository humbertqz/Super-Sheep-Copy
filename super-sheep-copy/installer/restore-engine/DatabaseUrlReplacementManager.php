<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Standalone installer cannot rely on WordPress logging APIs during URL replacement.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use SuperSheepCopy\Shared\Serialization\SerializationWalker;
use SuperSheepCopy\Shared\Urls\StructuredValueReplacer;
use SuperSheepCopy\Shared\Urls\UrlReplacementEngine;

final class DatabaseUrlReplacementManager
{
    private WpConfigReader $wp_config;
    private DatabaseConnectionTester $connection_tester;
    private DatabaseTextColumnInspector $column_inspector;
    private DatabaseUrlReplacementExecutor $executor;

    public function __construct(
        WpConfigReader $wp_config,
        DatabaseConnectionTester $connection_tester,
        DatabaseTextColumnInspector $column_inspector,
        DatabaseUrlReplacementExecutor $executor
    ) {
        $this->wp_config = $wp_config;
        $this->connection_tester = $connection_tester;
        $this->column_inspector = $column_inspector;
        $this->executor = $executor;
    }

    /**
     * @param array<string,mixed> $config
     * @return array{completed:bool,table_count:int,scanned_rows:int,changed_rows:int,scanned_cells:int,changed_cells:int,replacement_count:int,warnings:list<string>}
     */
    public function replace(string $engine_dir, array $config): array
    {
        if (empty($config['restore_confirmed'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Restore is not confirmed.'));
        }
        if (empty($config['rollback_prepared'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Rollback is not prepared.'));
        }
        if (empty($config['rollback_database_dump'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Database URL replacement requires a database rollback dump.'));
        }
        if (empty($config['database_tables_swapped'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Database tables must be swapped before URL replacement.'));
        }
        if (!empty($config['database_url_replacement_completed'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Database URL replacement is already completed.'));
        }

        $plan = isset($config['database_url_replacement_plan']) && is_array($config['database_url_replacement_plan'])
            ? $config['database_url_replacement_plan']
            : array();
        if (!$this->isValidPlan($plan)) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('URL replacement plan is missing.'));
        }

        $credentials = $this->wp_config->readDatabaseCredentials(dirname(rtrim($engine_dir, '/\\')));
        if (empty($credentials['complete'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Database credentials are incomplete.'));
        }
        $connection = $this->connection_tester->test($credentials);
        if (empty($connection['connected'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array(isset($connection['message']) ? (string) $connection['message'] : 'Database connection failed.'));
        }

        $tables = $this->stringList($plan['tables']);
        $inspection = $this->column_inspector->inspect($tables, $credentials);
        if (empty($inspection['valid'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, $this->stringList(isset($inspection['warnings']) ? $inspection['warnings'] : array()));
        }

        $started_at = gmdate('c');
        $replacement = $this->executor->execute(
            $credentials,
            $plan,
            isset($inspection['tables']) && is_array($inspection['tables']) ? $inspection['tables'] : array(),
            new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker())
        );
        if (empty($replacement['completed'])) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, $this->stringList(isset($replacement['warnings']) ? $replacement['warnings'] : array('Database URL replacement failed.')));
        }

        $config['database_url_replacement_started_at'] = $started_at;
        $config['database_url_replacement_completed'] = true;
        $config['database_url_replacement_completed_at'] = gmdate('c');
        $config['database_url_replacement_table_count'] = (int) $replacement['table_count'];
        $config['database_url_replacement_scanned_rows'] = (int) $replacement['scanned_rows'];
        $config['database_url_replacement_changed_rows'] = (int) $replacement['changed_rows'];
        $config['database_url_replacement_scanned_cells'] = (int) $replacement['scanned_cells'];
        $config['database_url_replacement_changed_cells'] = (int) $replacement['changed_cells'];
        $config['database_url_replacement_count'] = (int) $replacement['replacement_count'];
        $config['database_url_replacement_warnings'] = $this->stringList(isset($replacement['warnings']) ? $replacement['warnings'] : array());
        $config['locked'] = true;

        if (!$this->writeConfig(rtrim($engine_dir, '/\\'), $config)) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Unable to update installer config.'));
        }

        return $this->result(true, (int) $replacement['table_count'], (int) $replacement['scanned_rows'], (int) $replacement['changed_rows'], (int) $replacement['scanned_cells'], (int) $replacement['changed_cells'], (int) $replacement['replacement_count'], array());
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function isValidPlan(array $plan): bool
    {
        return isset($plan['source_urls'], $plan['destination_url'], $plan['tables'])
            && is_array($plan['source_urls'])
            && is_array($plan['tables'])
            && (string) $plan['destination_url'] !== '';
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
            if (is_scalar($value)) {
                $strings[] = (string) $value;
            }
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
     * @return array{completed:bool,table_count:int,scanned_rows:int,changed_rows:int,scanned_cells:int,changed_cells:int,replacement_count:int,warnings:list<string>}
     */
    private function result(bool $completed, int $table_count, int $scanned_rows, int $changed_rows, int $scanned_cells, int $changed_cells, int $replacement_count, array $warnings): array
    {
        return array(
            'completed' => $completed,
            'table_count' => $table_count,
            'scanned_rows' => $scanned_rows,
            'changed_rows' => $changed_rows,
            'scanned_cells' => $scanned_cells,
            'changed_cells' => $changed_cells,
            'replacement_count' => $replacement_count,
            'warnings' => $warnings,
        );
    }
}
