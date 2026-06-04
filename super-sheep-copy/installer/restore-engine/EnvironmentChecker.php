<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class EnvironmentChecker
{
    /**
     * @return array<string, array{label:string,value:string,status:string}>
     */
    public function check(): array
    {
        return array(
            'php_version' => array(
                'label' => 'PHP version',
                'value' => PHP_VERSION,
                'status' => version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'warning',
            ),
            'zip' => array(
                'label' => 'ZIP extension',
                'value' => extension_loaded('zip') ? 'Available' : 'Missing',
                'status' => extension_loaded('zip') ? 'ok' : 'warning',
            ),
            'disk_free_space' => array(
                'label' => 'Disk free space',
                'value' => function_exists('disk_free_space') ? (string) disk_free_space(__DIR__) : 'Unavailable',
                'status' => 'ok',
            ),
        );
    }
}
