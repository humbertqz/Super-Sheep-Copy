<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupArchiveStepPackager;
use SuperSheepCopy\Backup\ManifestBuilder;
use SuperSheepCopy\Backup\Package\DirectoryPackageWriter;
use SuperSheepCopy\Backup\Package\PackageWriterFactory;
use SuperSheepCopy\Backup\Package\PackageWriterInterface;
use SuperSheepCopy\Backup\Package\TarGzPackageWriter;
use SuperSheepCopy\Backup\ScannedFile;
use PharData;
use ZipArchive;

final class BackupArchiveStepPackagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-step-packager-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/site/uploads', 0777, true);
        mkdir($this->root . '/working/database/chunks', 0777, true);
        file_put_contents($this->root . '/site/uploads/a.txt', 'a');
        file_put_contents($this->root . '/site/uploads/b.txt', 'b');
        file_put_contents($this->root . '/working/database/tables.json', '{"tables":[]}');
        file_put_contents($this->root . '/working/database/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPackagesArchiveAcrossMultipleBoundedSteps(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $packager = new BackupArchiveStepPackager(new ManifestBuilder('0.1.0', '1'), 2);
        $payload = array();
        $site_files = array(
            new ScannedFile($this->root . '/site/uploads/a.txt', 'uploads/a.txt', 1, false),
            new ScannedFile($this->root . '/site/uploads/b.txt', 'uploads/b.txt', 1, false),
        );

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), $payload);

        self::assertFalse($payload['archive_complete']);
        self::assertSame(2, $payload['archive_index']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->root . '/working/backup-123.zip'));
        self::assertSame('a', $zip->getFromName('files/uploads/a.txt'));
        self::assertSame('b', $zip->getFromName('files/uploads/b.txt'));
        self::assertFalse($zip->getFromName('database/tables.json'));
        self::assertFalse($zip->getFromName('manifest.json'));
        $zip->close();

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), $payload);

        self::assertTrue($payload['archive_complete']);
        self::assertSame('valid', $payload['archive_validation_status']);
        self::assertSame(array(), $payload['archive_validation_errors']);
        self::assertGreaterThan(0, $payload['archive_size']);
        self::assertSame(2, $payload['archive_site_file_count']);
        self::assertSame(2, $payload['archive_database_file_count']);
        self::assertSame('zip', $payload['package_format']);
        self::assertSame('.zip', $payload['package_extension']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->root . '/working/backup-123.zip'));
        self::assertSame('{"tables":[]}', $zip->getFromName('database/tables.json'));
        self::assertSame('CREATE TABLE wp_posts;', $zip->getFromName('database/chunks/wp_posts.part001.sql'));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertSame(2, $manifest['file_count']);
        self::assertSame(2, $manifest['database_table_count']);
        self::assertSame($payload['archive_size'], $manifest['archive_size']);
        self::assertSame(hash('sha256', 'a'), $manifest['checksums']['files/uploads/a.txt']);
        self::assertSame(hash('sha256', '{"tables":[]}'), $manifest['checksums']['database/tables.json']);
        self::assertSame(hash('sha256', 'CREATE TABLE wp_posts;'), $manifest['checksums']['database/chunks/wp_posts.part001.sql']);
        $zip->close();
    }

    public function testPackagesDirectoryPackageAcrossMultipleBoundedSteps(): void
    {
        $packager = new BackupArchiveStepPackager(
            new ManifestBuilder('0.1.0', '1'),
            2,
            20.0,
            new PackageWriterFactory(array(new DirectoryPackageWriter()))
        );
        $payload = array();
        $site_files = array(
            new ScannedFile($this->root . '/site/uploads/a.txt', 'uploads/a.txt', 1, false),
            new ScannedFile($this->root . '/site/uploads/b.txt', 'uploads/b.txt', 1, false),
        );

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), $payload);

        self::assertFalse($payload['archive_complete']);
        self::assertSame(2, $payload['archive_index']);
        self::assertSame('directory', $payload['package_format']);
        self::assertSame('', $payload['package_extension']);
        self::assertSame($this->root . '/working/backup-123', $payload['archive_path']);
        self::assertFileExists($this->root . '/working/backup-123/files/uploads/a.txt');

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), $payload);

        self::assertTrue($payload['archive_complete']);
        self::assertSame('valid', $payload['archive_validation_status']);
        self::assertSame(array(), $payload['archive_validation_errors']);
        self::assertSame('directory', $payload['package_format']);
        self::assertSame('', $payload['package_extension']);
        self::assertFileExists($this->root . '/working/backup-123/manifest.json');
        self::assertFileExists($this->root . '/working/backup-123/checksums.json');
        self::assertFileExists($this->root . '/working/backup-123/logs/backup.log');
        self::assertSame('{"tables":[]}', file_get_contents($this->root . '/working/backup-123/database/tables.json'));

        $manifest = json_decode((string) file_get_contents($this->root . '/working/backup-123/manifest.json'), true);
        self::assertSame('directory', $manifest['package_format']);
        self::assertSame('', $manifest['package_extension']);
        self::assertSame(1, $manifest['package_schema_version']);
        self::assertSame(2, $manifest['file_count']);
        self::assertSame(2, $manifest['database_table_count']);
    }

    public function testPackagesTarGzPackageAcrossMultipleBoundedStepsWhenZipIsUnavailable(): void
    {
        if (!class_exists(PharData::class)) {
            self::markTestSkipped('PharData is not available.');
        }

        $packager = new BackupArchiveStepPackager(
            new ManifestBuilder('0.1.0', '1'),
            2,
            20.0,
            new PackageWriterFactory(array(
                new StepPackagerUnavailableWriter('zip', '.zip'),
                new TarGzPackageWriter(),
                new DirectoryPackageWriter(),
            ))
        );
        $payload = array();
        $site_files = array(
            new ScannedFile($this->root . '/site/uploads/a.txt', 'uploads/a.txt', 1, false),
            new ScannedFile($this->root . '/site/uploads/b.txt', 'uploads/b.txt', 1, false),
        );

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), $payload);

        self::assertFalse($payload['archive_complete']);
        self::assertSame(2, $payload['archive_index']);
        self::assertSame('tar.gz', $payload['package_format']);
        self::assertSame('.tar.gz', $payload['package_extension']);
        self::assertSame($this->root . '/working/backup-123.tar.gz', $payload['archive_path']);

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), $payload);

        self::assertTrue($payload['archive_complete']);
        self::assertSame('valid', $payload['archive_validation_status']);
        self::assertSame(array(), $payload['archive_validation_errors']);
        self::assertFileExists($this->root . '/working/backup-123.tar.gz');
        self::assertDirectoryDoesNotExist($this->root . '/working/backup-123.tar.gz.staging');

        $extract_path = $this->root . '/extracted';
        mkdir($extract_path, 0777, true);
        $phar = new PharData($this->root . '/working/backup-123.tar.gz');
        $phar->extractTo($extract_path);

        self::assertSame('a', file_get_contents($extract_path . '/files/uploads/a.txt'));
        self::assertSame('b', file_get_contents($extract_path . '/files/uploads/b.txt'));
        self::assertSame('{"tables":[]}', file_get_contents($extract_path . '/database/tables.json'));
        self::assertSame('CREATE TABLE wp_posts;', file_get_contents($extract_path . '/database/chunks/wp_posts.part001.sql'));
        self::assertFileExists($extract_path . '/manifest.json');
        self::assertFileExists($extract_path . '/checksums.json');
        self::assertFileExists($extract_path . '/logs/backup.log');

        $manifest = json_decode((string) file_get_contents($extract_path . '/manifest.json'), true);
        self::assertSame('tar.gz', $manifest['package_format']);
        self::assertSame('.tar.gz', $manifest['package_extension']);
        self::assertSame(1, $manifest['package_schema_version']);
        self::assertSame(2, $manifest['file_count']);
        self::assertSame(2, $manifest['database_table_count']);
    }

    public function testDefaultPackagingBatchHandlesHundredsOfSmallEntriesPerStep(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $site_files = array();
        for ($index = 1; $index <= 700; $index++) {
            $path = $this->root . '/site/uploads/file-' . $index . '.txt';
            file_put_contents($path, 'file ' . $index);
            $site_files[] = new ScannedFile($path, 'uploads/file-' . $index . '.txt', filesize($path) ?: 0, false);
        }

        $packager = new BackupArchiveStepPackager(new ManifestBuilder('0.1.0', '1'));
        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), array());

        self::assertTrue($payload['archive_complete']);
        self::assertSame(702, $payload['archive_index']);
    }

    public function testPclZipPackagingUsesSmallBatchesToAvoidRequestTimeouts(): void
    {
        $site_files = array();
        for ($index = 1; $index <= 100; $index++) {
            $path = $this->root . '/site/uploads/pclzip-file-' . $index . '.txt';
            file_put_contents($path, 'file ' . $index);
            $site_files[] = new ScannedFile($path, 'uploads/pclzip-file-' . $index . '.txt', filesize($path) ?: 0, false);
        }

        $packager = new BackupArchiveStepPackager(
            new ManifestBuilder('0.1.0', '1'),
            2000,
            20.0,
            new PackageWriterFactory(array(new StepPackagerRecordingWriter('pclzip', '.zip')))
        );
        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), array());

        self::assertFalse($payload['archive_complete']);
        self::assertSame(50, $payload['archive_index']);
        self::assertSame('pclzip', $payload['package_format']);
    }

    public function testCliZipPackagingUsesSmallBatchesToAvoidRequestTimeouts(): void
    {
        $site_files = array();
        for ($index = 1; $index <= 100; $index++) {
            $path = $this->root . '/site/uploads/cli-zip-file-' . $index . '.txt';
            file_put_contents($path, 'file ' . $index);
            $site_files[] = new ScannedFile($path, 'uploads/cli-zip-file-' . $index . '.txt', filesize($path) ?: 0, false);
        }

        $packager = new BackupArchiveStepPackager(
            new ManifestBuilder('0.1.0', '1'),
            2000,
            20.0,
            new PackageWriterFactory(array(new StepPackagerRecordingWriter('zip-cli', '.zip')))
        );
        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), array());

        self::assertFalse($payload['archive_complete']);
        self::assertSame(50, $payload['archive_index']);
        self::assertSame('zip-cli', $payload['package_format']);
    }


    public function testPackagingTimeBudgetStopsBatchAfterAtLeastOneEntry(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $packager = new BackupArchiveStepPackager(new ManifestBuilder('0.1.0', '1'), 500, 0.0);
        $site_files = array(
            new ScannedFile($this->root . '/site/uploads/a.txt', 'uploads/a.txt', 1, false),
            new ScannedFile($this->root . '/site/uploads/b.txt', 'uploads/b.txt', 1, false),
        );

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), array());

        self::assertFalse($payload['archive_complete']);
        self::assertSame(1, $payload['archive_index']);
    }

    public function testPackagingProgressMessageIncludesRateAndEta(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $packager = new BackupArchiveStepPackager(new ManifestBuilder('0.1.0', '1'), 2);
        $site_files = array(
            new ScannedFile($this->root . '/site/uploads/a.txt', 'uploads/a.txt', 1, false),
            new ScannedFile($this->root . '/site/uploads/b.txt', 'uploads/b.txt', 1, false),
        );

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), array());

        self::assertArrayHasKey('archive_entries_per_second', $payload);
        self::assertArrayHasKey('archive_eta_seconds', $payload);
        self::assertStringContainsString('ETA', $payload['message']);
    }

    public function testPackagingRecordsByteThroughputAndAdaptiveBudget(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $packager = new BackupArchiveStepPackager(new ManifestBuilder('0.1.0', '1'), 2);
        $site_files = array(
            new ScannedFile($this->root . '/site/uploads/a.txt', 'uploads/a.txt', 1, false),
            new ScannedFile($this->root . '/site/uploads/b.txt', 'uploads/b.txt', 1, false),
        );

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), array());

        self::assertArrayHasKey('archive_last_step_bytes', $payload);
        self::assertGreaterThan(0, $payload['archive_last_step_bytes']);
        self::assertArrayHasKey('archive_mb_per_second', $payload);
        self::assertArrayHasKey('archive_adaptive_time_budget_seconds', $payload);
        self::assertSame('archive', $payload['backup_bottleneck']);
    }

    public function testPackagingStoresEntriesAndChecksumsOutsidePayload(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $packager = new BackupArchiveStepPackager(new ManifestBuilder('0.1.0', '1'), 2);
        $site_files = array(
            new ScannedFile($this->root . '/site/uploads/a.txt', 'uploads/a.txt', 1, false),
            new ScannedFile($this->root . '/site/uploads/b.txt', 'uploads/b.txt', 1, false),
        );

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), array());

        self::assertFalse($payload['archive_complete']);
        self::assertArrayHasKey('archive_entries_path', $payload);
        self::assertArrayHasKey('archive_checksums_path', $payload);
        self::assertFileExists($payload['archive_entries_path']);
        self::assertFileExists($payload['archive_checksums_path']);
        self::assertArrayNotHasKey('archive_entries', $payload);
        self::assertArrayNotHasKey('archive_checksums', $payload);
        self::assertStringContainsString('"archive_name":"files/uploads/a.txt"', (string) file_get_contents($payload['archive_entries_path']));
        self::assertStringContainsString('"files\\/uploads\\/a.txt"', (string) file_get_contents($payload['archive_checksums_path']));

        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', $site_files, $this->metadata(), $payload);

        self::assertTrue($payload['archive_complete']);
        self::assertArrayNotHasKey('archive_entries', $payload);
        self::assertArrayNotHasKey('archive_checksums', $payload);
    }

    public function testPackagingMigratesLegacyPayloadArraysToManifestFiles(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $payload = array(
            'package_format' => 'zip',
            'package_extension' => '.zip',
            'package_schema_version' => 1,
            'archive_path' => $this->root . '/working/backup-123.zip',
            'archive_entries' => array(
                array(
                    'absolute_path' => $this->root . '/site/uploads/a.txt',
                    'archive_name' => 'files/uploads/a.txt',
                    'size' => 1,
                    'symlink' => false,
                ),
                array(
                    'absolute_path' => $this->root . '/site/uploads/b.txt',
                    'archive_name' => 'files/uploads/b.txt',
                    'size' => 1,
                    'symlink' => false,
                ),
            ),
            'archive_checksums' => array(),
            'archive_index' => 0,
            'archive_site_file_count' => 2,
            'archive_database_file_count' => 0,
            'archive_started_at' => microtime(true),
        );

        $packager = new BackupArchiveStepPackager(new ManifestBuilder('0.1.0', '1'), 1);
        $payload = $packager->packageStep('backup-123', $this->root . '/working', $this->root . '/working/database', array(), $this->metadata(), $payload);

        self::assertFalse($payload['archive_complete']);
        self::assertArrayHasKey('archive_entries_path', $payload);
        self::assertArrayHasKey('archive_checksums_path', $payload);
        self::assertArrayNotHasKey('archive_entries', $payload);
        self::assertArrayNotHasKey('archive_checksums', $payload);
        self::assertFileExists($payload['archive_entries_path']);
        self::assertFileExists($payload['archive_checksums_path']);
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
            'active_theme' => 'theme',
            'active_plugins' => array(),
            'must_use_plugins' => array(),
            'created_at' => '2026-05-16T12:00:00+00:00',
            'file_count' => 0,
            'database_table_count' => 0,
            'archive_size' => 0,
            'checksums' => array(),
            'exclusions' => array(),
            'environment' => array(),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
                continue;
            }
            unlink($child);
        }
        rmdir($path);
    }
}

final class StepPackagerUnavailableWriter implements PackageWriterInterface
{
    private string $format;
    private string $extension;

    public function __construct(string $format, string $extension)
    {
        $this->format = $format;
        $this->extension = $extension;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function open(string $package_path): void
    {
        file_put_contents($package_path, 'recording writer');
    }

    public function addFile(string $source_path, string $entry_path): void
    {
    }

    public function addString(string $entry_path, string $contents): void
    {
    }

    public function close(): void
    {
    }
}

final class StepPackagerRecordingWriter implements PackageWriterInterface
{
    private string $format;
    private string $extension;

    public function __construct(string $format, string $extension)
    {
        $this->format = $format;
        $this->extension = $extension;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function open(string $package_path): void
    {
    }

    public function addFile(string $source_path, string $entry_path): void
    {
    }

    public function addString(string $entry_path, string $contents): void
    {
    }

    public function close(): void
    {
    }
}
