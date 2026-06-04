<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackDatabaseDumper.php';

final class RollbackDatabaseDumperTest extends TestCase
{
    private string $rollback;

    protected function setUp(): void
    {
        $this->rollback = sys_get_temp_dir() . '/ssc-rollback-db-' . bin2hex(random_bytes(4));
        mkdir($this->rollback, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rollback);
    }

    public function testSkipsDumpWhenPrefixIsEmpty(): void
    {
        $result = (new \SuperSheepCopyInstaller\RollbackDatabaseDumper())->dump(array('table_prefix' => ''), $this->rollback);

        self::assertFalse($result['included']);
        self::assertSame('', $result['dump_path']);
        self::assertSame(0, $result['table_count']);
        self::assertSame(array('Database table prefix is empty; database rollback dump skipped.'), $result['warnings']);
        self::assertStringNotContainsString('secret', json_encode($result) ?: '');
    }

    public function testFormatsSqlValueLiterals(): void
    {
        $dumper = new \SuperSheepCopyInstaller\RollbackDatabaseDumper();

        self::assertSame('NULL', $dumper->formatValueForTest(null));
        self::assertSame("'simple'", $dumper->formatValueForTest('simple'));
        self::assertSame("'Bob\\'s'", $dumper->formatValueForTest("Bob's"));
        self::assertSame("'line\\nfeed'", $dumper->formatValueForTest("line\nfeed"));
    }

    public function testQuotesIdentifiers(): void
    {
        $dumper = new \SuperSheepCopyInstaller\RollbackDatabaseDumper();

        self::assertSame('`wp_posts`', $dumper->quoteIdentifierForTest('wp_posts'));
        self::assertSame('`odd``name`', $dumper->quoteIdentifierForTest('odd`name'));
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
