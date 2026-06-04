<?php
// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_report,WordPress.DB.RestrictedClasses.mysql__mysqli -- Standalone installer connects before WordPress is available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class DatabaseTextColumnInspector
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
     * @param list<string> $tables
     * @param array<string,mixed> $credentials
     * @return array{valid:bool,tables:array<string,array{columns:list<string>,primary_key:string}>,warnings:list<string>}
     */
    public function inspect(array $tables, array $credentials = array()): array
    {
        $connection = $this->mysqli;
        $should_close = false;
        if (!is_object($connection) && $credentials !== array()) {
            $connection = $this->connect($credentials);
            $should_close = is_object($connection);
        }

        if (!is_object($connection) || !method_exists($connection, 'query')) {
            return array('valid' => false, 'tables' => array(), 'warnings' => array('Unable to inspect database columns.'));
        }

        $metadata = array();
        $warnings = array();
        foreach ($tables as $table) {
            if (!$this->isIdentifier($table)) {
                $warnings[] = 'Invalid database table identifier: ' . $table;
                continue;
            }

            $result = $connection->query('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table));
            if (!is_object($result) || !method_exists($result, 'fetch_assoc')) {
                $warnings[] = 'Unable to inspect columns for table: ' . $table;
                continue;
            }

            $columns = array();
            $primary_key = '';
            while (is_array($row = $result->fetch_assoc())) {
                if (!isset($row['Field'], $row['Type'])) {
                    continue;
                }
                $field = (string) $row['Field'];
                if (!$this->isIdentifier($field)) {
                    continue;
                }
                if (isset($row['Key']) && (string) $row['Key'] === 'PRI' && $primary_key === '') {
                    $primary_key = $field;
                }
                if ($this->isTextType((string) $row['Type'])) {
                    $columns[] = $field;
                }
            }

            $metadata[$table] = array('columns' => $columns, 'primary_key' => $primary_key);
        }

        if ($should_close && is_object($connection) && method_exists($connection, 'close')) {
            $connection->close();
        }

        return array('valid' => $warnings === array(), 'tables' => $warnings === array() ? $metadata : array(), 'warnings' => $warnings);
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

    private function isTextType(string $type): bool
    {
        $type = strtolower($type);

        return preg_match('/^(?:var)?char\b|^(?:tiny|medium|long)?text\b|^json\b/', $type) === 1;
    }

    private function isIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
