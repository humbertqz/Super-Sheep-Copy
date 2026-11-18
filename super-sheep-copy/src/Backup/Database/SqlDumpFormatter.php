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
        $statement_bytes = strlen($prefix) + strlen(";\n");
        foreach ($rows->rows() as $row) {
            $formatted = array();
            foreach ($rows->columns() as $column) {
                $formatted[] = $this->formatValue($row[$column]);
            }
            $value = '(' . implode(', ', $formatted) . ')';
            $value_bytes = strlen($value);

            if ($values !== array() && $statement_bytes + 2 + $value_bytes > $this->max_insert_bytes) {
                $statements[] = $this->formatInsertStatement($prefix, $values);
                $values = array();
                $statement_bytes = strlen($prefix) + strlen(";\n");
            }
            $statement_bytes += $value_bytes + ($values === array() ? 0 : 2);
            if ($statement_bytes > $this->max_insert_bytes) {
                throw new \InvalidArgumentException('Single row for table ' . $rows->tableName() . ' exceeds the maximum INSERT statement size.');
            }
            $values[] = $value;
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

        $value = (string) $value;
        // Keep binary data out of SQL text, including NUL bytes rejected by clients.
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return '0x' . bin2hex($value);
        }
        return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), $value) . "'";
    }
}
