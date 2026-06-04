<?php
// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_report,WordPress.DB.RestrictedClasses.mysql__mysqli -- Standalone installer connects before WordPress is available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

class DatabaseConnectionTester
{
    /**
     * @param array<string,mixed> $credentials
     * @return array{connected:bool,status:string,message:string,database:string,host:string}
     */
    public function test(array $credentials): array
    {
        $database = isset($credentials['name']) ? (string) $credentials['name'] : '';
        $host = isset($credentials['host']) ? (string) $credentials['host'] : '';

        if (empty($credentials['complete'])) {
            return $this->result(false, 'warning', 'Database credentials are incomplete.', $database, $host);
        }

        if (!class_exists('\\mysqli')) {
            return $this->result(false, 'warning', 'The mysqli extension is not available.', $database, $host);
        }

        \mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new \mysqli(
            $host,
            isset($credentials['user']) ? (string) $credentials['user'] : '',
            isset($credentials['password']) ? (string) $credentials['password'] : '',
            $database,
            isset($credentials['port']) ? (int) $credentials['port'] : 0,
            isset($credentials['socket']) ? (string) $credentials['socket'] : ''
        );

        if ($mysqli->connect_errno !== 0) {
            return $this->result(false, 'error', 'Database connection failed.', $database, $host);
        }

        $mysqli->close();

        return $this->result(true, 'ok', 'Connected', $database, $host);
    }

    /**
     * @return array{connected:bool,status:string,message:string,database:string,host:string}
     */
    private function result(bool $connected, string $status, string $message, string $database, string $host): array
    {
        return array(
            'connected' => $connected,
            'status' => $status,
            'message' => $message,
            'database' => $database,
            'host' => $host,
        );
    }
}
