<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\TableSchema;

final class DatabaseExportManifestBuilderTest extends TestCase
{
    public function testBuildsTablesManifestMetadata(): void
    {
        $posts = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 250, 'utf8mb4', 'utf8mb4_unicode_ci');
        $options = new TableSchema('wp_options', 'CREATE TABLE `wp_options` (`option_name` varchar(191))', null, 10, 'utf8mb4', 'utf8mb4_unicode_ci');
        $planner = new ChunkPlanner();

        $manifest = (new DatabaseExportManifestBuilder())->build(
            array($posts, $options),
            array(
                'wp_posts' => array(
                    $planner->plan($posts, 100, 1, null),
                    $planner->plan($posts, 100, 2, 100),
                ),
                'wp_options' => array(
                    $planner->plan($options, 100, 1, null),
                ),
            )
        );

        self::assertSame('1', $manifest['format_version']);
        self::assertSame(2, $manifest['table_count']);
        self::assertSame('wp_posts', $manifest['tables'][0]['name']);
        self::assertSame('ID', $manifest['tables'][0]['primary_key']);
        self::assertSame(250, $manifest['tables'][0]['row_count']);
        self::assertSame(array('wp_posts.part001.sql', 'wp_posts.part002.sql'), $manifest['tables'][0]['chunks']);
        self::assertSame('primary_key', $manifest['tables'][0]['pagination_strategy']);
        self::assertSame('offset', $manifest['tables'][1]['pagination_strategy']);
    }
}
