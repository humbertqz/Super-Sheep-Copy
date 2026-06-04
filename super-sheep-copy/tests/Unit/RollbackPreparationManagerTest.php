<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackFileCollector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackManifestBuilder.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DestinationDetector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackDatabaseDumper.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackPreparationManager.php';

final class RollbackPreparationManagerTest extends TestCase
{
    private string $root;
    private string $engine;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-rollback-manager-' . bin2hex(random_bytes(4));
        $this->engine = $this->root . '/ssc-restore-engine';
        mkdir($this->engine, 0777, true);
        file_put_contents($this->root . '/wp-config.php', "<?php\n\$table_prefix = 'wp_';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRejectsUnconfirmedConfig(): void
    {
        $this->writeConfig(array('restore_job_id' => 'restore-123'));

        $result = $this->manager()->prepare($this->engine, require $this->engine . '/config.php', array());

        self::assertFalse($result['prepared']);
        self::assertSame('Restore is not confirmed.', $result['warnings'][0]);
    }

    public function testRejectsLockedConfig(): void
    {
        $config = $this->confirmedConfig();
        $config['locked'] = true;
        $this->writeConfig($config);

        $result = $this->manager()->prepare($this->engine, require $this->engine . '/config.php', array());

        self::assertFalse($result['prepared']);
        self::assertSame('Installer is locked.', $result['warnings'][0]);
    }

    public function testCreatesRollbackDirectoryCopiedFileAndManifestAndUpdatesConfig(): void
    {
        $this->writeConfig($this->confirmedConfig());

        $result = $this->manager()->prepare($this->engine, require $this->engine . '/config.php', array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php'));

        self::assertTrue($result['prepared']);
        self::assertSame(1, $result['file_count']);
        self::assertDirectoryExists($this->engine . '/rollback/' . $result['rollback_directory']);
        self::assertFileExists($this->engine . '/rollback/' . $result['rollback_directory'] . '/files/wp-config.php');
        self::assertFileExists($this->engine . '/rollback/' . $result['rollback_directory'] . '/manifest.json');

        $config = require $this->engine . '/config.php';
        self::assertTrue($config['rollback_prepared']);
        self::assertArrayHasKey('rollback_prepared_at', $config);
        self::assertSame($result['rollback_directory'], $config['rollback_directory']);
        self::assertSame('rollback/' . $result['rollback_directory'] . '/manifest.json', $config['rollback_manifest']);
        self::assertFalse($result['database_included']);
        self::assertSame('', $config['rollback_database_dump']);
        self::assertSame(0, $config['rollback_database_table_count']);

        $manifest = json_decode((string) file_get_contents($this->engine . '/' . $config['rollback_manifest']), true);
        self::assertSame('rollback', $manifest['type']);
        self::assertSame('http://destination.example', $manifest['destination_url']);
        self::assertFalse($manifest['database']['included']);
    }

    public function testAllowsManifestOnlyRollbackWhenWpConfigMissing(): void
    {
        unlink($this->root . '/wp-config.php');
        $this->writeConfig($this->confirmedConfig());

        $result = $this->manager()->prepare($this->engine, require $this->engine . '/config.php', array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php'));

        self::assertTrue($result['prepared']);
        self::assertSame(0, $result['file_count']);
        self::assertSame(array('wp-config.php is not readable.'), $result['warnings']);
    }

    public function testSkipsDatabaseDumpWhenCredentialsAreIncomplete(): void
    {
        $this->writeConfig($this->confirmedConfig());

        $result = $this->managerWithDatabaseDumper(new FakeRollbackDatabaseDumper())->prepare(
            $this->engine,
            require $this->engine . '/config.php',
            array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php')
        );

        $config = require $this->engine . '/config.php';
        $manifest = json_decode((string) file_get_contents($this->engine . '/' . $config['rollback_manifest']), true);

        self::assertTrue($result['prepared']);
        self::assertFalse($result['database_included']);
        self::assertSame('', $config['rollback_database_dump']);
        self::assertSame(0, $config['rollback_database_table_count']);
        self::assertContains('Database credentials are incomplete.', $result['warnings']);
        self::assertFalse($manifest['database']['included']);
        self::assertSame(array('Database credentials are incomplete.'), $manifest['database']['warnings']);
    }

    public function testAddsDatabaseDumpMetadataToManifestAndConfig(): void
    {
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");
        $this->writeConfig($this->confirmedConfig());

        $result = $this->managerWithDatabaseDumper(new FakeRollbackDatabaseDumper())->prepare(
            $this->engine,
            require $this->engine . '/config.php',
            array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php')
        );

        $config = require $this->engine . '/config.php';
        $manifest = json_decode((string) file_get_contents($this->engine . '/' . $config['rollback_manifest']), true);

        self::assertTrue($result['database_included']);
        self::assertSame('rollback/' . $result['rollback_directory'] . '/database/destination.sql', $config['rollback_database_dump']);
        self::assertSame(2, $config['rollback_database_table_count']);
        self::assertArrayHasKey('rollback_database_dumped_at', $config);
        self::assertTrue($manifest['database']['included']);
        self::assertSame(2, $manifest['database']['table_count']);
        self::assertStringNotContainsString('secret', json_encode($manifest) ?: '');
    }

    private function manager(): \SuperSheepCopyInstaller\RollbackPreparationManager
    {
        return new \SuperSheepCopyInstaller\RollbackPreparationManager(
            new \SuperSheepCopyInstaller\RollbackFileCollector(),
            new \SuperSheepCopyInstaller\RollbackManifestBuilder(),
            new \SuperSheepCopyInstaller\DestinationDetector()
        );
    }

    private function managerWithDatabaseDumper(\SuperSheepCopyInstaller\RollbackDatabaseDumper $dumper): \SuperSheepCopyInstaller\RollbackPreparationManager
    {
        return new \SuperSheepCopyInstaller\RollbackPreparationManager(
            new \SuperSheepCopyInstaller\RollbackFileCollector(),
            new \SuperSheepCopyInstaller\RollbackManifestBuilder(),
            new \SuperSheepCopyInstaller\DestinationDetector(),
            new \SuperSheepCopyInstaller\WpConfigReader(),
            new FakeDatabaseConnectionTester(),
            $dumper
        );
    }

    private function confirmedConfig(): array
    {
        return array(
            'restore_confirmed' => true,
            'restore_job_id' => 'restore-123',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
            'staged_archive_basename' => 'restore-123.zip',
            'locked' => false,
        );
    }

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

final class FakeDatabaseConnectionTester extends \SuperSheepCopyInstaller\DatabaseConnectionTester
{
    public function test(array $credentials): array
    {
        return array(
            'connected' => true,
            'status' => 'ok',
            'message' => 'Connected',
            'database' => isset($credentials['name']) ? (string) $credentials['name'] : '',
            'host' => isset($credentials['host']) ? (string) $credentials['host'] : '',
        );
    }
}

final class FakeRollbackDatabaseDumper extends \SuperSheepCopyInstaller\RollbackDatabaseDumper
{
    public function dump(array $credentials, string $rollback_directory): array
    {
        mkdir($rollback_directory . '/database', 0777, true);
        file_put_contents($rollback_directory . '/database/destination.sql', "-- dump\n");

        return array('included' => true, 'dump_path' => 'database/destination.sql', 'table_count' => 2, 'warnings' => array());
    }
}
