<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;

final class TableSchema
{
    private string $name;
    private string $create_sql;
    private ?string $primary_key;
    private int $row_count;
    private ?string $charset;
    private ?string $collation;

    public function __construct(
        string $name,
        string $create_sql,
        ?string $primary_key,
        int $row_count,
        ?string $charset,
        ?string $collation
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Table name is required.');
        }

        if ($create_sql === '') {
            throw new InvalidArgumentException('Create table SQL is required.');
        }

        if ($row_count < 0) {
            throw new InvalidArgumentException('Row count cannot be negative.');
        }

        $this->name = $name;
        $this->create_sql = $create_sql;
        $this->primary_key = $primary_key;
        $this->row_count = $row_count;
        $this->charset = $charset;
        $this->collation = $collation;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function createSql(): string
    {
        return $this->create_sql;
    }

    public function primaryKey(): ?string
    {
        return $this->primary_key;
    }

    public function rowCount(): int
    {
        return $this->row_count;
    }

    public function charset(): ?string
    {
        return $this->charset;
    }

    public function collation(): ?string
    {
        return $this->collation;
    }
}
