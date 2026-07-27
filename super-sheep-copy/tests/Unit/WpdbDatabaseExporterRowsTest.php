<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\ChunkPlan;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClientInterface;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;

final class WpdbDatabaseExporterRowsTest extends TestCase
{
    public function testBuildsPrimaryKeyQueryForFirstChunk(): void
    {
        $client = new RowsFakeClient();
        $exporter = new WpdbDatabaseExporter($client, new TableSelector());
        $plan = new ChunkPlan('wp_posts', 'wp_posts.part001.sql', ChunkPlan::STRATEGY_PRIMARY_KEY, 'ID', null, 100, null, 1);

        self::assertSame('SELECT * FROM `wp_posts` ORDER BY `ID` ASC LIMIT 100', $exporter->buildChunkQuery($plan));
        self::assertSame(array('SELECT * FROM `wp_posts` ORDER BY `ID` ASC LIMIT %d', array(100)), $client->prepared[0]);
    }

    public function testBuildsPrimaryKeyQueryAfterLastSeenId(): void
    {
        $client = new RowsFakeClient();
        $exporter = new WpdbDatabaseExporter($client, new TableSelector());
        $plan = new ChunkPlan('wp_posts', 'wp_posts.part002.sql', ChunkPlan::STRATEGY_PRIMARY_KEY, 'ID', 100, 100, null, 2);

        self::assertSame('SELECT * FROM `wp_posts` WHERE `ID` > 100 ORDER BY `ID` ASC LIMIT 100', $exporter->buildChunkQuery($plan));
        self::assertSame(array('SELECT * FROM `wp_posts` WHERE `ID` > %d ORDER BY `ID` ASC LIMIT %d', array(100, 100)), $client->prepared[0]);
    }

    public function testBuildsOffsetQuery(): void
    {
        $client = new RowsFakeClient();
        $exporter = new WpdbDatabaseExporter($client, new TableSelector());
        $plan = new ChunkPlan('wp_options', 'wp_options.part003.sql', ChunkPlan::STRATEGY_OFFSET, null, null, 50, 100, 3);

        self::assertSame('SELECT * FROM `wp_options` LIMIT 50 OFFSET 100', $exporter->buildChunkQuery($plan));
        self::assertSame(array('SELECT * FROM `wp_options` LIMIT %d OFFSET %d', array(50, 100)), $client->prepared[0]);
    }

    public function testFetchesRowsIntoTableRows(): void
    {
        $client = new RowsFakeClient();
        $exporter = new WpdbDatabaseExporter($client, new TableSelector());
        $plan = new ChunkPlan('wp_posts', 'wp_posts.part001.sql', ChunkPlan::STRATEGY_PRIMARY_KEY, 'ID', null, 100, null, 1);

        $rows = $exporter->fetchRows($plan, array('ID', 'post_title'));

        self::assertSame('wp_posts', $rows->tableName());
        self::assertSame(array('ID', 'post_title'), $rows->columns());
        self::assertSame(array(array('ID' => 1, 'post_title' => 'Hello')), $rows->rows());
    }

    public function testBuildsQueryWithHyphenatedPrimaryKey(): void
    {
        $client = new RowsFakeClient();
        $exporter = new WpdbDatabaseExporter($client, new TableSelector());
        $plan = new ChunkPlan('wp_posts', 'wp_posts.part001.sql', ChunkPlan::STRATEGY_PRIMARY_KEY, 'play-large', null, 100, null, 1);

        self::assertSame(
            'SELECT * FROM `wp_posts` ORDER BY `play-large` ASC LIMIT 100',
            $exporter->buildChunkQuery($plan)
        );
    }

    public function testFetchesRowsWithHyphenatedColumn(): void
    {
        $client = new RowsFakeClient(array(array('play-large' => 1)));
        $exporter = new WpdbDatabaseExporter($client, new TableSelector());
        $plan = new ChunkPlan('wp_posts', 'wp_posts.part001.sql', ChunkPlan::STRATEGY_OFFSET, null, null, 100, 0, 1);

        $rows = $exporter->fetchRows($plan, array('play-large'));

        self::assertSame(array('play-large'), $rows->columns());
        self::assertSame(array(array('play-large' => 1)), $rows->rows());
    }

    public function testRejectsUnsafeColumnIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier: ID;DROP');

        $plan = new ChunkPlan('wp_posts', 'wp_posts.part001.sql', ChunkPlan::STRATEGY_PRIMARY_KEY, 'ID;DROP', null, 100, null, 1);
        (new WpdbDatabaseExporter(new RowsFakeClient(), new TableSelector()))->buildChunkQuery($plan);
    }
}

final class RowsFakeClient implements WpdbClientInterface
{
    /** @var array<int, array{0:string,1:array<int,mixed>}> */
    public array $prepared = array();

    /** @var array<int, array<string, mixed>>|null */
    private ?array $rows;

    /**
     * @param array<int, array<string, mixed>>|null $rows
     */
    public function __construct(?array $rows = null)
    {
        $this->rows = $rows;
    }

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
        return array();
    }

    public function getRows(string $sql): array
    {
        return $this->rows ?? array(array('ID' => 1, 'post_title' => 'Hello'));
    }

    public function prepare(string $sql, array $args): string
    {
        $this->prepared[] = array($sql, $args);
        foreach ($args as $arg) {
            $sql = preg_replace('/%d/', (string) $arg, $sql, 1);
        }

        return $sql;
    }
}
