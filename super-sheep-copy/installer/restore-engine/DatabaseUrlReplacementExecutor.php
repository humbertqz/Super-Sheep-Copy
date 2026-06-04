<?php
// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_report,WordPress.DB.RestrictedClasses.mysql__mysqli -- Standalone installer connects before WordPress is available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use SuperSheepCopy\Shared\Urls\StructuredValueReplacer;

class DatabaseUrlReplacementExecutor
{
    /** @var mixed */
    private $mysqli;

    /**
     * @param mixed $mysqli
     */
    public function __construct($mysqli = null)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @param array<string,mixed> $credentials
     * @param array<string,mixed> $plan
     * @param array<string,array{columns:list<string>,primary_key:string}> $tables
     * @return array{completed:bool,table_count:int,scanned_rows:int,changed_rows:int,scanned_cells:int,changed_cells:int,replacement_count:int,warnings:list<string>}
     */
    public function execute(array $credentials, array $plan, array $tables, StructuredValueReplacer $replacer): array
    {
        $connection = $this->mysqli;
        $should_close = false;
        if (!is_object($connection)) {
            $connection = $this->connect($credentials);
            $should_close = is_object($connection);
        }
        if (!is_object($connection) || !method_exists($connection, 'query')) {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('Database connection failed.'));
        }
        $this->setConnectionCharset($connection, $credentials);

        $source_urls = isset($plan['source_urls']) && is_array($plan['source_urls']) ? $this->stringList($plan['source_urls']) : array();
        $destination_url = isset($plan['destination_url']) ? (string) $plan['destination_url'] : '';
        if ($source_urls === array() || $destination_url === '') {
            return $this->result(false, 0, 0, 0, 0, 0, 0, array('URL replacement plan is malformed.'));
        }

        $scanned_rows = 0;
        $changed_rows = 0;
        $scanned_cells = 0;
        $changed_cells = 0;
        $replacement_count = 0;
        $warnings = array();

        foreach ($tables as $table => $metadata) {
            if (!$this->isIdentifier($table)) {
                $warnings[] = 'Invalid database table identifier: ' . $table;
                continue;
            }
            $primary_key = isset($metadata['primary_key']) ? (string) $metadata['primary_key'] : '';
            $columns = isset($metadata['columns']) ? $metadata['columns'] : array();
            foreach (array_merge($columns, $primary_key === '' ? array() : array($primary_key)) as $identifier) {
                if (!$this->isIdentifier((string) $identifier)) {
                    $warnings[] = 'Invalid database column identifier: ' . (string) $identifier;
                    continue 2;
                }
            }
            if ($columns === array()) {
                continue;
            }

            $select_columns = $primary_key === '' ? $columns : array_merge(array($primary_key), $columns);
            $result = $connection->query('SELECT ' . implode(', ', array_map(array($this, 'quoteIdentifier'), $select_columns)) . ' FROM ' . $this->quoteIdentifier($table));
            if (!is_object($result) || !method_exists($result, 'fetch_assoc')) {
                $warnings[] = 'Unable to scan table: ' . $table;
                continue;
            }

            while (is_array($row = $result->fetch_assoc())) {
                ++$scanned_rows;
                $changed_values = array();
                $row_replacement_count = 0;
                foreach ($columns as $column) {
                    ++$scanned_cells;
                    $original = isset($row[$column]) && is_scalar($row[$column]) ? (string) $row[$column] : '';
                    $value = $original;
                    $cell_replacements = 0;
                    foreach ($source_urls as $source_url) {
                        $cell_result = $replacer->replace($value, $source_url, $destination_url);
                        $value = $cell_result->value();
                        $cell_replacements += $cell_result->replacementCount();
                    }
                    if ($value === $original) {
                        continue;
                    }
                    $changed_values[$column] = $value;
                    ++$changed_cells;
                    $row_replacement_count += $cell_replacements;
                }

                if ($changed_values !== array()) {
                    if (!$this->updateRow($connection, $table, $changed_values, $primary_key, $select_columns, $row)) {
                        $warnings[] = 'Unable to update table: ' . $table;
                        continue 2;
                    }
                    ++$changed_rows;
                    $replacement_count += $row_replacement_count;
                }
            }
        }

        if ($should_close && is_object($connection) && method_exists($connection, 'close')) {
            $connection->close();
        }

        return $this->result($warnings === array(), count($tables), $scanned_rows, $changed_rows, $scanned_cells, $changed_cells, $replacement_count, $warnings);
    }

    /**
     * @param array<string,mixed> $credentials
     * @return mixed
     */
    protected function connect(array $credentials)
    {
        if (!class_exists('\\mysqli')) {
            return null;
        }

        \mysqli_report(MYSQLI_REPORT_OFF);

        return @new \mysqli(
            isset($credentials['host']) ? (string) $credentials['host'] : '',
            isset($credentials['user']) ? (string) $credentials['user'] : '',
            isset($credentials['password']) ? (string) $credentials['password'] : '',
            isset($credentials['name']) ? (string) $credentials['name'] : '',
            isset($credentials['port']) ? (int) $credentials['port'] : 0,
            isset($credentials['socket']) ? (string) $credentials['socket'] : ''
        );
    }

    /**
     * @param mixed $connection
     * @param array<string,string> $changed_values
     * @param list<string> $selected_columns
     * @param array<string,mixed> $row
     */
    private function updateRow($connection, string $table, array $changed_values, string $primary_key, array $selected_columns, array $row): bool
    {
        if ($changed_values === array()) {
            return true;
        }

        $where = '';
        $limit = '';

        if ($primary_key !== '' && array_key_exists($primary_key, $row)) {
            $where = $this->quoteIdentifier($primary_key) . " = '" . $this->escapeSqlValue($connection, (string) $row[$primary_key]) . "'";
        } else {
            $where = $this->buildOriginalValuesPredicate($connection, $selected_columns, $row);
            $limit = ' LIMIT 1';
        }

        if ($where === '') {
            return false;
        }

        $assignments = array();
        foreach ($changed_values as $column => $value) {
            $assignments[] = $this->quoteIdentifier($column) . " = '" . $this->escapeSqlValue($connection, $value) . "'";
        }

        return $connection->query(
            'UPDATE ' . $this->quoteIdentifier($table)
            . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $where
            . $limit
        ) === true;
    }

    /**
     * @param mixed $connection
     * @param list<string> $selected_columns
     * @param array<string,mixed> $row
     */
    private function buildOriginalValuesPredicate($connection, array $selected_columns, array $row): string
    {
        if ($selected_columns === array()) {
            return '';
        }

        $predicates = array();
        foreach ($selected_columns as $selected_column) {
            if (!array_key_exists($selected_column, $row)) {
                return '';
            }

            if ($row[$selected_column] === null) {
                $predicates[] = $this->quoteIdentifier($selected_column) . ' IS NULL';
                continue;
            }

            if (!is_scalar($row[$selected_column])) {
                return '';
            }

            $predicates[] = $this->quoteIdentifier($selected_column) . " = '" . $this->escapeSqlValue($connection, (string) $row[$selected_column]) . "'";
        }

        return implode(' AND ', $predicates);
    }

    /**
     * @param mixed $connection
     */
    private function escapeSqlValue($connection, string $value): string
    {
        return method_exists($connection, 'real_escape_string') ? $connection->real_escape_string($value) : addslashes($value);
    }

    /**
     * @param mixed $connection
     * @param array<string,mixed> $credentials
     */
    private function setConnectionCharset($connection, array $credentials): void
    {
        $charset = isset($credentials['charset']) && is_scalar($credentials['charset']) ? (string) $credentials['charset'] : '';
        $charset = $charset !== '' ? $charset : 'utf8mb4';
        if (preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
            $charset = 'utf8mb4';
        }

        if (is_object($connection) && method_exists($connection, 'set_charset')) {
            $connection->set_charset($charset);
        }
    }

    private function isIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $strings = array();
        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $strings[] = (string) $value;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param list<string> $warnings
     * @return array{completed:bool,table_count:int,scanned_rows:int,changed_rows:int,scanned_cells:int,changed_cells:int,replacement_count:int,warnings:list<string>}
     */
    private function result(bool $completed, int $table_count, int $scanned_rows, int $changed_rows, int $scanned_cells, int $changed_cells, int $replacement_count, array $warnings): array
    {
        return array(
            'completed' => $completed,
            'table_count' => $table_count,
            'scanned_rows' => $scanned_rows,
            'changed_rows' => $changed_rows,
            'scanned_cells' => $scanned_cells,
            'changed_cells' => $changed_cells,
            'replacement_count' => $replacement_count,
            'warnings' => $warnings,
        );
    }
}
