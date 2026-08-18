<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

final class ChunkPlan
{
    public const STRATEGY_PRIMARY_KEY = 'primary_key';
    public const STRATEGY_OFFSET = 'offset';

    private string $table_name;
    private string $file_name;
    private string $strategy;
    private ?string $primary_key;
    private ?int $last_seen_id;
    private int $limit;
    private ?int $offset;
    private int $chunk_number;
    private ?int $upper_bound;

    public function __construct(
        string $table_name,
        string $file_name,
        string $strategy,
        ?string $primary_key,
        ?int $last_seen_id,
        int $limit,
        ?int $offset,
        int $chunk_number,
        ?int $upper_bound = null
    ) {
        $this->table_name = $table_name;
        $this->file_name = $file_name;
        $this->strategy = $strategy;
        $this->primary_key = $primary_key;
        $this->last_seen_id = $last_seen_id;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->chunk_number = $chunk_number;
        $this->upper_bound = $upper_bound;
    }

    public function tableName(): string
    {
        return $this->table_name;
    }

    public function fileName(): string
    {
        return $this->file_name;
    }

    public function strategy(): string
    {
        return $this->strategy;
    }

    public function primaryKey(): ?string
    {
        return $this->primary_key;
    }

    public function lastSeenId(): ?int
    {
        return $this->last_seen_id;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function offset(): ?int
    {
        return $this->offset;
    }

    public function chunkNumber(): int
    {
        return $this->chunk_number;
    }

    public function upperBound(): ?int
    {
        return $this->upper_bound;
    }
}
