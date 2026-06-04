<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClientInterface;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;

final class WpdbDatabaseExporterColumnsTest extends TestCase
{
    public function testGetsColumnsFromClient(): void
    {
        $exporter = new WpdbDatabaseExporter(new ColumnsFakeClient(), new TableSelector());

        self::assertSame(array('ID', 'post_title'), $exporter->getColumns('wp_posts'));
    }

    public function testRejectsUnsafeTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier: wp_posts;DROP');

        (new WpdbDatabaseExporter(new ColumnsFakeClient(), new TableSelector()))->getColumns('wp_posts;DROP');
    }
}

final class ColumnsFakeClient implements WpdbClientInterface
{
    public function getTables(): array
    {
        return array();
    }

    public function getCreateTableSql(string $table): string
    {
        return '';
    }

    public function getPrimaryKey(string $table): ?string
    {
        return null;
    }

    public function getRowCount(string $table): int
    {
        return 0;
    }

    public function getTableStatus(string $table): array
    {
        return array();
    }

    public function getColumns(string $table): array
    {
        return array('ID', 'post_title');
    }

    public function getRows(string $sql): array
    {
        return array();
    }

    public function prepare(string $sql, array $args): string
    {
        return $sql;
    }
}
