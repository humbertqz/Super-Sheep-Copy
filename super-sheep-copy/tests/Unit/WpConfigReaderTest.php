<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';

final class WpConfigReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-wp-config-reader-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: array() as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->root);
    }

    public function testParsesDatabaseConstantsAndTablePrefix(): void
    {
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");

        $config = (new \SuperSheepCopyInstaller\WpConfigReader())->readDatabaseConfig($this->root);

        self::assertTrue($config['readable']);
        self::assertTrue($config['has_db_name']);
        self::assertTrue($config['has_db_user']);
        self::assertTrue($config['has_db_password']);
        self::assertTrue($config['has_db_host']);
        self::assertTrue($config['has_table_prefix']);
        self::assertSame('wp_', $config['table_prefix']);
        self::assertArrayNotHasKey('db_password', $config);
        self::assertStringNotContainsString('secret', json_encode($config) ?: '');
    }

    public function testReportsMissingConfigAsUnreadable(): void
    {
        $config = (new \SuperSheepCopyInstaller\WpConfigReader())->readDatabaseConfig($this->root);

        self::assertFalse($config['readable']);
        self::assertFalse($config['has_db_name']);
        self::assertFalse($config['has_db_user']);
        self::assertFalse($config['has_db_password']);
        self::assertFalse($config['has_db_host']);
        self::assertFalse($config['has_table_prefix']);
    }

    public function testReadsTrustedDatabaseCredentialsWithoutChangingSecretFreeSummary(): void
    {
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost:3307');\n"
            . "define('DB_CHARSET', 'utf8mb4');\n"
            . "\$table_prefix = 'wp_';\n");

        $reader = new \SuperSheepCopyInstaller\WpConfigReader();
        $credentials = $reader->readDatabaseCredentials($this->root);
        $summary = $reader->readDatabaseConfig($this->root);

        self::assertTrue($credentials['readable']);
        self::assertTrue($credentials['complete']);
        self::assertSame('wordpress', $credentials['name']);
        self::assertSame('dbuser', $credentials['user']);
        self::assertSame('secret', $credentials['password']);
        self::assertSame('localhost', $credentials['host']);
        self::assertSame(3307, $credentials['port']);
        self::assertSame('', $credentials['socket']);
        self::assertSame('utf8mb4', $credentials['charset']);
        self::assertSame('wp_', $credentials['table_prefix']);

        self::assertArrayNotHasKey('password', $summary);
        self::assertStringNotContainsString('secret', json_encode($summary) ?: '');
    }

    public function testReadsParentDirectoryWpConfig(): void
    {
        $site_root = $this->root . '/public';
        mkdir($site_root);
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'parent_db');\n"
            . "define('DB_USER', 'parent_user');\n"
            . "define('DB_PASSWORD', 'parent_secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'sp_';\n");

        $credentials = (new \SuperSheepCopyInstaller\WpConfigReader())->readDatabaseCredentials($site_root);

        self::assertTrue($credentials['readable']);
        self::assertTrue($credentials['complete']);
        self::assertSame('parent_db', $credentials['name']);
        self::assertSame('parent_user', $credentials['user']);
        self::assertSame('parent_secret', $credentials['password']);
        self::assertSame('sp_', $credentials['table_prefix']);
    }

    public function testReadsEscapedDatabaseConstantValues(): void
    {
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'sec\\'ret\\\\value');\n"
            . "define('DB_HOST', 'localhost');\n");

        $reader = new \SuperSheepCopyInstaller\WpConfigReader();
        $credentials = $reader->readDatabaseCredentials($this->root);
        $summary = $reader->readDatabaseConfig($this->root);

        self::assertTrue($summary['has_db_password']);
        self::assertTrue($credentials['complete']);
        self::assertSame("sec'ret\\value", $credentials['password']);
    }

    public function testSplitsSocketLikeDatabaseHost(): void
    {
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost:/tmp/mysql.sock');\n"
            . "\$table_prefix = 'wp_';\n");

        $credentials = (new \SuperSheepCopyInstaller\WpConfigReader())->readDatabaseCredentials($this->root);

        self::assertSame('localhost', $credentials['host']);
        self::assertSame(0, $credentials['port']);
        self::assertSame('/tmp/mysql.sock', $credentials['socket']);
    }

    public function testUnreadableCredentialsAreIncompleteAndSecretFree(): void
    {
        $credentials = (new \SuperSheepCopyInstaller\WpConfigReader())->readDatabaseCredentials($this->root);

        self::assertFalse($credentials['readable']);
        self::assertFalse($credentials['complete']);
        self::assertSame('', $credentials['password']);
        self::assertStringNotContainsString('secret', json_encode($credentials) ?: '');
    }

    public function testEmptyDatabasePasswordCanStillBeComplete(): void
    {
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', '');\n"
            . "define('DB_HOST', 'localhost');\n");

        $reader = new \SuperSheepCopyInstaller\WpConfigReader();
        $credentials = $reader->readDatabaseCredentials($this->root);
        $summary = $reader->readDatabaseConfig($this->root);

        self::assertTrue($credentials['complete']);
        self::assertSame('', $credentials['password']);
        self::assertTrue($summary['has_db_password']);
    }
}
