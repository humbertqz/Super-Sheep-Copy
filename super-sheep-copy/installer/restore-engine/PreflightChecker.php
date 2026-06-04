<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Standalone preflight runs before WordPress filesystem APIs are available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class PreflightChecker
{
    private EnvironmentChecker $environment;
    private DestinationDetector $destination;
    private WpConfigReader $wp_config;
    private ArchiveValidator $archive_validator;
    private DatabaseConnectionTester $database_tester;

    public function __construct(
        EnvironmentChecker $environment,
        DestinationDetector $destination,
        WpConfigReader $wp_config,
        ArchiveValidator $archive_validator,
        ?DatabaseConnectionTester $database_tester = null
    ) {
        $this->environment = $environment;
        $this->destination = $destination;
        $this->wp_config = $wp_config;
        $this->archive_validator = $archive_validator;
        $this->database_tester = $database_tester ?: new DatabaseConnectionTester();
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $server
     * @return list<array{key:string,label:string,status:string,value:string,message:string}>
     */
    public function run(array $config, array $server, string $engine_dir): array
    {
        $checks = array();
        foreach ($this->environment->check() as $key => $check) {
            $checks[] = $this->check((string) $key, $check['label'], $check['status'], $check['value'], '');
        }

        $archive_path = isset($config['staged_archive_path']) ? (string) $config['staged_archive_path'] : '';
        $archive_readable = $archive_path !== '' && is_readable($archive_path);
        $checks[] = $this->check('archive_readable', 'Staged archive readable', $archive_readable ? 'ok' : 'error', $archive_readable ? 'Readable' : 'Unavailable', $archive_readable ? '' : 'The prepared archive cannot be read.');

        $cached_validation_status = isset($config['archive_validation_status']) && is_scalar($config['archive_validation_status']) ? (string) $config['archive_validation_status'] : '';
        if ($archive_readable && $cached_validation_status === 'valid') {
            $checks[] = $this->check('archive_valid', 'Staged archive valid', 'ok', 'Valid', '');
        } elseif ($archive_readable && $cached_validation_status === 'invalid') {
            $checks[] = $this->check('archive_valid', 'Staged archive valid', 'error', 'Invalid', 'The prepared archive failed validation.');
        } elseif ($archive_readable) {
            $validation = $this->archive_validator->validatePackage($archive_path);
            $checks[] = $this->check('archive_valid', 'Staged archive valid', $validation->isValid() ? 'ok' : 'error', $validation->isValid() ? 'Valid' : 'Invalid', $validation->isValid() ? '' : 'The prepared archive failed validation.');
        } else {
            $checks[] = $this->check('archive_valid', 'Staged archive valid', 'error', 'Not checked', 'Archive validation requires a readable archive.');
        }

        $destination_url = $this->destination->detect($server);
        $checks[] = $this->check('destination_url', 'Destination URL detected', $destination_url === '' ? 'warning' : 'ok', $destination_url === '' ? 'Unavailable' : $destination_url, $destination_url === '' ? 'The installer could not detect the destination URL.' : '');

        $wordpress_root = dirname(rtrim($engine_dir, '/\\'));
        $checks[] = $this->check('wordpress_root', 'WordPress root detected', is_dir($wordpress_root) ? 'ok' : 'error', is_dir($wordpress_root) ? 'Detected' : 'Missing', is_dir($wordpress_root) ? '' : 'The WordPress root directory could not be detected.');
        $checks[] = $this->check('wordpress_root_writable', 'WordPress root writable', is_writable($wordpress_root) ? 'ok' : 'warning', is_writable($wordpress_root) ? 'Writable' : 'Not writable', is_writable($wordpress_root) ? '' : 'File restore may require writable WordPress root permissions later.');

        $database = $this->wp_config->readDatabaseConfig($wordpress_root);
        $checks[] = $this->check('wp_config_readable', 'wp-config.php readable', $database['readable'] ? 'ok' : 'warning', $database['readable'] ? 'Readable' : 'Unavailable', $database['readable'] ? '' : 'Manual database credentials will be required in a later step.');
        $has_credentials = $database['has_db_name'] && $database['has_db_user'] && $database['has_db_password'] && $database['has_db_host'];
        $checks[] = $this->check('database_credentials', 'Database credentials detected', $has_credentials ? 'ok' : 'warning', $has_credentials ? 'Detected' : 'Incomplete', $has_credentials ? '' : 'Database constants are incomplete or unavailable.');
        $credentials = $this->wp_config->readDatabaseCredentials($wordpress_root);
        $connection = $this->database_tester->test($credentials);
        $checks[] = $this->check(
            'database_connection',
            'Database connection',
            $connection['status'],
            $connection['connected'] ? 'Connected' : 'Unavailable',
            $connection['message']
        );

        return $checks;
    }

    /**
     * @param list<array{key:string,label:string,status:string,value:string,message:string}> $checks
     */
    public static function hasBlockingErrors(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'error' && $check['key'] !== 'database_connection') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{key:string,label:string,status:string,value:string,message:string}
     */
    private function check(string $key, string $label, string $status, string $value, string $message): array
    {
        return array('key' => $key, 'label' => $label, 'status' => $status, 'value' => $value, 'message' => $message);
    }
}
