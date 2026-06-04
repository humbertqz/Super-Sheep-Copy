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
}
