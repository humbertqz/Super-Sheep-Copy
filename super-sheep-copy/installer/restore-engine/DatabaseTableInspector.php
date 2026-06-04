<?php
// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_report,WordPress.DB.RestrictedClasses.mysql__mysqli -- Standalone installer connects before WordPress is available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class DatabaseTableInspector
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
     * @param array<string,string> $table_map
     * @param array<string,mixed> $credentials
     * @return array{valid:bool,warnings:list<string>}
     */
    public function verifyTables(array $table_map, array $credentials = array()): array
    {
        $warnings = array();
        $connection = $this->mysqli;
        $should_close = false;

        if (!is_object($connection) && $credentials !== array()) {
            $connection = $this->connect($credentials);
            $should_close = is_object($connection);
        }

        foreach ($table_map as $staging_table) {
            $exists = $this->inspectTable($staging_table, $connection);
            if ($exists === null) {
                $warnings[] = 'Unable to inspect staging table: ' . $staging_table;
                continue;
            }

            if (!$exists) {
                $warnings[] = 'Missing staging table: ' . $staging_table;
            }
        }

        if ($should_close && is_object($connection) && method_exists($connection, 'close')) {
            $connection->close();
        }

        return array(
            'valid' => $warnings === array(),
            'warnings' => $warnings,
        );
    }

    /**
     * @param list<string> $tables
     * @param array<string,mixed> $credentials
     * @return array{valid:bool,tables:array<string,bool>,warnings:list<string>}
     */
    public function existingTables(array $tables, array $credentials = array()): array
    {
        $connection = $this->mysqli;
        $should_close = false;

        if (!is_object($connection) && $credentials !== array()) {
            $connection = $this->connect($credentials);
            $should_close = is_object($connection);
        }

        if (!is_object($connection) || !method_exists($connection, 'query')) {
            return array(
                'valid' => false,
                'tables' => array(),
                'warnings' => array('Unable to inspect destination tables.'),
            );
        }

        $existing = array();
        $warnings = array();
        foreach ($tables as $table) {
            $exists = $this->inspectTable($table, $connection);
            if ($exists === null) {
                $warnings[] = 'Unable to inspect destination table: ' . $table;
                continue;
            }

            $existing[$table] = $exists;
        }
        if ($should_close && is_object($connection) && method_exists($connection, 'close')) {
            $connection->close();
        }

        return array(
            'valid' => $warnings === array(),
            'tables' => $warnings === array() ? $existing : array(),
            'warnings' => $warnings,
        );
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
     */
    protected function tableExists(string $table, $connection = null): bool
    {
        return $this->inspectTable($table, $connection) === true;
    }

    /**
     * @param mixed $connection
     * @return bool|null Null means the inspection query failed.
     */
    protected function inspectTable(string $table, $connection = null): ?bool
    {
        if (!is_object($connection) || !method_exists($connection, 'query')) {
            return null;
        }

        $escaped = method_exists($connection, 'real_escape_string')
            ? $connection->real_escape_string($table)
            : addslashes($table);

        $result = $connection->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $escaped . "' LIMIT 1");
        if ($result === false) {
            return null;
        }

        if (!is_object($result) || !method_exists($result, 'fetch_row')) {
            return null;
        }

        $row = $result->fetch_row();
        if (!is_array($row) || !isset($row[0])) {
            return false;
        }

        return (string) $row[0] === $table;
    }
}
