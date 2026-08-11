<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

final class SqlDumpFormatter
{
    private const DEFAULT_MAX_INSERT_BYTES = 8388608;

    private int $max_insert_bytes;

    public function __construct(int $max_insert_bytes = self::DEFAULT_MAX_INSERT_BYTES)
    {
        if ($max_insert_bytes < 1) {
            throw new \InvalidArgumentException('Maximum INSERT statement size must be greater than zero.');
        }

        $this->max_insert_bytes = $max_insert_bytes;
    }

    public function formatSchema(TableSchema $schema): string
    {
        return sprintf("DROP TABLE IF EXISTS `%s`;\n%s;\n", $this->escapeIdentifier($schema->name()), rtrim($schema->createSql(), ";\n\r\t "));
    }

    public function formatRows(TableRows $rows): string
    {
        if ($rows->rows() === array()) {
            return '';
        }

        $columns = array_map(function (string $column): string {
            return '`' . $this->escapeIdentifier($column) . '`';
        }, $rows->columns());

        $prefix = sprintf(
            "INSERT INTO `%s` (%s) VALUES\n",
            $this->escapeIdentifier($rows->tableName()),
            implode(', ', $columns)
        );
        $statements = array();
        $values = array();
        foreach ($rows->rows() as $row) {
            $formatted = array();
            foreach ($rows->columns() as $column) {
                $formatted[] = $this->formatValue($row[$column]);
            }
            $value = '(' . implode(', ', $formatted) . ')';
            $candidate_values = $values;
            $candidate_values[] = $value;
            $candidate = $this->formatInsertStatement($prefix, $candidate_values);

            if (strlen($candidate) <= $this->max_insert_bytes) {
                $values = $candidate_values;
                continue;
            }

            if ($values === array()) {
                throw new \InvalidArgumentException('Single row for table ' . $rows->tableName() . ' exceeds the maximum INSERT statement size.');
            }

            $statements[] = $this->formatInsertStatement($prefix, $values);
            $values = array($value);
            if (strlen($this->formatInsertStatement($prefix, $values)) > $this->max_insert_bytes) {
                throw new \InvalidArgumentException('Single row for table ' . $rows->tableName() . ' exceeds the maximum INSERT statement size.');
            }
        }

        if ($values !== array()) {
            $statements[] = $this->formatInsertStatement($prefix, $values);
        }

        return implode('', $statements);
    }

    /**
     * @param string[] $values
     */
    private function formatInsertStatement(string $prefix, array $values): string
    {
        return $prefix . implode(",\n", $values) . ";\n";
    }

    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * @param mixed $value
     */
    private function formatValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value) . "'";
    }
}
