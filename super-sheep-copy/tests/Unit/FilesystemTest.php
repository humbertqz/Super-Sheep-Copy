<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Support\Filesystem;

final class FilesystemTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-filesystem-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $GLOBALS['ssc_test_deleted_files'] = array();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDeleteFileUsesWordPressDeleteFunction(): void
    {
        $path = $this->root . '/delete.txt';
        file_put_contents($path, 'delete me');

        self::assertTrue(Filesystem::deleteFile($path));
        self::assertFileDoesNotExist($path);
        self::assertSame(array($path), $GLOBALS['ssc_test_deleted_files']);
    }

    public function testMoveRenamesFile(): void
    {
        $source = $this->root . '/source.txt';
        $target = $this->root . '/target.txt';
        file_put_contents($source, 'move me');

        self::assertTrue(Filesystem::move($source, $target));
        self::assertFileDoesNotExist($source);
        self::assertSame('move me', file_get_contents($target));
    }

    public function testRemoveDirectoryDeletesNestedFiles(): void
    {
        mkdir($this->root . '/nested/deep', 0777, true);
        file_put_contents($this->root . '/nested/deep/file.txt', 'nested');

        self::assertTrue(Filesystem::removeDirectory($this->root . '/nested'));
        self::assertDirectoryDoesNotExist($this->root . '/nested');
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
