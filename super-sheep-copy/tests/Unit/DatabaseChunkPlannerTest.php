<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\ChunkPlan;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\TableSchema;

final class DatabaseChunkPlannerTest extends TestCase
{
    public function testPlansPrimaryKeyPagination(): void
    {
        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 250, null, null);
        $plan = (new ChunkPlanner())->plan($schema, 100, 3, 200);

        self::assertSame('wp_posts', $plan->tableName());
        self::assertSame('wp_posts.part003.sql', $plan->fileName());
        self::assertSame(ChunkPlan::STRATEGY_PRIMARY_KEY, $plan->strategy());
        self::assertSame('ID', $plan->primaryKey());
        self::assertSame(200, $plan->lastSeenId());
        self::assertSame(100, $plan->limit());
        self::assertNull($plan->offset());
    }

    public function testPlansOffsetPaginationWhenPrimaryKeyIsMissing(): void
    {
        $schema = new TableSchema('wp_options', 'CREATE TABLE `wp_options` (`option_name` varchar(191))', null, 250, null, null);
        $plan = (new ChunkPlanner())->plan($schema, 100, 3, null);

        self::assertSame('wp_options.part003.sql', $plan->fileName());
        self::assertSame(ChunkPlan::STRATEGY_OFFSET, $plan->strategy());
        self::assertNull($plan->primaryKey());
        self::assertNull($plan->lastSeenId());
        self::assertSame(100, $plan->limit());
        self::assertSame(200, $plan->offset());
    }

    public function testRejectsInvalidChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be greater than zero.');

        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 1, null, null);
        (new ChunkPlanner())->plan($schema, 0, 1, null);
    }

    public function testRejectsInvalidChunkNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk number must be greater than zero.');

        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 1, null, null);
        (new ChunkPlanner())->plan($schema, 100, 0, null);
    }
}
