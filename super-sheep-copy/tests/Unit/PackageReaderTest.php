<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PharData;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Shared\Archive\DirectoryPackageReader;
use SuperSheepCopy\Shared\Archive\PackageReaderFactory;
use SuperSheepCopy\Shared\Archive\TarGzPackageReader;
use SuperSheepCopy\Shared\Archive\ZipPackageReader;
use ZipArchive;

final class PackageReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-package-reader-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDirectoryReaderListsReadsAndCopiesEntries(): void
    {
        $package = $this->createDirectoryPackage(array(
            'manifest.json' => '{"project":"Super Sheep Copy"}',
            'files/a.txt' => 'body',
        ));

        $reader = new DirectoryPackageReader($package);

        self::assertContains('manifest.json', $reader->entries());
        self::assertContains('files/a.txt', $reader->entries());
        self::assertSame('body', $reader->read('files/a.txt'));
        self::assertTrue($reader->copyToFile('files/a.txt', $this->root . '/copied.txt'));
        self::assertSame('body', file_get_contents($this->root . '/copied.txt'));
        self::assertNull($reader->read('../wp-config.php'));
    }

    public function testReadersHashSafeEntries(): void
    {
        $directory_reader = new DirectoryPackageReader($this->createDirectoryPackage(array('files/a.txt' => 'body')));
        self::assertSame(hash('sha256', 'body'), $directory_reader->sha256('files/a.txt'));
        self::assertNull($directory_reader->sha256('../wp-config.php'));

        if (class_exists(ZipArchive::class)) {
            $zip_reader = new ZipPackageReader($this->createZipPackage(array('files/a.txt' => 'body')));
            self::assertSame(hash('sha256', 'body'), $zip_reader->sha256('files/a.txt'));
            self::assertNull($zip_reader->sha256('../wp-config.php'));
        }

        if (class_exists(PharData::class)) {
            $tar_reader = new TarGzPackageReader($this->createTarGzPackage(array('files/a.txt' => 'body')));
            self::assertSame(hash('sha256', 'body'), $tar_reader->sha256('files/a.txt'));
            self::assertNull($tar_reader->sha256('../wp-config.php'));
        }
    }

    public function testZipReaderListsReadsAndCopiesEntries(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $package = $this->createZipPackage(array(
            'manifest.json' => '{"project":"Super Sheep Copy"}',
            'files/a.txt' => 'body',
        ));

        $reader = new ZipPackageReader($package);

        self::assertContains('manifest.json', $reader->entries());
        self::assertContains('files/a.txt', $reader->entries());
        self::assertSame('body', $reader->read('files/a.txt'));
        self::assertTrue($reader->copyToFile('files/a.txt', $this->root . '/zip-copied.txt'));
        self::assertSame('body', file_get_contents($this->root . '/zip-copied.txt'));
    }

    public function testTarGzReaderListsReadsAndCopiesEntries(): void
    {
        if (!class_exists(PharData::class)) {
            self::markTestSkipped('PharData is not available.');
        }

        $package = $this->createTarGzPackage(array(
            'manifest.json' => '{"project":"Super Sheep Copy"}',
            'files/a.txt' => 'body',
        ));

        $reader = new TarGzPackageReader($package);

        self::assertContains('manifest.json', $reader->entries());
        self::assertContains('files/a.txt', $reader->entries());
        self::assertSame('body', $reader->read('files/a.txt'));
        self::assertTrue($reader->copyToFile('files/a.txt', $this->root . '/tar-copied.txt'));
        self::assertSame('body', file_get_contents($this->root . '/tar-copied.txt'));
    }

    public function testFactorySelectsReaderByPackagePath(): void
    {
        $directory = $this->createDirectoryPackage(array('manifest.json' => '{}'));
        self::assertInstanceOf(DirectoryPackageReader::class, (new PackageReaderFactory())->create($directory));

        if (class_exists(ZipArchive::class)) {
            self::assertInstanceOf(ZipPackageReader::class, (new PackageReaderFactory())->create($this->createZipPackage(array('manifest.json' => '{}'))));
        }

        if (class_exists(PharData::class)) {
            self::assertInstanceOf(TarGzPackageReader::class, (new PackageReaderFactory())->create($this->createTarGzPackage(array('manifest.json' => '{}'))));
        }
    }

    /**
     * @param array<string,string> $entries
     */
    private function createDirectoryPackage(array $entries): string
    {
        $directory = $this->root . '/package';
        foreach ($entries as $name => $contents) {
            $path = $directory . '/' . $name;
            mkdir(dirname($path), 0777, true);
            file_put_contents($path, $contents);
        }

        return $directory;
    }

    /**
     * @param array<string,string> $entries
     */
    private function createZipPackage(array $entries): string
    {
        $archive = $this->root . '/package.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $archive;
    }

    /**
     * @param array<string,string> $entries
     */
    private function createTarGzPackage(array $entries): string
    {
        $tar_path = $this->root . '/package.tar';
        $archive = $this->root . '/package.tar.gz';
        $tar = new PharData($tar_path);
        foreach ($entries as $name => $contents) {
            $file = $this->root . '/source-' . md5($name);
            file_put_contents($file, $contents);
            $tar->addFile($file, $name);
        }
        $tar->compress(\Phar::GZ);
        unset($tar);
        unlink($tar_path);

        return $archive;
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
