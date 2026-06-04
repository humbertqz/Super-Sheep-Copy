<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;

final class TableRows
{
    private string $table_name;
    /** @var string[] */
    private array $columns;
    /** @var array<int, array<string, mixed>> */
    private array $rows;

    /**
     * @param string[] $columns
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(string $table_name, array $columns, array $rows)
    {
        if ($table_name === '') {
            throw new InvalidArgumentException('Table name is required.');
        }

        if ($columns === array()) {
            throw new InvalidArgumentException('At least one column is required.');
        }

        foreach ($columns as $column) {
            if (!is_string($column) || $column === '') {
                throw new InvalidArgumentException('Column names must be non-empty strings.');
            }
        }

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if (!array_key_exists($column, $row)) {
                    throw new InvalidArgumentException('Row is missing expected column: ' . esc_html($column));
                }
            }
        }

        $this->table_name = $table_name;
        $this->columns = array_values($columns);
        $this->rows = array_values($rows);
    }

    public function tableName(): string
    {
        return $this->table_name;
    }

    /**
     * @return string[]
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }
}
