<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\SqlDumpFormatter;
use SuperSheepCopy\Backup\Database\TableRows;
use SuperSheepCopy\Backup\Database\TableSchema;

final class SqlDumpFormatterTest extends TestCase
{
    public function testFormatsSchemaSql(): void
    {
        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 1, 'utf8mb4', 'utf8mb4_unicode_ci');

        self::assertSame(
            "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (`ID` bigint);\n",
            (new SqlDumpFormatter())->formatSchema($schema)
        );
    }

    public function testFormatsInsertSqlWithEscapedValues(): void
    {
        $rows = new TableRows(
            'wp_options',
            array('option_id', 'option_name', 'option_value', 'autoload', 'enabled', 'ratio', 'missing'),
            array(
                array(
                    'option_id' => 1,
                    'option_name' => "site\\url",
                    'option_value' => "Bob's Site",
                    'autoload' => 'yes',
                    'enabled' => true,
                    'ratio' => 1.25,
                    'missing' => null,
                ),
            )
        );

        self::assertSame(
            "INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`, `enabled`, `ratio`, `missing`) VALUES\n" .
            "(1, 'site\\\\url', 'Bob\\'s Site', 'yes', 1, 1.25, NULL);\n",
            (new SqlDumpFormatter())->formatRows($rows)
        );
    }

    public function testReturnsEmptyStringForNoRows(): void
    {
        $rows = new TableRows('wp_options', array('option_id'), array());

        self::assertSame('', (new SqlDumpFormatter())->formatRows($rows));
    }

    public function testSplitsRowsIntoMultipleInsertStatementsBelowConfiguredLimit(): void
    {
        $rows = new TableRows(
            'wp_posts',
            array('ID', 'post_content'),
            array(
                array('ID' => 1, 'post_content' => str_repeat('a', 30)),
                array('ID' => 2, 'post_content' => str_repeat('b', 30)),
            )
        );

        $sql = (new SqlDumpFormatter(100))->formatRows($rows);

        self::assertSame(2, substr_count($sql, 'INSERT INTO `wp_posts`'));
        self::assertStringContainsString("(1, '" . str_repeat('a', 30) . "')", $sql);
        self::assertStringContainsString("(2, '" . str_repeat('b', 30) . "')", $sql);
        foreach (array_filter(explode(";\n", $sql)) as $statement) {
            self::assertLessThanOrEqual(100, strlen($statement . ";\n"));
        }
    }

    public function testRejectsSingleRowThatExceedsConfiguredInsertLimit(): void
    {
        $rows = new TableRows(
            'wp_posts',
            array('ID', 'post_content'),
            array(array('ID' => 1, 'post_content' => str_repeat('a', 90)))
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Single row for table wp_posts exceeds the maximum INSERT statement size.');

        (new SqlDumpFormatter(100))->formatRows($rows);
    }
}
