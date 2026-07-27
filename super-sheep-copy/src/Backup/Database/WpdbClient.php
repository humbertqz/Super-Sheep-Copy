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
        return array_values((array) $this->wpdb->get_col('SHOW TABLES'));
    }

    public function getCreateTableSql(string $table): string
    {
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers cannot be parameterized; quoteIdentifier() validates and quotes the table name.
        $row = (array) $this->wpdb->get_row('SHOW CREATE TABLE ' . $this->quoteIdentifier($table), 'ARRAY_N');

        return isset($row[1]) ? (string) $row[1] : '';
    }

    public function getPrimaryKey(string $table): ?string
    {
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers cannot be parameterized; quoteIdentifier() validates and quotes the table name.
        $rows = (array) $this->wpdb->get_results('SHOW KEYS FROM ' . $this->quoteIdentifier($table) . " WHERE Key_name = 'PRIMARY'", 'ARRAY_A');

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Column_name']) && is_string($row['Column_name']) && $row['Column_name'] !== '') {
                return $row['Column_name'];
            }
        }

        return null;
    }

    public function getRowCount(string $table): int
    {
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers cannot be parameterized; quoteIdentifier() validates and quotes the table name.
        return (int) $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table));
    }

    public function getTableStatus(string $table): array
    {
        return (array) $this->wpdb->get_row($this->wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table), 'ARRAY_A');
    }

    public function getColumns(string $table): array
    {
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers cannot be parameterized; quoteIdentifier() validates and quotes the table name.
        $rows = (array) $this->wpdb->get_results('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table), 'ARRAY_A');
        $columns = array();

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Field']) && is_string($row['Field'])) {
                $columns[] = $row['Field'];
            }
        }

        return $columns;
    }

    public function getRows(string $sql): array
    {
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built by WpdbDatabaseExporter from validated identifiers and prepared scalar values.
        return array_values((array) $this->wpdb->get_results($sql, 'ARRAY_A'));
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
}
