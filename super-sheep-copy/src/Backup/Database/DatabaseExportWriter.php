<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Database export writer creates plugin-owned backup directories.

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;
use RuntimeException;

final class DatabaseExportWriter
{
    private DatabaseExportManifestBuilder $manifest_builder;

    public function __construct(DatabaseExportManifestBuilder $manifest_builder)
    {
        $this->manifest_builder = $manifest_builder;
    }

    /**
     * @param TableSchema[] $schemas
     * @param array<string, ChunkPlan[]> $plans_by_table
     * @param array<string, string> $sql_by_chunk
     */
    public function write(string $working_directory, array $schemas, array $plans_by_table, array $sql_by_chunk): void
    {
        $database_directory = rtrim($working_directory, '/\\') . '/database';
        $chunks_directory = $database_directory . '/chunks';

        $this->ensureDirectory($database_directory);
        $this->ensureDirectory($chunks_directory);

        foreach ($plans_by_table as $plans) {
            foreach ($plans as $plan) {
                $file_name = $plan->fileName();
                $this->assertSafeChunkFileName($file_name);

                if (!array_key_exists($file_name, $sql_by_chunk)) {
                    throw new InvalidArgumentException('Missing SQL for database chunk: ' . esc_html($file_name));
                }

                $this->writeFile($chunks_directory . '/' . $file_name, $sql_by_chunk[$file_name]);
            }
        }

        $manifest = $this->manifest_builder->build($schemas, $plans_by_table);
        $this->writeFile(
            $database_directory . '/tables.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . esc_html($directory));
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write file: ' . esc_html($path));
        }
    }

    private function assertSafeChunkFileName(string $file_name): void
    {
        if (
            $file_name === ''
            || strpos($file_name, "\0") !== false
            || strpos($file_name, '/') !== false
            || strpos($file_name, '\\') !== false
            || strpos($file_name, '..') !== false
            || substr($file_name, -4) !== '.sql'
        ) {
            throw new InvalidArgumentException('Unsafe database chunk file name: ' . esc_html($file_name));
        }
    }
}
