<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\ArchiveWriter;
use SuperSheepCopy\Backup\Manifest;
use SuperSheepCopy\Backup\ScannedFile;
use ZipArchive;

final class ArchiveWriterTest extends TestCase
{
    public function testWritesBackupPackageShape(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $root = sys_get_temp_dir() . '/ssc-archive-' . bin2hex(random_bytes(4));
        mkdir($root . '/source/wp-content/uploads', 0777, true);
        mkdir($root . '/database/wp_posts', 0777, true);
        mkdir($root . '/output', 0777, true);
        file_put_contents($root . '/source/wp-content/uploads/file.txt', 'backup file');
        file_put_contents($root . '/database/tables.json', '{"tables":[]}');
        file_put_contents($root . '/database/wp_posts/chunk-000001.sql', 'INSERT INTO wp_posts VALUES (1);');

        $archive = $root . '/output/backup.zip';
        $writer = new ArchiveWriter();
        $writer->write(
            $archive,
            new Manifest(array('project' => 'Super Sheep Copy')),
            array(new ScannedFile($root . '/source/wp-content/uploads/file.txt', 'wp-content/uploads/file.txt', 11, false)),
            array(
                new ScannedFile($root . '/database/tables.json', 'tables.json', 13, false),
                new ScannedFile($root . '/database/wp_posts/chunk-000001.sql', 'wp_posts/chunk-000001.sql', 30, false),
            ),
            array(
                'files/wp-content/uploads/file.txt' => 'hash123',
                'database/tables.json' => 'hash456',
                'database/wp_posts/chunk-000001.sql' => 'hash789',
            ),
            'backup started'
        );

        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive));
        self::assertSame('backup file', $zip->getFromName('files/wp-content/uploads/file.txt'));
        self::assertSame('{"tables":[]}', $zip->getFromName('database/tables.json'));
        self::assertSame('INSERT INTO wp_posts VALUES (1);', $zip->getFromName('database/wp_posts/chunk-000001.sql'));
        self::assertNotFalse($zip->getFromName('manifest.json'));
        self::assertNotFalse($zip->getFromName('checksums.json'));
        self::assertSame('backup started', $zip->getFromName('logs/backup.log'));
        $zip->close();

        $this->removeDirectory($root);
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
