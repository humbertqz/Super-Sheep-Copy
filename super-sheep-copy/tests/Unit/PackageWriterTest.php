<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PharData;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Package\DirectoryPackageWriter;
use SuperSheepCopy\Backup\Package\TarGzPackageWriter;
use SuperSheepCopy\Backup\Package\ZipPackageWriter;
use ZipArchive;

final class PackageWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-package-writer-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/source.txt', 'file body');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDirectoryWriterCreatesPackageLayout(): void
    {
        $writer = new DirectoryPackageWriter();
        $writer->open($this->root . '/package');
        $writer->addString('manifest.json', '{"project":"Super Sheep Copy"}');
        $writer->addFile($this->root . '/source.txt', 'files/source.txt');
        $writer->close();

        self::assertSame('{"project":"Super Sheep Copy"}', file_get_contents($this->root . '/package/manifest.json'));
        self::assertSame('file body', file_get_contents($this->root . '/package/files/source.txt'));
    }

    public function testZipWriterCreatesPackageEntries(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $writer = new ZipPackageWriter();
        $writer->open($this->root . '/package.zip');
        $writer->addString('manifest.json', '{"project":"Super Sheep Copy"}');
        $writer->addFile($this->root . '/source.txt', 'files/source.txt');
        $writer->close();

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->root . '/package.zip'));
        self::assertSame('{"project":"Super Sheep Copy"}', $zip->getFromName('manifest.json'));
        self::assertSame('file body', $zip->getFromName('files/source.txt'));
        $zip->close();
    }

    public function testTarGzWriterCreatesPackageEntries(): void
    {
        if (!class_exists(PharData::class)) {
            self::markTestSkipped('PharData is not available.');
        }

        $writer = new TarGzPackageWriter();
        $writer->open($this->root . '/package.tar.gz');
        $writer->addString('manifest.json', '{"project":"Super Sheep Copy"}');
        $writer->addFile($this->root . '/source.txt', 'files/source.txt');
        $writer->close();

        $tar = new PharData($this->root . '/package.tar.gz');
        self::assertSame('{"project":"Super Sheep Copy"}', file_get_contents('phar://' . $this->root . '/package.tar.gz/manifest.json'));
        self::assertSame('file body', file_get_contents('phar://' . $this->root . '/package.tar.gz/files/source.txt'));
        unset($tar);
    }

    public function testWritersRejectUnsafeEntryPaths(): void
    {
        $writer = new DirectoryPackageWriter();
        $writer->open($this->root . '/package');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsafe package entry path');

        $writer->addString('../wp-config.php', 'bad');
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
