<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

final class SqlDumpFormatter
{
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

        $values = array();
        foreach ($rows->rows() as $row) {
            $formatted = array();
            foreach ($rows->columns() as $column) {
                $formatted[] = $this->formatValue($row[$column]);
            }
            $values[] = '(' . implode(', ', $formatted) . ')';
        }

        return sprintf(
            "INSERT INTO `%s` (%s) VALUES\n%s;\n",
            $this->escapeIdentifier($rows->tableName()),
            implode(', ', $columns),
            implode(",\n", $values)
        );
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
