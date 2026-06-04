<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\ArchiveWriter;
use SuperSheepCopy\Backup\BackupArchivePackager;
use SuperSheepCopy\Backup\ManifestBuilder;
use SuperSheepCopy\Backup\Package\DirectoryPackageWriter;
use SuperSheepCopy\Backup\Package\PackageWriterFactory;
use SuperSheepCopy\Backup\ScannedFile;
use ZipArchive;

final class BackupArchivePackagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-packager-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/site/wp-content/uploads', 0777, true);
        mkdir($this->root . '/working/database/wp_posts', 0777, true);
        file_put_contents($this->root . '/site/wp-content/uploads/file.txt', 'backup file');
        file_put_contents($this->root . '/working/database/tables.json', '{"tables":[]}');
        file_put_contents($this->root . '/working/database/wp_posts/chunk-000001.sql', 'INSERT INTO wp_posts VALUES (1);');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPackagesBackupArchiveWithManifestChecksumsAndDatabaseFiles(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $packager = new BackupArchivePackager(new ArchiveWriter(), new ManifestBuilder('0.1.0', '1'));
        $result = $packager->package(
            'backup-123',
            $this->root . '/working',
            $this->root . '/working/database',
            array(new ScannedFile($this->root . '/site/wp-content/uploads/file.txt', 'wp-content/uploads/file.txt', 11, false)),
            $this->metadata()
        );

        self::assertSame($this->root . '/working/backup-123.zip', $result->archivePath());
        self::assertGreaterThan(0, $result->archiveSize());
        self::assertSame(1, $result->siteFileCount());
        self::assertSame(2, $result->databaseFileCount());
        self::assertSame(hash('sha256', 'backup file'), $result->checksums()['files/wp-content/uploads/file.txt']);
        self::assertSame(hash('sha256', '{"tables":[]}'), $result->checksums()['database/tables.json']);
        self::assertSame(hash('sha256', 'INSERT INTO wp_posts VALUES (1);'), $result->checksums()['database/wp_posts/chunk-000001.sql']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($result->archivePath()));
        self::assertSame('backup file', $zip->getFromName('files/wp-content/uploads/file.txt'));
        self::assertSame('{"tables":[]}', $zip->getFromName('database/tables.json'));
        self::assertSame('INSERT INTO wp_posts VALUES (1);', $zip->getFromName('database/wp_posts/chunk-000001.sql'));

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['file_count']);
        self::assertSame(2, $manifest['database_table_count']);
        self::assertSame($result->archiveSize(), $manifest['archive_size']);
        self::assertSame($result->checksums(), $manifest['checksums']);

        $checksums = json_decode((string) $zip->getFromName('checksums.json'), true);
        self::assertSame($result->checksums(), $checksums);
        self::assertStringContainsString('Backup backup-123 packaged.', (string) $zip->getFromName('logs/backup.log'));
        $zip->close();
    }

    public function testPackagesBackupDirectoryWhenDirectoryWriterIsSelected(): void
    {
        $packager = new BackupArchivePackager(
            new ArchiveWriter(),
            new ManifestBuilder('0.1.0', '1'),
            new PackageWriterFactory(array(new DirectoryPackageWriter()))
        );

        $result = $packager->package(
            'backup-123',
            $this->root . '/working',
            $this->root . '/working/database',
            array(new ScannedFile($this->root . '/site/wp-content/uploads/file.txt', 'uploads/a.txt', 11, false)),
            $this->metadata()
        );

        self::assertSame($this->root . '/working/backup-123', $result->archivePath());
        self::assertSame('directory', $result->packageFormat());
        self::assertSame('', $result->packageExtension());
        self::assertFileExists($this->root . '/working/backup-123/manifest.json');
        self::assertFileExists($this->root . '/working/backup-123/files/uploads/a.txt');

        $manifest = json_decode((string) file_get_contents($this->root . '/working/backup-123/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertSame('directory', $manifest['package_format']);
        self::assertSame('', $manifest['package_extension']);
        self::assertSame(1, $manifest['package_schema_version']);
    }

    public function testRejectsMissingDatabaseDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database export directory does not exist.');

        $packager = new BackupArchivePackager(new ArchiveWriter(), new ManifestBuilder('0.1.0', '1'));
        $packager->package(
            'backup-123',
            $this->root . '/working',
            $this->root . '/working/missing-database',
            array(),
            $this->metadata()
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(): array
    {
        return array(
            'source_site_url' => 'https://example.com',
            'source_home_url' => 'https://example.com',
            'wordpress_version' => '6.5',
            'php_version' => PHP_VERSION,
            'database_version' => '8.0',
            'table_prefix' => 'wp_',
            'is_multisite' => false,
            'active_theme' => 'twentytwentyfour',
            'active_plugins' => array('akismet/akismet.php'),
            'must_use_plugins' => array(),
            'created_at' => '2026-05-16T12:00:00+00:00',
            'file_count' => 0,
            'database_table_count' => 0,
            'archive_size' => 0,
            'checksums' => array(),
            'exclusions' => array('wp-content/cache'),
            'environment' => array('zip' => true),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
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
