<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\TableRows;
use SuperSheepCopy\Backup\Database\TableSchema;

final class DatabaseValueObjectsTest extends TestCase
{
    public function testTableSchemaStoresMetadata(): void
    {
        $schema = new TableSchema(
            'wp_posts',
            'CREATE TABLE `wp_posts` (`ID` bigint unsigned NOT NULL)',
            'ID',
            125,
            'utf8mb4',
            'utf8mb4_unicode_ci'
        );

        self::assertSame('wp_posts', $schema->name());
        self::assertSame('CREATE TABLE `wp_posts` (`ID` bigint unsigned NOT NULL)', $schema->createSql());
        self::assertSame('ID', $schema->primaryKey());
        self::assertSame(125, $schema->rowCount());
        self::assertSame('utf8mb4', $schema->charset());
        self::assertSame('utf8mb4_unicode_ci', $schema->collation());
    }

    public function testTableRowsStoresOrderedColumnsAndRows(): void
    {
        $rows = new TableRows(
            'wp_options',
            array('option_id', 'option_name', 'option_value'),
            array(
                array('option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://website.com'),
                array('option_id' => 2, 'option_name' => 'home', 'option_value' => 'https://website.com'),
            )
        );

        self::assertSame('wp_options', $rows->tableName());
        self::assertSame(array('option_id', 'option_name', 'option_value'), $rows->columns());
        self::assertCount(2, $rows->rows());
    }

    public function testTableSchemaRejectsEmptyTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name is required.');

        new TableSchema('', 'CREATE TABLE `wp_posts` (`ID` bigint)', null, 0, null, null);
    }

    public function testTableRowsRejectRowsMissingExpectedColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Row is missing expected column: option_value');

        new TableRows(
            'wp_options',
            array('option_id', 'option_name', 'option_value'),
            array(array('option_id' => 1, 'option_name' => 'siteurl'))
        );
    }
}
