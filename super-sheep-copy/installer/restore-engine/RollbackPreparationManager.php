<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Standalone installer runs before WordPress filesystem/logging APIs are available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class RollbackPreparationManager
{
    private RollbackFileCollector $files;
    private RollbackManifestBuilder $manifest;
    private DestinationDetector $destination;
    private ?WpConfigReader $wp_config;
    private ?DatabaseConnectionTester $database_tester;
    private ?RollbackDatabaseDumper $database_dumper;

    public function __construct(
        RollbackFileCollector $files,
        RollbackManifestBuilder $manifest,
        DestinationDetector $destination,
        ?WpConfigReader $wp_config = null,
        ?DatabaseConnectionTester $database_tester = null,
        ?RollbackDatabaseDumper $database_dumper = null
    ) {
        $this->files = $files;
        $this->manifest = $manifest;
        $this->destination = $destination;
        $this->wp_config = $wp_config;
        $this->database_tester = $database_tester;
        $this->database_dumper = $database_dumper;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $server
     * @return array{prepared:bool,rollback_directory:string,file_count:int,database_included:bool,warnings:list<string>}
     */
    public function prepare(string $engine_dir, array $config, array $server): array
    {
        if (empty($config['restore_confirmed'])) {
            return $this->result(false, '', 0, false, array('Restore is not confirmed.'));
        }

        if (!empty($config['locked'])) {
            return $this->result(false, '', 0, false, array('Installer is locked.'));
        }

        $engine_dir = rtrim($engine_dir, '/\\');
        $wordpress_root = dirname($engine_dir);
        $basename = 'rollback-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $rollback_dir = $engine_dir . '/rollback/' . $basename;

        if (!is_dir($rollback_dir) && !mkdir($rollback_dir, 0777, true) && !is_dir($rollback_dir)) {
            return $this->result(false, '', 0, false, array('Unable to create rollback directory.'));
        }

        $collection = $this->files->collect($wordpress_root, $rollback_dir);
        $database = $this->prepareDatabaseRollback($wordpress_root, $rollback_dir);
        $warnings = array_merge($collection['warnings'], $database['warnings']);
        $manifest = $this->manifest->build(
            $config,
            $this->destination->detect($server),
            $wordpress_root,
            $collection['files'],
            $warnings,
            $database
        );

        $manifest_path = $rollback_dir . '/manifest.json';
        if (file_put_contents($manifest_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return $this->result(false, $basename, count($collection['files']), $database['included'], array('Unable to write rollback manifest.'));
        }

        $config['rollback_prepared'] = true;
        $config['rollback_prepared_at'] = gmdate('c');
        $config['rollback_directory'] = $basename;
        $config['rollback_manifest'] = 'rollback/' . $basename . '/manifest.json';
        $config['rollback_database_dump'] = $database['included']
            ? 'rollback/' . $basename . '/' . $database['dump_path']
            : '';
        $config['rollback_database_table_count'] = $database['table_count'];
        if ($database['included']) {
            $config['rollback_database_dumped_at'] = gmdate('c');
        }

        $config_path = $engine_dir . '/config.php';
        if (file_put_contents($config_path, "<?php\n\nreturn " . var_export($config, true) . ";\n") === false) {
            return $this->result(false, $basename, count($collection['files']), $database['included'], array('Unable to update installer config.'));
        }

        return $this->result(true, $basename, count($collection['files']), $database['included'], $warnings);
    }

    /**
     * @return array{included:bool,dump_path:string,table_count:int,warnings:list<string>}
     */
    private function prepareDatabaseRollback(string $wordpress_root, string $rollback_dir): array
    {
        if (!$this->wp_config instanceof WpConfigReader || !$this->database_tester instanceof DatabaseConnectionTester || !$this->database_dumper instanceof RollbackDatabaseDumper) {
            return $this->emptyDatabase();
        }

        $credentials = $this->wp_config->readDatabaseCredentials($wordpress_root);
        if (empty($credentials['complete'])) {
            return $this->emptyDatabase(array('Database credentials are incomplete.'));
        }

        $connection = $this->database_tester->test($credentials);
        if (empty($connection['connected'])) {
            $message = isset($connection['message']) ? (string) $connection['message'] : 'Database connection failed.';

            return $this->emptyDatabase(array($message));
        }

        return $this->database_dumper->dump($credentials, $rollback_dir);
    }

    /**
     * @param list<string> $warnings
     * @return array{included:bool,dump_path:string,table_count:int,warnings:list<string>}
     */
    private function emptyDatabase(array $warnings = array()): array
    {
        return array(
            'included' => false,
            'dump_path' => '',
            'table_count' => 0,
            'warnings' => $warnings,
        );
    }

    /**
     * @param list<string> $warnings
     * @return array{prepared:bool,rollback_directory:string,file_count:int,database_included:bool,warnings:list<string>}
     */
    private function result(bool $prepared, string $directory, int $file_count, bool $database_included, array $warnings): array
    {
        return array(
            'prepared' => $prepared,
            'rollback_directory' => $directory,
            'file_count' => $file_count,
            'database_included' => $database_included,
            'warnings' => $warnings,
        );
    }
}
