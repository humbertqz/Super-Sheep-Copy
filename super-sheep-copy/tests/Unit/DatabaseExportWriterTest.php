<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\ChunkPlan;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\DatabaseExportWriter;
use SuperSheepCopy\Backup\Database\TableSchema;

final class DatabaseExportWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-db-writer-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testWritesChunksAndTablesManifest(): void
    {
        $posts = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 2, 'utf8mb4', 'utf8mb4_unicode_ci');
        $options = new TableSchema('wp_options', 'CREATE TABLE `wp_options` (`option_id` bigint)', 'option_id', 1, 'utf8mb4', 'utf8mb4_unicode_ci');
        $planner = new ChunkPlanner();
        $posts_plan = $planner->plan($posts, 100, 1, null);
        $options_plan = $planner->plan($options, 100, 1, null);

        $writer = new DatabaseExportWriter(new DatabaseExportManifestBuilder());
        $writer->write(
            $this->root,
            array($posts, $options),
            array(
                'wp_posts' => array($posts_plan),
                'wp_options' => array($options_plan),
            ),
            array(
                'wp_posts.part001.sql' => "DROP TABLE IF EXISTS `wp_posts`;\n",
                'wp_options.part001.sql' => "DROP TABLE IF EXISTS `wp_options`;\n",
            )
        );

        self::assertSame("DROP TABLE IF EXISTS `wp_posts`;\n", file_get_contents($this->root . '/database/chunks/wp_posts.part001.sql'));
        self::assertSame("DROP TABLE IF EXISTS `wp_options`;\n", file_get_contents($this->root . '/database/chunks/wp_options.part001.sql'));

        $manifest = json_decode((string) file_get_contents($this->root . '/database/tables.json'), true);
        self::assertSame('1', $manifest['format_version']);
        self::assertSame(2, $manifest['table_count']);
        self::assertSame(array('wp_posts.part001.sql'), $manifest['tables'][0]['chunks']);
    }

    public function testRejectsUnsafeChunkFileName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe database chunk file name: ../escape.sql');

        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 1, null, null);
        $writer = new DatabaseExportWriter(new DatabaseExportManifestBuilder());
        $writer->write(
            $this->root,
            array($schema),
            array('wp_posts' => array(new ChunkPlan('wp_posts', '../escape.sql', 'primary_key', 'ID', null, 100, null, 1))),
            array('../escape.sql' => 'SELECT 1;')
        );
    }

    public function testRejectsMissingSqlForPlannedChunk(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing SQL for database chunk: wp_posts.part001.sql');

        $schema = new TableSchema('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)', 'ID', 1, null, null);
        $plan = (new ChunkPlanner())->plan($schema, 100, 1, null);
        (new DatabaseExportWriter(new DatabaseExportManifestBuilder()))->write(
            $this->root,
            array($schema),
            array('wp_posts' => array($plan)),
            array()
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
