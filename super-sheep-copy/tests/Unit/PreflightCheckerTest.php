<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/EnvironmentChecker.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/ArchiveValidationResult.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackageReaderInterface.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackagePathGuard.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DirectoryPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/ZipPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/TarGzPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackageReaderFactory.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/ArchiveValidator.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DestinationDetector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PreflightChecker.php';

final class PreflightCheckerTest extends TestCase
{
    private string $root;
    private string $engine;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-preflight-root-' . bin2hex(random_bytes(4));
        $this->engine = $this->root . '/ssc-restore-engine';
        mkdir($this->engine, 0777, true);
        file_put_contents($this->root . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReportsOkArchiveAndDestinationChecksForValidContext(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $checks = $this->checker()->run(
            array('staged_archive_path' => $this->validArchive()),
            array('HTTP_HOST' => 'example.com', 'SCRIPT_NAME' => '/installer.php'),
            $this->engine
        );

        self::assertSame('ok', $this->status($checks, 'archive_readable'));
        self::assertSame('ok', $this->status($checks, 'archive_valid'));
        self::assertSame('ok', $this->status($checks, 'destination_url'));
        self::assertSame('ok', $this->status($checks, 'wp_config_readable'));
        self::assertFalse(\SuperSheepCopyInstaller\PreflightChecker::hasBlockingErrors($checks));
    }

    public function testReportsBlockingErrorForUnreadableArchive(): void
    {
        $checks = $this->checker()->run(
            array('staged_archive_path' => $this->root . '/missing.zip'),
            array('HTTP_HOST' => 'example.com', 'SCRIPT_NAME' => '/installer.php'),
            $this->engine
        );

        self::assertSame('error', $this->status($checks, 'archive_readable'));
        self::assertTrue(\SuperSheepCopyInstaller\PreflightChecker::hasBlockingErrors($checks));
    }

    public function testReportsWarningForUnreadableWpConfig(): void
    {
        unlink($this->root . '/wp-config.php');

        $checks = $this->checker()->run(
            array('staged_archive_path' => $this->validArchive()),
            array('HTTP_HOST' => 'example.com', 'SCRIPT_NAME' => '/installer.php'),
            $this->engine
        );

        self::assertSame('warning', $this->status($checks, 'wp_config_readable'));
    }

    public function testReportsDatabaseConnectionWithoutSecrets(): void
    {
        $checks = $this->checker()->run(
            array('staged_archive_path' => $this->validArchive()),
            array('HTTP_HOST' => 'example.com', 'SCRIPT_NAME' => '/installer.php'),
            $this->engine
        );

        $database_check = $this->check($checks, 'database_connection');

        self::assertSame('database_connection', $database_check['key']);
        self::assertSame('Database connection', $database_check['label']);
        self::assertContains($database_check['status'], array('ok', 'warning', 'error'));
        self::assertContains($database_check['value'], array('Connected', 'Unavailable'));
        self::assertStringNotContainsString('secret', json_encode($database_check) ?: '');
    }

    private function checker(): \SuperSheepCopyInstaller\PreflightChecker
    {
        return new \SuperSheepCopyInstaller\PreflightChecker(
            new \SuperSheepCopyInstaller\EnvironmentChecker(),
            new \SuperSheepCopyInstaller\DestinationDetector(),
            new \SuperSheepCopyInstaller\WpConfigReader(),
            new \SuperSheepCopyInstaller\ArchiveValidator()
        );
    }

    /**
     * @param array<int,array{key:string,status:string}> $checks
     */
    private function status(array $checks, string $key): string
    {
        foreach ($checks as $check) {
            if ($check['key'] === $key) {
                return $check['status'];
            }
        }

        return '';
    }

    /**
     * @param array<int,array{key:string,label:string,status:string,value:string,message:string}> $checks
     * @return array{key:string,label:string,status:string,value:string,message:string}
     */
    private function check(array $checks, string $key): array
    {
        foreach ($checks as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        self::fail('Missing preflight check: ' . $key);
    }

    private function validArchive(): string
    {
        $archive = $this->root . '/backup.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', json_encode(array(
            'project' => 'Super Sheep Copy',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
        )));
        $zip->addFromString('database/tables.json', '{}');
        $zip->addFromString('database/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');
        $zip->addFromString('files/index.php', '<?php echo "site";');
        $zip->addFromString('checksums.json', (string) json_encode(array(
            'database/tables.json' => hash('sha256', '{}'),
            'database/chunks/wp_posts.part001.sql' => hash('sha256', 'CREATE TABLE wp_posts;'),
            'files/index.php' => hash('sha256', '<?php echo "site";'),
        )));
        $zip->close();

        return $archive;
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
