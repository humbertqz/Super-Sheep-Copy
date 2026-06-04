<?php
// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_report,WordPress.DB.RestrictedClasses.mysql__mysqli -- Standalone installer connects before WordPress is available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class DatabaseTableSwapExecutor
{
    /**
     * @param array<string,mixed> $credentials
     * @param list<string> $sql
     */
    public function execute(array $credentials, array $sql): bool
    {
        if (!class_exists('\\mysqli')) {
            return false;
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
            return false;
        }

        foreach ($sql as $statement) {
            if (!$mysqli->query($statement)) {
                $mysqli->close();

                return false;
            }
        }

        $mysqli->close();

        return true;
    }
}
