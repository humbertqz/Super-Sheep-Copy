<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTableInspector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DestinationDetector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseUrlReplacementPlanBuilder.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTableSwapExecutor.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTableSwapManager.php';

final class DatabaseTableSwapManagerTest extends TestCase
{
    private string $root_dir;
    private string $engine_dir;

    protected function setUp(): void
    {
        $this->root_dir = sys_get_temp_dir() . '/ssc-swap-' . bin2hex(random_bytes(4));
        $this->engine_dir = $this->root_dir . '/ssc-restore-engine';
        mkdir($this->engine_dir, 0777, true);
        file_put_contents($this->root_dir . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'db');\n"
            . "define('DB_USER', 'user');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root_dir);
    }

    public function testRejectsMissingStagedImport(): void
    {
        $manager = $this->manager(array());

        $result = $manager->swap($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
        ), array('HTTP_HOST' => 'destination.example', 'HTTPS' => 'on'));

        self::assertFalse($result['swapped']);
        self::assertSame(array('Database import must be staged before table swap.'), $result['warnings']);
    }

    public function testRecordsUrlPlanAndSwapMetadata(): void
    {
        file_put_contents($this->engine_dir . '/config.php', "<?php\n\nreturn array();\n");
        $executor = new FakeSwapExecutor($this->engine_dir . '/config.php', true);
        $manager = $this->manager(array('wp_posts' => true, 'ssc_tmp_abcd_wp_posts' => true), $executor);

        $result = $manager->swap($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_import_staging_tables' => array('wp_posts' => 'ssc_tmp_abcd_wp_posts'),
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example',
        ), array('HTTP_HOST' => 'destination.example', 'HTTPS' => 'on'));

        self::assertTrue($result['swapped']);
        self::assertSame(1, $result['table_count']);
        self::assertSame(array('RENAME TABLE `wp_posts` TO `ssc_old_' . substr(hash('sha256', 'restore-123'), 0, 8) . '_wp_posts`, `ssc_tmp_abcd_wp_posts` TO `wp_posts`'), $result['sql']);
        self::assertTrue($executor->saw_pending_config);

        $config = require $this->engine_dir . '/config.php';
        self::assertTrue($config['database_tables_swapped']);
        self::assertTrue($config['locked']);
        self::assertArrayNotHasKey('database_tables_swap_pending', $config);
        self::assertSame(1, $config['database_swap_table_count']);
        self::assertSame(array('wp_posts' => 'ssc_old_' . substr(hash('sha256', 'restore-123'), 0, 8) . '_wp_posts'), $config['database_swap_backup_tables']);
        self::assertSame('planned', $config['database_url_replacement_plan']['status']);
    }

    public function testUrlPlanUsesSubdirectoryDestinationFromInstallerPath(): void
    {
        file_put_contents($this->engine_dir . '/config.php', "<?php\n\nreturn array();\n");
        $manager = $this->manager(array('wp_options' => true, 'ssc_tmp_abcd_wp_options' => true));

        $result = $manager->swap($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_import_staging_tables' => array('wp_options' => 'ssc_tmp_abcd_wp_options'),
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example',
        ), array(
            'HTTP_HOST' => 'shotpruebas.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/wptest/installer.php',
        ));

        self::assertTrue($result['swapped']);
        $config = require $this->engine_dir . '/config.php';
        self::assertSame('https://shotpruebas.com/wptest', $config['database_url_replacement_plan']['destination_url']);
    }

    public function testSkipsBackupRenameWhenDestinationTableDoesNotExist(): void
    {
        file_put_contents($this->engine_dir . '/config.php', "<?php\n\nreturn array();\n");
        $manager = $this->manager(array('ssc_tmp_abcd_wp_new' => true));

        $result = $manager->swap($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_import_staging_tables' => array('wp_new' => 'ssc_tmp_abcd_wp_new'),
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example',
        ), array('HTTP_HOST' => 'destination.example', 'HTTPS' => 'on'));

        self::assertTrue($result['swapped']);
        self::assertSame(array('RENAME TABLE `ssc_tmp_abcd_wp_new` TO `wp_new`'), $result['sql']);

        $config = require $this->engine_dir . '/config.php';
        self::assertSame(array(), $config['database_swap_backup_tables']);
    }

    public function testSwapsSourcePrefixedTablesToDestinationPrefix(): void
    {
        file_put_contents($this->root_dir . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'db');\n"
            . "define('DB_USER', 'user');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'dst_';\n");
        file_put_contents($this->engine_dir . '/config.php', "<?php\n\nreturn array();\n");
        $manager = $this->manager(array('dst_options' => true, 'ssc_tmp_abcd_wp_options' => true));

        $result = $manager->swap($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_import_staging_tables' => array('wp_options' => 'ssc_tmp_abcd_wp_options'),
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example',
            'source_table_prefix' => 'wp_',
        ), array('HTTP_HOST' => 'destination.example', 'HTTPS' => 'on'));

        self::assertTrue($result['swapped']);
        self::assertSame(array(
            'RENAME TABLE `dst_options` TO `ssc_old_' . substr(hash('sha256', 'restore-123'), 0, 8) . '_dst_options`, `ssc_tmp_abcd_wp_options` TO `dst_options`',
            "UPDATE `dst_options` SET `option_name` = 'dst_user_roles' WHERE `option_name` = 'wp_user_roles'",
        ), $result['sql']);

        $config = require $this->engine_dir . '/config.php';
        self::assertSame(array('dst_options'), $config['database_url_replacement_plan']['tables']);
        self::assertSame(array('dst_options' => 'ssc_old_' . substr(hash('sha256', 'restore-123'), 0, 8) . '_dst_options'), $config['database_swap_backup_tables']);
    }

    public function testUpdatesUsermetaCapabilityKeysForDestinationPrefix(): void
    {
        file_put_contents($this->root_dir . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'db');\n"
            . "define('DB_USER', 'user');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'dst_';\n");
        file_put_contents($this->engine_dir . '/config.php', "<?php\n\nreturn array();\n");
        $manager = $this->manager(array(
            'dst_usermeta' => true,
            'ssc_tmp_abcd_wp_usermeta' => true,
        ));

        $result = $manager->swap($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_import_staging_tables' => array('wp_usermeta' => 'ssc_tmp_abcd_wp_usermeta'),
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example',
            'source_table_prefix' => 'wp_',
        ), array('HTTP_HOST' => 'destination.example', 'HTTPS' => 'on'));

        self::assertTrue($result['swapped']);
        self::assertSame(array(
            'RENAME TABLE `dst_usermeta` TO `ssc_old_' . substr(hash('sha256', 'restore-123'), 0, 8) . '_dst_usermeta`, `ssc_tmp_abcd_wp_usermeta` TO `dst_usermeta`',
            "UPDATE `dst_usermeta` SET `meta_key` = 'dst_capabilities' WHERE `meta_key` = 'wp_capabilities'",
            "UPDATE `dst_usermeta` SET `meta_key` = 'dst_user_level' WHERE `meta_key` = 'wp_user_level'",
        ), $result['sql']);
    }

    public function testRejectsInvalidTableIdentifiersBeforeExecutingSwap(): void
    {
        file_put_contents($this->engine_dir . '/config.php', "<?php\n\nreturn array();\n");
        $executor = new FakeSwapExecutor();
        $manager = $this->manager(array('wp_posts' => true, 'ssc_tmp_abcd_wp_posts' => true), $executor);

        $result = $manager->swap($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_import_staging_tables' => array('wp-posts' => 'ssc_tmp_abcd_wp_posts'),
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example',
        ), array('HTTP_HOST' => 'destination.example', 'HTTPS' => 'on'));

        self::assertFalse($result['swapped']);
        self::assertSame(array('Invalid database table identifier: wp-posts'), $result['warnings']);
        self::assertFalse($executor->executed);
    }

    public function testStopsWhenDestinationTableInspectionFails(): void
    {
        file_put_contents($this->engine_dir . '/config.php', "<?php\n\nreturn array();\n");
        $executor = new FakeSwapExecutor();
        $manager = new \SuperSheepCopyInstaller\DatabaseTableSwapManager(
            new \SuperSheepCopyInstaller\WpConfigReader(),
            new FakeSwapConnectionTester(),
            new FailingSwapTableInspector(),
            new \SuperSheepCopyInstaller\DatabaseUrlReplacementPlanBuilder(),
            $executor
        );

        $result = $manager->swap($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_import_staging_tables' => array('wp_posts' => 'ssc_tmp_abcd_wp_posts'),
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example',
        ), array('HTTP_HOST' => 'destination.example', 'HTTPS' => 'on'));

        self::assertFalse($result['swapped']);
        self::assertSame(array('Unable to inspect destination tables.'), $result['warnings']);
        self::assertFalse($executor->executed);
    }

    public function testClearsStagedImportWhenStagingTableIsMissing(): void
    {
        $config = array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_import_staging_tables' => array('wp_posts' => 'ssc_tmp_abcd_wp_posts'),
            'database_import_table_count' => 1,
            'database_import_chunk_count' => 1,
            'database_import_statement_count' => 2,
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example',
        );
        file_put_contents($this->engine_dir . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n");
        $executor = new FakeSwapExecutor();
        $manager = $this->manager(array(), $executor);

        $result = $manager->swap($this->engine_dir, $config, array('HTTP_HOST' => 'destination.example', 'HTTPS' => 'on'));
        $updated = require $this->engine_dir . '/config.php';

        self::assertFalse($result['swapped']);
        self::assertSame(array('Missing staging table: ssc_tmp_abcd_wp_posts'), $result['warnings']);
        self::assertFalse($executor->executed);
        self::assertArrayNotHasKey('database_import_staged', $updated);
        self::assertArrayNotHasKey('database_import_staging_tables', $updated);
        self::assertArrayNotHasKey('database_import_table_count', $updated);
    }

    /**
     * @param array<string,bool> $existing_tables
     */
    private function manager(array $existing_tables, ?FakeSwapExecutor $executor = null): \SuperSheepCopyInstaller\DatabaseTableSwapManager
    {
        return new \SuperSheepCopyInstaller\DatabaseTableSwapManager(
            new \SuperSheepCopyInstaller\WpConfigReader(),
            new FakeSwapConnectionTester(),
            new FakeSwapTableInspector($existing_tables),
            new \SuperSheepCopyInstaller\DatabaseUrlReplacementPlanBuilder(),
            $executor === null ? new FakeSwapExecutor() : $executor
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}

final class FakeSwapConnectionTester extends \SuperSheepCopyInstaller\DatabaseConnectionTester
{
    public function test(array $credentials): array
    {
        return array('connected' => true, 'status' => 'ok', 'message' => '', 'database' => 'db', 'host' => 'localhost');
    }
}

final class FakeSwapTableInspector extends \SuperSheepCopyInstaller\DatabaseTableInspector
{
    /** @var array<string,bool> */
    private array $existing_tables;

    /**
     * @param array<string,bool> $existing_tables
     */
    public function __construct(array $existing_tables)
    {
        $this->existing_tables = $existing_tables;
    }

    /**
     * @param array<string,string> $table_map
     * @param array<string,mixed> $credentials
     * @return array{valid:bool,warnings:list<string>}
     */
    public function verifyTables(array $table_map, array $credentials = array()): array
    {
        if (empty($credentials['complete'])) {
            return array('valid' => false, 'warnings' => array('Credentials were not passed to table inspector.'));
        }

        $warnings = array();
        foreach ($table_map as $staging_table) {
            if (!$this->tableExists($staging_table)) {
                $warnings[] = 'Missing staging table: ' . $staging_table;
            }
        }

        return array('valid' => $warnings === array(), 'warnings' => $warnings);
    }

    /**
     * @param list<string> $tables
     * @param array<string,mixed> $credentials
     * @return array{valid:bool,tables:array<string,bool>,warnings:list<string>}
     */
    public function existingTables(array $tables, array $credentials = array()): array
    {
        unset($credentials);

        $existing = array();
        foreach ($tables as $table) {
            $existing[$table] = $this->tableExists($table);
        }

        return array('valid' => true, 'tables' => $existing, 'warnings' => array());
    }

    /**
     * @param mixed $connection
     */
    protected function inspectTable(string $table, $connection = null): ?bool
    {
        unset($connection);

        return isset($this->existing_tables[$table]) && $this->existing_tables[$table];
    }
}

final class FailingSwapTableInspector extends \SuperSheepCopyInstaller\DatabaseTableInspector
{
    public function verifyTables(array $table_map, array $credentials = array()): array
    {
        unset($table_map, $credentials);

        return array('valid' => true, 'warnings' => array());
    }

    public function existingTables(array $tables, array $credentials = array()): array
    {
        unset($tables, $credentials);

        return array('valid' => false, 'tables' => array(), 'warnings' => array('Unable to inspect destination tables.'));
    }
}

final class FakeSwapExecutor
{
    /** @var list<string> */
    public array $sql = array();
    public bool $executed = false;
    public bool $saw_pending_config = false;
    private string $config_path;
    private bool $expect_pending_config;

    public function __construct(string $config_path = '', bool $expect_pending_config = false)
    {
        $this->config_path = $config_path;
        $this->expect_pending_config = $expect_pending_config;
    }

    /**
     * @param array<string,mixed> $credentials
     * @param list<string> $sql
     */
    public function execute(array $credentials, array $sql): bool
    {
        unset($credentials);
        $this->executed = true;
        $this->sql = $sql;
        if ($this->expect_pending_config && is_readable($this->config_path)) {
            $config = require $this->config_path;
            $this->saw_pending_config = !empty($config['locked'])
                && !empty($config['database_tables_swap_pending'])
                && isset($config['database_url_replacement_plan'])
                && isset($config['database_swap_backup_tables']);
        }

        return true;
    }
}
