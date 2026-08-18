<?php

declare(strict_types=1);

namespace SuperSheepCopy\Support;

use SuperSheepCopy\Backup\Package\CliZipPackageWriter;

final class EnvironmentChecker implements EnvironmentCheckerInterface
{
    private string $backup_directory;

    public function __construct(string $backup_directory = '')
    {
        $this->backup_directory = $backup_directory;
    }

    public function check(): array
    {
        $cli_zip_available = (new CliZipPackageWriter())->isAvailable();
        $storage_path = $this->backup_directory !== '' ? $this->backup_directory : sys_get_temp_dir();
        $storage_probe = is_dir($storage_path) ? $storage_path : dirname($storage_path);
        while (!is_dir($storage_probe) && dirname($storage_probe) !== $storage_probe) {
            $storage_probe = dirname($storage_probe);
        }
        $storage_writable = is_dir($storage_probe) && is_writable($storage_probe);
        $free_bytes = function_exists('disk_free_space') ? disk_free_space($storage_probe) : false;
        $free_space = $free_bytes === false
            ? 'Unavailable'
            : (function_exists('size_format') ? size_format((int) $free_bytes, 1) : (string) (int) $free_bytes . ' bytes');

        return array(
            'php_version' => array(
                'label' => 'PHP version',
                'value' => PHP_VERSION,
                'status' => version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'error',
            ),
            'zip' => array(
                'label' => 'ZIP extension',
                'value' => extension_loaded('zip') ? 'Available' : 'Missing',
                'status' => extension_loaded('zip') ? 'ok' : 'warning',
            ),
            'cli_zip' => array(
                'label' => 'CLI zip command',
                'value' => $cli_zip_available ? 'Available' : 'Missing',
                'status' => $cli_zip_available ? 'ok' : 'warning',
            ),
            'tar_gzip' => array(
                'label' => 'TAR/GZIP package support',
                'value' => class_exists(\PharData::class) ? 'Available' : 'Missing',
                'status' => class_exists(\PharData::class) ? 'ok' : 'warning',
            ),
            'folder_package' => array(
                'label' => 'Folder package fallback',
                'value' => 'Available',
                'status' => 'ok',
            ),
            'backup_storage' => array(
                'label' => 'Backup storage writable',
                'value' => $storage_writable ? 'Yes' : 'No',
                'status' => $storage_writable ? 'ok' : 'error',
            ),
            'disk_free_space' => array(
                'label' => 'Backup storage free space',
                'value' => $free_space,
                'status' => $free_bytes === false ? 'warning' : 'info',
            ),
            'memory_limit' => array(
                'label' => 'PHP memory limit',
                'value' => (string) ini_get('memory_limit'),
                'status' => 'info',
            ),
            'max_execution_time' => array(
                'label' => 'Max execution time',
                'value' => (string) ini_get('max_execution_time'),
                'status' => 'info',
            ),
        );
    }
}
