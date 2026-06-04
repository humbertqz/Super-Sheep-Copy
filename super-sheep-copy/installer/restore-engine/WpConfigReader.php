<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class WpConfigReader
{
    /**
     * @return array{readable:bool,has_db_name:bool,has_db_user:bool,has_db_password:bool,has_db_host:bool,has_table_prefix:bool,table_prefix:string}
     */
    public function readDatabaseConfig(string $wordpress_root): array
    {
        $defaults = array(
            'readable' => false,
            'has_db_name' => false,
            'has_db_user' => false,
            'has_db_password' => false,
            'has_db_host' => false,
            'has_table_prefix' => false,
            'table_prefix' => '',
        );

        $path = $this->configPath($wordpress_root);
        if ($path === '') {
            return $defaults;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return $defaults;
        }

        $prefix = $this->tablePrefix($contents);

        return array(
            'readable' => true,
            'has_db_name' => $this->definedValue($contents, 'DB_NAME') !== null,
            'has_db_user' => $this->definedValue($contents, 'DB_USER') !== null,
            'has_db_password' => $this->definedValue($contents, 'DB_PASSWORD') !== null,
            'has_db_host' => $this->definedValue($contents, 'DB_HOST') !== null,
            'has_table_prefix' => $prefix !== '',
            'table_prefix' => $prefix,
        );
    }

    /**
     * @return array{readable:bool,complete:bool,name:string,user:string,password:string,host:string,port:int,socket:string,charset:string,table_prefix:string}
     */
    public function readDatabaseCredentials(string $wordpress_root): array
    {
        $defaults = array(
            'readable' => false,
            'complete' => false,
            'name' => '',
            'user' => '',
            'password' => '',
            'host' => '',
            'port' => 0,
            'socket' => '',
            'charset' => '',
            'table_prefix' => '',
        );

        $path = $this->configPath($wordpress_root);
        if ($path === '') {
            return $defaults;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return $defaults;
        }

        $host_parts = $this->splitHost($this->definedValue($contents, 'DB_HOST') ?? '');
        $credentials = array(
            'readable' => true,
            'complete' => false,
            'name' => $this->definedValue($contents, 'DB_NAME') ?? '',
            'user' => $this->definedValue($contents, 'DB_USER') ?? '',
            'password' => $this->definedValue($contents, 'DB_PASSWORD') ?? '',
            'host' => $host_parts['host'],
            'port' => $host_parts['port'],
            'socket' => $host_parts['socket'],
            'charset' => $this->definedValue($contents, 'DB_CHARSET') ?? '',
            'table_prefix' => $this->tablePrefix($contents),
        );
        $credentials['complete'] = $credentials['name'] !== ''
            && $credentials['user'] !== ''
            && $credentials['host'] !== '';

        return $credentials;
    }

    private function configPath(string $wordpress_root): string
    {
        $root = rtrim($wordpress_root, '/\\');
        $paths = array(
            $root . '/wp-config.php',
            dirname($root) . '/wp-config.php',
        );

        foreach ($paths as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return '';
    }

    private function definedValue(string $contents, string $name): ?string
    {
        $pattern = '/define\\s*\\(\\s*["\']' . preg_quote($name, '/') . '["\']\\s*,\\s*(["\'])((?:\\\\.|(?!\\1).)*)\\1\\s*\\)/s';
        if (preg_match($pattern, $contents, $match) !== 1) {
            return null;
        }

        return stripcslashes((string) $match[2]);
    }

    private function tablePrefix(string $contents): string
    {
        if (preg_match('/\\$table_prefix\\s*=\\s*["\']([^"\']*)["\']\\s*;/', $contents, $match) !== 1) {
            return '';
        }

        return (string) $match[1];
    }

    /**
     * @return array{host:string,port:int,socket:string}
     */
    private function splitHost(string $host): array
    {
        if ($host === '') {
            return array('host' => '', 'port' => 0, 'socket' => '');
        }

        if (strpos($host, ':/') !== false) {
            [$hostname, $socket] = explode(':', $host, 2);

            return array('host' => $hostname, 'port' => 0, 'socket' => $socket);
        }

        if (preg_match('/^([^:]+):(\\d+)$/', $host, $match) === 1) {
            return array('host' => (string) $match[1], 'port' => (int) $match[2], 'socket' => '');
        }

        return array('host' => $host, 'port' => 0, 'socket' => '');
    }
}
