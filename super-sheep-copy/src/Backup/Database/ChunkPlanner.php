<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;

final class ChunkPlanner
{
    public function plan(TableSchema $schema, int $chunk_size, int $chunk_number, ?int $last_seen_id): ChunkPlan
    {
        if ($chunk_size < 1) {
            throw new InvalidArgumentException('Chunk size must be greater than zero.');
        }

        if ($chunk_number < 1) {
            throw new InvalidArgumentException('Chunk number must be greater than zero.');
        }

        $file_name = sprintf('%s.part%03d.sql', $schema->name(), $chunk_number);

        if ($schema->primaryKey() !== null && $schema->primaryKey() !== '') {
            return new ChunkPlan(
                $schema->name(),
                $file_name,
                ChunkPlan::STRATEGY_PRIMARY_KEY,
                $schema->primaryKey(),
                $last_seen_id,
                $chunk_size,
                null,
                $chunk_number
            );
        }

        return new ChunkPlan(
            $schema->name(),
            $file_name,
            ChunkPlan::STRATEGY_OFFSET,
            null,
            null,
            $chunk_size,
            ($chunk_number - 1) * $chunk_size,
            $chunk_number
        );
    }
}
