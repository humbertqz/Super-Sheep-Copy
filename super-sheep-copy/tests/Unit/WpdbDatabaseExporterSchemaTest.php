<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClientInterface;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;

final class WpdbDatabaseExporterSchemaTest extends TestCase
{
    public function testDiscoversSelectedTables(): void
    {
        $exporter = new WpdbDatabaseExporter(new SchemaFakeClient(), new TableSelector());

        self::assertSame(
            array('wp_posts', 'wp_options'),
            $exporter->selectTables('wp_', TableSelector::MODE_PREFIXED)
        );
    }

    public function testBuildsTableSchemaFromClientMetadata(): void
    {
        $exporter = new WpdbDatabaseExporter(new SchemaFakeClient(), new TableSelector());

        $schema = $exporter->getSchema('wp_posts');

        self::assertSame('wp_posts', $schema->name());
        self::assertSame('CREATE TABLE `wp_posts` (`ID` bigint)', $schema->createSql());
        self::assertSame('ID', $schema->primaryKey());
        self::assertSame(12, $schema->rowCount());
        self::assertSame('utf8mb4', $schema->charset());
        self::assertSame('utf8mb4_unicode_ci', $schema->collation());
    }

    public function testRejectsUnsafeTableIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier: wp_posts;DROP');

        (new WpdbDatabaseExporter(new SchemaFakeClient(), new TableSelector()))->getSchema('wp_posts;DROP');
    }

    public function testThrowsWhenCreateSqlIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Create table SQL was not found for table: wp_missing');

        (new WpdbDatabaseExporter(new SchemaFakeClient(), new TableSelector()))->getSchema('wp_missing');
    }
}

final class SchemaFakeClient implements WpdbClientInterface
{
    public function getTables(): array
    {
        return array('wp_posts', 'wp_options', 'other_table');
    }

    public function getCreateTableSql(string $table): string
    {
        return $table === 'wp_missing' ? '' : 'CREATE TABLE `' . $table . '` (`ID` bigint)';
    }

    public function getPrimaryKey(string $table): ?string
    {
        return $table === 'wp_options' ? null : 'ID';
    }

    public function getRowCount(string $table): int
    {
        return 12;
    }

    public function getTableStatus(string $table): array
    {
        return array('Charset' => 'utf8mb4', 'Collation' => 'utf8mb4_unicode_ci');
    }

    public function getColumns(string $table): array
    {
        return array();
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
