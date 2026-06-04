<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackageReaderInterface.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackagePathGuard.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DirectoryPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/ZipPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/TarGzPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackageReaderFactory.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseImportManifestReader.php';

final class DatabaseImportManifestReaderTest extends TestCase
{
    private string $archive;

    protected function setUp(): void
    {
        $this->archive = sys_get_temp_dir() . '/ssc-import-manifest-' . bin2hex(random_bytes(4)) . '.zip';
    }

    protected function tearDown(): void
    {
        if (is_file($this->archive)) {
            unlink($this->archive);
        }
        if (is_dir($this->archive)) {
            $this->removeDirectory($this->archive);
        }
    }

    public function testReadsValidDatabaseManifestAndChunks(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeArchive(array(
            'format_version' => '1',
            'table_count' => 1,
            'tables' => array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
        ), array('wp_posts.part001.sql' => 'CREATE TABLE `wp_posts` (`ID` bigint);'));

        $result = (new \SuperSheepCopyInstaller\DatabaseImportManifestReader())->read($this->archive);

        self::assertTrue($result['valid']);
        self::assertSame(array(), $result['warnings']);
        self::assertSame('wp_posts', $result['tables'][0]['name']);
        self::assertSame(array('wp_posts.part001.sql'), $result['tables'][0]['chunks']);
        self::assertSame('CREATE TABLE `wp_posts` (`ID` bigint);', $result['chunks']['wp_posts.part001.sql']);
    }

    public function testReadsValidDirectoryDatabaseManifestAndChunks(): void
    {
        $this->archive = sys_get_temp_dir() . '/ssc-import-manifest-dir-' . bin2hex(random_bytes(4));
        $this->writeDirectoryPackage(array(
            'format_version' => '1',
            'table_count' => 1,
            'tables' => array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
        ), array('wp_posts.part001.sql' => 'CREATE TABLE `wp_posts` (`ID` bigint);'));

        $result = (new \SuperSheepCopyInstaller\DatabaseImportManifestReader())->read($this->archive);

        self::assertTrue($result['valid']);
        self::assertSame(array(), $result['warnings']);
        self::assertSame('wp_posts', $result['tables'][0]['name']);
        self::assertSame('CREATE TABLE `wp_posts` (`ID` bigint);', $result['chunks']['wp_posts.part001.sql']);
    }

    public function testRejectsUnsafeChunkName(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeArchive(array(
            'format_version' => '1',
            'table_count' => 1,
            'tables' => array(array('name' => 'wp_posts', 'chunks' => array('../escape.sql'))),
        ), array());

        $result = (new \SuperSheepCopyInstaller\DatabaseImportManifestReader())->read($this->archive);

        self::assertFalse($result['valid']);
        self::assertSame(array('Unsafe database chunk file name: ../escape.sql'), $result['warnings']);
    }

    public function testRejectsMissingChunkEntry(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeArchive(array(
            'format_version' => '1',
            'table_count' => 1,
            'tables' => array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
        ), array());

        $result = (new \SuperSheepCopyInstaller\DatabaseImportManifestReader())->read($this->archive);

        self::assertFalse($result['valid']);
        self::assertSame(array('Missing database chunk entry: database/chunks/wp_posts.part001.sql'), $result['warnings']);
    }

    public function testRejectsTableWithoutChunks(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeArchive(array(
            'format_version' => '1',
            'table_count' => 1,
            'tables' => array(array('name' => 'wp_actionscheduler_actions', 'chunks' => array())),
        ), array());

        $result = (new \SuperSheepCopyInstaller\DatabaseImportManifestReader())->read($this->archive);

        self::assertFalse($result['valid']);
        self::assertSame(array('Database table has no chunks: wp_actionscheduler_actions'), $result['warnings']);
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,string> $chunks
     */
    private function writeArchive(array $manifest, array $chunks): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('database/tables.json', (string) json_encode($manifest));
        foreach ($chunks as $file => $sql) {
            $zip->addFromString('database/chunks/' . $file, $sql);
        }
        $zip->close();
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,string> $chunks
     */
    private function writeDirectoryPackage(array $manifest, array $chunks): void
    {
        mkdir($this->archive . '/database/chunks', 0777, true);
        file_put_contents($this->archive . '/database/tables.json', (string) json_encode($manifest));
        foreach ($chunks as $file => $sql) {
            file_put_contents($this->archive . '/database/chunks/' . $file, $sql);
        }
    }

    private function removeDirectory(string $path): void
    {
        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $item) {
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
