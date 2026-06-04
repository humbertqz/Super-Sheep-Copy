<?php

declare(strict_types=1);

namespace SuperSheepCopy\Support;

final class EnvironmentChecker implements EnvironmentCheckerInterface
{
    public function check(): array
    {
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
