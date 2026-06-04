<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackFileCollector.php';

final class RollbackFileCollectorTest extends TestCase
{
    private string $root;
    private string $rollback;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-rollback-files-root-' . bin2hex(random_bytes(4));
        $this->rollback = sys_get_temp_dir() . '/ssc-rollback-files-artifact-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        mkdir($this->rollback, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        $this->removeDirectory($this->rollback);
    }

    public function testCopiesReadableWpConfigAndRecordsChecksumAndSize(): void
    {
        file_put_contents($this->root . '/wp-config.php', "<?php\n\$table_prefix = 'wp_';\n");

        $result = (new \SuperSheepCopyInstaller\RollbackFileCollector())->collect($this->root, $this->rollback);

        self::assertSame(array(), $result['warnings']);
        self::assertCount(1, $result['files']);
        self::assertSame('wp-config.php', $result['files'][0]['relative_path']);
        self::assertSame('files/wp-config.php', $result['files'][0]['rollback_path']);
        self::assertSame(hash_file('sha256', $this->root . '/wp-config.php'), $result['files'][0]['sha256']);
        self::assertSame(filesize($this->root . '/wp-config.php'), $result['files'][0]['size']);
        self::assertFileExists($this->rollback . '/files/wp-config.php');
    }

    public function testWarnsWhenWpConfigMissing(): void
    {
        $result = (new \SuperSheepCopyInstaller\RollbackFileCollector())->collect($this->root, $this->rollback);

        self::assertSame(array(), $result['files']);
        self::assertSame(array('wp-config.php is not readable.'), $result['warnings']);
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
