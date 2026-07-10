<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackagePathGuard.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackageReaderInterface.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/ZipPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/TarGzPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DirectoryPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackageReaderFactory.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseImportManifestReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/SqlTableNameRewriter.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseChunkImporter.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTableInspector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseImportPreparationManager.php';

final class DatabaseImportPreparationManagerTest extends TestCase
{
    private string $root;
    private string $engine;
    private string $archive;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-staged-db-import-' . bin2hex(random_bytes(4));
        $this->engine = $this->root . '/ssc-restore-engine';
        $this->archive = $this->root . '/backup.zip';

        mkdir($this->engine, 0777, true);
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");
        $this->writeArchive();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRejectsMissingRollbackDatabaseDump(): void
    {
        $result = $this->manager()->stage($this->engine, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
        ), array());

        self::assertFalse($result['staged']);
        self::assertSame(array('Database import requires a database rollback dump.'), $result['warnings']);
    }

    /**
     * @dataProvider gateProvider
     *
     * @param array<string,mixed> $config
     */
    public function testRejectsInvalidGateState(array $config, string $warning): void
    {
        $result = $this->manager()->stage($this->engine, $config, array());

        self::assertFalse($result['staged']);
        self::assertSame(array($warning), $result['warnings']);
    }

    /**
     * @return array<string,array{config:array<string,mixed>,warning:string}>
     */
    public function gateProvider(): array
    {
        return array(
            'restore not confirmed' => array(
                'config' => array(),
                'warning' => 'Restore is not confirmed.',
            ),
            'rollback not prepared' => array(
                'config' => array('restore_confirmed' => true),
                'warning' => 'Rollback is not prepared.',
            ),
            'already staged' => array(
                'config' => array(
                    'restore_confirmed' => true,
                    'rollback_prepared' => true,
                    'rollback_database_dump' => 'rollback.sql',
                    'database_import_staged' => true,
                ),
                'warning' => 'Database import is already staged.',
            ),
            'installer locked' => array(
                'config' => array(
                    'restore_confirmed' => true,
                    'rollback_prepared' => true,
                    'rollback_database_dump' => 'rollback.sql',
                    'locked' => true,
                ),
                'warning' => 'Installer is locked.',
            ),
        );
    }

    public function testRejectsIncompleteDatabaseCredentials(): void
    {
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n");

        $result = $this->manager()->stage($this->engine, $this->readyConfig(), array());

        self::assertFalse($result['staged']);
        self::assertSame(array('Database credentials are incomplete.'), $result['warnings']);
    }

    public function testUsesConnectionTesterFailureMessage(): void
    {
        $result = $this->manager(new FakeFailingStagedImportConnectionTester())->stage($this->engine, $this->readyConfig(), array());

        self::assertFalse($result['staged']);
        self::assertSame(array('No database today.'), $result['warnings']);
    }

    public function testUsesManifestWarningsWhenManifestIsInvalid(): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('database/tables.json', '{"tables":');
        $zip->close();

        $result = $this->manager()->stage($this->engine, $this->readyConfig(), array());

        self::assertFalse($result['staged']);
        self::assertSame(array('Invalid database/tables.json.'), $result['warnings']);
    }

    public function testUsesImporterWarningsWhenImportFails(): void
    {
        $result = $this->manager(null, new FakeFailingDatabaseChunkImporter())->stage($this->engine, $this->readyConfig(), array());

        self::assertFalse($result['staged']);
        self::assertSame(array('Import failed.'), $result['warnings']);
    }

    public function testRejectsImportWhenStagingTableIsMissingAfterImport(): void
    {
        $config = $this->readyConfig();
        $this->writeConfig($config);

        $result = $this->manager(null, null, new FakeMissingStagedImportTableInspector())->stage($this->engine, $config, array());
        $updated = require $this->engine . '/config.php';

        self::assertFalse($result['staged']);
        self::assertSame(array('Missing staging table: ssc_tmp_' . substr(hash('sha256', 'restore'), 0, 8) . '_wp_posts'), $result['warnings']);
        self::assertArrayNotHasKey('database_import_staged', $updated);
    }

    public function testRecordsStagedImportMetadata(): void
    {
        $config = $this->readyConfig();
        $config['restore_job_id'] = 'restore-123';
        $config['existing_key'] = 'preserved';
        $config['database_import_in_progress'] = true;
        $config['database_import_cursor'] = array('table_index' => 0, 'chunk_index' => 0, 'statement_index' => 1);
        $config['database_import_chunk_count'] = 0;
        $config['database_import_statement_count'] = 1;
        $this->writeConfig($config);

        $result = $this->manager()->stage($this->engine, $config, array('HTTP_HOST' => 'destination.example'));
        $updated = require $this->engine . '/config.php';

        self::assertTrue($result['staged']);
        self::assertSame(1, $result['table_count']);
        self::assertSame(1, $result['chunk_count']);
        self::assertSame(2, $result['statement_count']);
        self::assertSame(array(), $result['warnings']);
        self::assertSame('preserved', $updated['existing_key']);
        self::assertTrue($updated['database_import_staged']);
        self::assertIsString($updated['database_import_staged_at']);
        self::assertSame(1, $updated['database_import_table_count']);
        self::assertSame(1, $updated['database_import_chunk_count']);
        self::assertSame(2, $updated['database_import_statement_count']);
        self::assertSame('ssc_tmp_' . substr(hash('sha256', 'restore-123'), 0, 8) . '_wp_posts', $updated['database_import_staging_tables']['wp_posts']);
        self::assertStringNotContainsString('secret', json_encode($updated['database_import_staging_tables']) ?: '');
        self::assertFalse($updated['database_import_in_progress']);
        self::assertArrayNotHasKey('database_import_cursor', $updated);
    }

    public function testPersistsImportCursorWhenDatabaseImportIsStillRunning(): void
    {
        $config = $this->readyConfig();
        $this->writeConfig($config);

        $result = $this->manager(null, new FakeInProgressDatabaseChunkImporter())->stage($this->engine, $config, array());
        $updated = require $this->engine . '/config.php';

        self::assertFalse($result['staged']);
        self::assertTrue($result['in_progress']);
        self::assertSame(1, $result['statement_count']);
        self::assertSame(array(), $result['warnings']);
        self::assertTrue($updated['database_import_in_progress']);
        self::assertSame(array('table_index' => 0, 'chunk_index' => 0, 'statement_index' => 1), $updated['database_import_cursor']);
        self::assertArrayNotHasKey('database_import_staged', $updated);
    }

    private function manager(?\SuperSheepCopyInstaller\DatabaseConnectionTester $connection_tester = null, ?\SuperSheepCopyInstaller\DatabaseChunkImporter $importer = null, ?\SuperSheepCopyInstaller\DatabaseTableInspector $table_inspector = null): \SuperSheepCopyInstaller\DatabaseImportPreparationManager
    {
        return new \SuperSheepCopyInstaller\DatabaseImportPreparationManager(
            new \SuperSheepCopyInstaller\WpConfigReader(),
            $connection_tester ?: new FakeStagedImportConnectionTester(),
            new \SuperSheepCopyInstaller\DatabaseImportManifestReader(),
            new \SuperSheepCopyInstaller\SqlTableNameRewriter(),
            $importer ?: new FakeDatabaseChunkImporter(),
            $table_inspector ?: new FakeStagedImportTableInspector()
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function readyConfig(): array
    {
        return array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/id/database/destination.sql',
            'staged_archive_path' => $this->archive,
            'locked' => false,
        );
    }

    private function writeArchive(): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('database/tables.json', json_encode(array(
            'format_version' => '1',
            'table_count' => 1,
            'tables' => array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
        )));
        $zip->addFromString('database/chunks/wp_posts.part001.sql', 'CREATE TABLE `wp_posts` (`ID` bigint);');
        $zip->close();
    }

    /**
     * @param array<string,mixed> $config
     */
    private function writeConfig(array $config): void
    {
        file_put_contents($this->engine . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n");
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $item) {
            $child = $path . '/' . $item;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}

final class FakeStagedImportConnectionTester extends \SuperSheepCopyInstaller\DatabaseConnectionTester
{
    /**
     * @param array<string,mixed> $credentials
     * @return array{connected:bool,status:string,message:string,database:string,host:string}
     */
    public function test(array $credentials): array
    {
        \PHPUnit\Framework\Assert::assertSame('secret', $credentials['password']);

        return array('connected' => true, 'status' => 'ok', 'message' => 'Connected', 'database' => 'wordpress', 'host' => 'localhost');
    }
}

final class FakeFailingStagedImportConnectionTester extends \SuperSheepCopyInstaller\DatabaseConnectionTester
{
    /**
     * @param array<string,mixed> $credentials
     * @return array{connected:bool,status:string,message:string,database:string,host:string}
     */
    public function test(array $credentials): array
    {
        return array('connected' => false, 'status' => 'error', 'message' => 'No database today.', 'database' => 'wordpress', 'host' => 'localhost');
    }
}

final class FakeDatabaseChunkImporter extends \SuperSheepCopyInstaller\DatabaseChunkImporter
{
    /**
     * @param array<string,mixed> $credentials
     * @param list<array{name:string,chunks:list<string>}> $tables
     * @param array<string,string> $chunks
     * @param array<string,string> $table_map
     * @return array{imported:bool,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    public function import(array $credentials, array $tables, array $chunks, array $table_map, \SuperSheepCopyInstaller\SqlTableNameRewriter $rewriter): array
    {
        \PHPUnit\Framework\Assert::assertSame(array('wp_posts'), array_keys($table_map));
        \PHPUnit\Framework\Assert::assertStringStartsWith('ssc_tmp_', $table_map['wp_posts']);

        return array('imported' => true, 'table_count' => 1, 'chunk_count' => 1, 'statement_count' => 2, 'warnings' => array());
    }

    /**
     * @param array<string,mixed> $credentials
     * @param list<array{name:string,chunks:list<string>}> $tables
     * @param array<string,string> $chunks
     * @param array<string,string> $table_map
     * @param array<string,mixed> $cursor
     * @return array{imported:bool,in_progress:bool,cursor:array<string,mixed>,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    public function importStep(array $credentials, array $tables, array $chunks, array $table_map, \SuperSheepCopyInstaller\SqlTableNameRewriter $rewriter, array $cursor = array(), float $budget_seconds = 10.0): array
    {
        unset($budget_seconds);

        $result = $this->import($credentials, $tables, $chunks, $table_map, $rewriter);
        $statement_count = $cursor === array() ? $result['statement_count'] : 1;

        return array(
            'imported' => $result['imported'],
            'in_progress' => false,
            'cursor' => $cursor,
            'table_count' => $result['table_count'],
            'chunk_count' => $result['chunk_count'],
            'statement_count' => $statement_count,
            'warnings' => $result['warnings'],
        );
    }
}

final class FakeStagedImportTableInspector extends \SuperSheepCopyInstaller\DatabaseTableInspector
{
    public function verifyTables(array $table_map, array $credentials = array()): array
    {
        unset($table_map, $credentials);

        return array('valid' => true, 'warnings' => array());
    }
}

final class FakeMissingStagedImportTableInspector extends \SuperSheepCopyInstaller\DatabaseTableInspector
{
    public function verifyTables(array $table_map, array $credentials = array()): array
    {
        unset($credentials);

        return array('valid' => false, 'warnings' => array('Missing staging table: ' . reset($table_map)));
    }
}

final class FakeFailingDatabaseChunkImporter extends \SuperSheepCopyInstaller\DatabaseChunkImporter
{
    /**
     * @param array<string,mixed> $credentials
     * @param list<array{name:string,chunks:list<string>}> $tables
     * @param array<string,string> $chunks
     * @param array<string,string> $table_map
     * @return array{imported:bool,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    public function import(array $credentials, array $tables, array $chunks, array $table_map, \SuperSheepCopyInstaller\SqlTableNameRewriter $rewriter): array
    {
        return array('imported' => false, 'table_count' => 1, 'chunk_count' => 0, 'statement_count' => 0, 'warnings' => array('Import failed.'));
    }

    /**
     * @param array<string,mixed> $credentials
     * @param list<array{name:string,chunks:list<string>}> $tables
     * @param array<string,string> $chunks
     * @param array<string,string> $table_map
     * @param array<string,mixed> $cursor
     * @return array{imported:bool,in_progress:bool,cursor:array<string,mixed>,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    public function importStep(array $credentials, array $tables, array $chunks, array $table_map, \SuperSheepCopyInstaller\SqlTableNameRewriter $rewriter, array $cursor = array(), float $budget_seconds = 10.0): array
    {
        unset($budget_seconds);

        $result = $this->import($credentials, $tables, $chunks, $table_map, $rewriter);

        return array(
            'imported' => $result['imported'],
            'in_progress' => false,
            'cursor' => $cursor,
            'table_count' => $result['table_count'],
            'chunk_count' => $result['chunk_count'],
            'statement_count' => $result['statement_count'],
            'warnings' => $result['warnings'],
        );
    }
}

final class FakeInProgressDatabaseChunkImporter extends \SuperSheepCopyInstaller\DatabaseChunkImporter
{
    /**
     * @param array<string,mixed> $credentials
     * @param list<array{name:string,chunks:list<string>}> $tables
     * @param array<string,string> $chunks
     * @param array<string,string> $table_map
     * @param array<string,mixed> $cursor
     * @return array{imported:bool,in_progress:bool,cursor:array<string,mixed>,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    public function importStep(array $credentials, array $tables, array $chunks, array $table_map, \SuperSheepCopyInstaller\SqlTableNameRewriter $rewriter, array $cursor = array(), float $budget_seconds = 10.0): array
    {
        unset($credentials, $tables, $chunks, $table_map, $rewriter, $cursor, $budget_seconds);

        return array(
            'imported' => false,
            'in_progress' => true,
            'cursor' => array('table_index' => 0, 'chunk_index' => 0, 'statement_index' => 1),
            'table_count' => 1,
            'chunk_count' => 0,
            'statement_count' => 1,
            'warnings' => array(),
        );
    }
}
