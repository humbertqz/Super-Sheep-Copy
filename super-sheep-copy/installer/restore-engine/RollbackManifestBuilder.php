<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class RollbackManifestBuilder
{
    /**
     * @param array<string,mixed> $config
     * @param list<array{relative_path:string,rollback_path:string,sha256:string,size:int}> $files
     * @param list<string> $warnings
     * @param array<string,mixed> $database
     * @return array<string,mixed>
     */
    public function build(array $config, string $destination_url, string $wordpress_root, array $files, array $warnings, array $database = array()): array
    {
        return array(
            'project' => 'Super Sheep Copy',
            'type' => 'rollback',
            'created_at' => gmdate('c'),
            'destination_url' => $destination_url,
            'wordpress_root' => $wordpress_root,
            'restore_job_id' => isset($config['restore_job_id']) ? (string) $config['restore_job_id'] : '',
            'source_site_url' => isset($config['source_site_url']) ? (string) $config['source_site_url'] : '',
            'source_home_url' => isset($config['source_home_url']) ? (string) $config['source_home_url'] : '',
            'staged_archive_basename' => isset($config['staged_archive_basename']) ? (string) $config['staged_archive_basename'] : '',
            'files' => $files,
            'warnings' => $warnings,
            'database' => $database !== array() ? $database : array(
                'included' => false,
                'dump_path' => '',
                'table_count' => 0,
                'warnings' => array(),
            ),
        );
    }
}
