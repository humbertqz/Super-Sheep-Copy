<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

interface WpdbClientInterface
{
    /**
     * @return string[]
     */
    public function getTables(): array;

    public function getCreateTableSql(string $table): string;

    public function getPrimaryKey(string $table): ?string;

    public function getRowCount(string $table): int;

    /**
     * @return array<string, mixed>
     */
    public function getTableStatus(string $table): array;

    /**
     * @return string[]
     */
    public function getColumns(string $table): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRows(string $sql): array;

    /**
     * @param array<int, mixed> $args
     */
    public function prepare(string $sql, array $args): string;
}
