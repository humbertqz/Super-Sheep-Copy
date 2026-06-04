<?php
// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_report,WordPress.DB.RestrictedClasses.mysql__mysqli -- Standalone installer connects before WordPress is available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class DatabaseBackupTableCleaner
{
    /**
     * @param array<string,mixed> $config
     * @return array{cleaned:bool,table_count:int,warnings:list<string>}
     */
    public function clean(string $engine_dir, array $config): array
    {
        $tables = isset($config['database_swap_backup_tables']) && is_array($config['database_swap_backup_tables'])
            ? $this->stringList($config['database_swap_backup_tables'])
            : array();
        if ($tables === array()) {
            return $this->result(true, 0, array());
        }

        if (!class_exists('\\mysqli')) {
            return $this->result(false, 0, array('The mysqli extension is not available.'));
        }

        $credentials = (new WpConfigReader())->readDatabaseCredentials(dirname(rtrim($engine_dir, '/\\')));
        if (empty($credentials['complete'])) {
            return $this->result(false, 0, array('Database credentials are incomplete.'));
        }

        \mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new \mysqli(
            isset($credentials['host']) ? (string) $credentials['host'] : '',
            isset($credentials['user']) ? (string) $credentials['user'] : '',
            isset($credentials['password']) ? (string) $credentials['password'] : '',
            isset($credentials['name']) ? (string) $credentials['name'] : '',
            isset($credentials['port']) ? (int) $credentials['port'] : 0,
            isset($credentials['socket']) ? (string) $credentials['socket'] : ''
        );

        if ($mysqli->connect_errno !== 0) {
            return $this->result(false, 0, array('Database connection failed.'));
        }

        $cleaned = 0;
        foreach ($tables as $table) {
            if (!$this->isIdentifier($table)) {
                $mysqli->close();

                return $this->result(false, $cleaned, array('Invalid backup table identifier: ' . $table));
            }

            if (!$mysqli->query('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table))) {
                $error = property_exists($mysqli, 'error') ? (string) $mysqli->error : '';
                $mysqli->close();

                return $this->result(false, $cleaned, array('Unable to drop backup table: ' . $table . ($error !== '' ? '. ' . $error : '')));
            }
            ++$cleaned;
        }

        $mysqli->close();

        return $this->result(true, $cleaned, array());
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
     * @param mixed $values
     * @return list<string>
     */
    private function stringList($values): array
    {
        if (!is_array($values)) {
            return array();
        }

        $strings = array();
        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * @param list<string> $warnings
     * @return array{cleaned:bool,table_count:int,warnings:list<string>}
     */
    private function result(bool $cleaned, int $table_count, array $warnings): array
    {
        return array(
            'cleaned' => $cleaned,
            'table_count' => $table_count,
            'warnings' => $warnings,
        );
    }
}
