<?php
// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_report,WordPress.DB.RestrictedClasses.mysql__mysqli,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone rollback dump runs before WordPress APIs are available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class RollbackDatabaseDumper
{
    /**
     * @param array<string,mixed> $credentials
     * @return array{included:bool,dump_path:string,table_count:int,warnings:list<string>}
     */
    public function dump(array $credentials, string $rollback_directory): array
    {
        $prefix = isset($credentials['table_prefix']) ? (string) $credentials['table_prefix'] : '';
        if ($prefix === '') {
            return $this->result(false, '', 0, array('Database table prefix is empty; database rollback dump skipped.'));
        }

        if (!class_exists('\\mysqli')) {
            return $this->result(false, '', 0, array('The mysqli extension is not available; database rollback dump skipped.'));
        }

        \mysqli_report(\MYSQLI_REPORT_OFF);
        $mysqli = @new \mysqli(
            isset($credentials['host']) ? (string) $credentials['host'] : '',
            isset($credentials['user']) ? (string) $credentials['user'] : '',
            isset($credentials['password']) ? (string) $credentials['password'] : '',
            isset($credentials['name']) ? (string) $credentials['name'] : '',
            isset($credentials['port']) ? (int) $credentials['port'] : 0,
            isset($credentials['socket']) ? (string) $credentials['socket'] : ''
        );

        if ($mysqli->connect_errno !== 0) {
            return $this->result(false, '', 0, array('Database connection failed; database rollback dump skipped.'));
        }

        $target_dir = rtrim($rollback_directory, '/\\') . '/database';
        if (!is_dir($target_dir) && !mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
            $mysqli->close();

            return $this->result(false, '', 0, array('Unable to create rollback database directory.'));
        }

        $relative_path = 'database/destination.sql';
        $target = rtrim($rollback_directory, '/\\') . '/' . $relative_path;
        $sql = $this->buildDump($mysqli, isset($credentials['name']) ? (string) $credentials['name'] : '', $prefix);
        $mysqli->close();

        if (file_put_contents($target, $sql['contents']) === false) {
            return $this->result(false, '', 0, array('Unable to write rollback database dump.'));
        }

        return $this->result(true, $relative_path, $sql['table_count'], array());
    }

    public function formatValueForTest($value): string
    {
        return $this->formatValue($value, null);
    }

    public function quoteIdentifierForTest(string $identifier): string
    {
        return $this->quoteIdentifier($identifier);
    }

    /**
     * @return array{contents:string,table_count:int}
     */
    private function buildDump(\mysqli $mysqli, string $database, string $prefix): array
    {
        $tables = $this->tables($mysqli, $prefix);
        $lines = array(
            '-- Super Sheep Copy destination database rollback dump',
            '-- Created at: ' . gmdate('c'),
            '-- Database: ' . $database,
            '',
        );

        foreach ($tables as $table) {
            $create_result = $mysqli->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($table));
            if (!$create_result instanceof \mysqli_result) {
                continue;
            }

            $create_row = $create_result->fetch_assoc();
            if (!isset($create_row['Create Table'])) {
                continue;
            }

            $lines[] = 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table) . ';';
            $lines[] = (string) $create_row['Create Table'] . ';';

            $rows = $mysqli->query('SELECT * FROM ' . $this->quoteIdentifier($table));
            if ($rows instanceof \mysqli_result) {
                while ($row = $rows->fetch_assoc()) {
                    $columns = array();
                    $values = array();
                    foreach ($row as $column => $value) {
                        $columns[] = $this->quoteIdentifier((string) $column);
                        $values[] = $this->formatValue($value, $mysqli);
                    }
                    $lines[] = 'INSERT INTO ' . $this->quoteIdentifier($table)
                        . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
                }
            }

            $lines[] = '';
        }

        return array('contents' => implode("\n", $lines) . "\n", 'table_count' => count($tables));
    }

    /**
     * @return list<string>
     */
    private function tables(\mysqli $mysqli, string $prefix): array
    {
        $safe_prefix = $mysqli->real_escape_string($prefix);
        $result = $mysqli->query("SHOW TABLES LIKE '" . $safe_prefix . "%'");
        $tables = array();

        if (!$result instanceof \mysqli_result) {
            return $tables;
        }

        while ($row = $result->fetch_array()) {
            if (isset($row[0]) && is_string($row[0])) {
                $tables[] = $row[0];
            }
        }

        sort($tables);

        return $tables;
    }

    private function formatValue($value, ?\mysqli $mysqli): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $string = (string) $value;
        $escaped = $mysqli instanceof \mysqli
            ? $mysqli->real_escape_string($string)
            : str_replace(array('\\', "\n", "\r", "\0", "'"), array('\\\\', '\\n', '\\r', '\\0', "\\'"), $string);

        return "'" . $escaped . "'";
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param list<string> $warnings
     * @return array{included:bool,dump_path:string,table_count:int,warnings:list<string>}
     */
    private function result(bool $included, string $dump_path, int $table_count, array $warnings): array
    {
        return array(
            'included' => $included,
            'dump_path' => $dump_path,
            'table_count' => $table_count,
            'warnings' => $warnings,
        );
    }
}
