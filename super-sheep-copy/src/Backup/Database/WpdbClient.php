<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- This adapter validates SQL identifiers and prepares scalar values before delegating to wpdb.
final class WpdbClient implements WpdbClientInterface
{
    /** @var object */
    private $wpdb;

    /**
     * @param object $wpdb
     */
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function getTables(): array
    {
        $tables = $this->wpdb->get_col('SHOW TABLES');
        $this->assertQuerySucceeded();
        return array_values((array) $tables);
    }

    public function getCreateTableSql(string $table): string
    {
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers cannot be parameterized; quoteIdentifier() validates and quotes the table name.
        $row = (array) $this->wpdb->get_row('SHOW CREATE TABLE ' . $this->quoteIdentifier($table), 'ARRAY_N');
        $this->assertQuerySucceeded();

        return isset($row[1]) ? (string) $row[1] : '';
    }

    public function getPrimaryKey(string $table): ?string
    {
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers cannot be parameterized; quoteIdentifier() validates and quotes the table name.
        $rows = (array) $this->wpdb->get_results('SHOW KEYS FROM ' . $this->quoteIdentifier($table) . " WHERE Key_name = 'PRIMARY'", 'ARRAY_A');
        $this->assertQuerySucceeded();
        if (count($rows) !== 1 || empty($rows[0]['Column_name'])) {
            return null;
        }
        $primary_key = (string) $rows[0]['Column_name'];
        $columns = (array) $this->wpdb->get_results('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table), 'ARRAY_A');
        $this->assertQuerySucceeded();
        foreach ($columns as $column) {
            $type = strtolower((string) ($column['Type'] ?? ''));
            // The cursor uses PHP integers. Other keys require offset pagination.
            if (($column['Field'] ?? null) === $primary_key
                && preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)\b/', $type) === 1) {
                if (PHP_INT_SIZE < 8 && strpos($type, 'bigint') === 0) {
                    return null;
                }
                if (PHP_INT_SIZE < 8 || (strpos($type, 'bigint') === 0 && strpos($type, 'unsigned') !== false)) {
                    $maximum = $this->wpdb->get_var('SELECT MAX(' . $this->quoteIdentifier($primary_key) . ') FROM ' . $this->quoteIdentifier($table));
                    $this->assertQuerySucceeded();
                    if ($maximum !== null && filter_var($maximum, FILTER_VALIDATE_INT) === false) {
                        return null;
                    }
                }
                return $primary_key;
            }
        }

        return null;
    }

    public function getRowCount(string $table): int
    {
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers cannot be parameterized; quoteIdentifier() validates and quotes the table name.
        $count = $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table));
        $this->assertQuerySucceeded();
        return (int) $count;
    }

    public function getTableStatus(string $table): array
    {
        $pattern = str_replace(array('\\', '_', '%'), array('\\\\', '\\_', '\\%'), $table);
        $status = (array) $this->wpdb->get_row($this->wpdb->prepare('SHOW TABLE STATUS LIKE %s', $pattern), 'ARRAY_A');
        $this->assertQuerySucceeded();
        return $status;
    }

    public function getColumns(string $table): array
    {
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers cannot be parameterized; quoteIdentifier() validates and quotes the table name.
        $rows = (array) $this->wpdb->get_results('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table), 'ARRAY_A');
        $this->assertQuerySucceeded();
        $columns = array();

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Field']) && is_string($row['Field'])
                && preg_match('/\b(?:STORED|VIRTUAL) GENERATED\b/i', (string) ($row['Extra'] ?? '')) !== 1) {
                $columns[] = $row['Field'];
            }
        }

        return $columns;
    }

    public function getRows(string $sql): array
    {
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built by WpdbDatabaseExporter from validated identifiers and prepared scalar values.
        $rows = $this->wpdb->get_results($sql, 'ARRAY_A');
        $this->assertQuerySucceeded();
        return array_values((array) $rows);
    }

    public function prepare(string $sql, array $args): string
    {
        return (string) $this->wpdb->prepare($sql, ...$args);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $identifier)) {
            throw new \InvalidArgumentException('Unsafe SQL identifier.');
        }

        return '`' . $identifier . '`';
    }

    private function assertQuerySucceeded(): void
    {
        if (!empty($this->wpdb->last_error)) {
            throw new \RuntimeException('Database export query failed: ' . $this->wpdb->last_error);
        }
    }
}
