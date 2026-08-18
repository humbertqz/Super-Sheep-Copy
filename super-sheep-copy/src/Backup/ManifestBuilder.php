<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class ManifestBuilder
{
    private string $plugin_version;
    private string $backup_format_version;

    public function __construct(string $plugin_version, string $backup_format_version)
    {
        $this->plugin_version = $plugin_version;
        $this->backup_format_version = $backup_format_version;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function build(array $metadata): Manifest
    {
        return new Manifest(array(
            'project' => 'Super Sheep Copy',
            'plugin_version' => $this->plugin_version,
            'backup_format_version' => $this->backup_format_version,
            'source_site_url' => (string) $metadata['source_site_url'],
            'source_home_url' => (string) $metadata['source_home_url'],
            'source_wordpress_version' => (string) $metadata['wordpress_version'],
            'source_php_version' => (string) $metadata['php_version'],
            'source_database_version' => (string) $metadata['database_version'],
            'source_table_prefix' => (string) $metadata['table_prefix'],
            'is_multisite' => (bool) $metadata['is_multisite'],
            'active_theme' => (string) $metadata['active_theme'],
            'active_plugins' => array_values((array) $metadata['active_plugins']),
            'must_use_plugins' => array_values((array) $metadata['must_use_plugins']),
            'created_at' => (string) $metadata['created_at'],
            'file_count' => (int) $metadata['file_count'],
            'database_table_count' => (int) $metadata['database_table_count'],
            'archive_size' => (int) $metadata['archive_size'],
            'package_format' => isset($metadata['package_format']) ? (string) $metadata['package_format'] : 'zip',
            'package_extension' => isset($metadata['package_extension']) ? (string) $metadata['package_extension'] : '.zip',
            'package_schema_version' => isset($metadata['package_schema_version']) ? (int) $metadata['package_schema_version'] : 1,
            'checksums' => (array) $metadata['checksums'],
            'exclusions' => array_values((array) $metadata['exclusions']),
            'warnings' => array_values((array) ($metadata['warnings'] ?? array())),
            'environment' => (array) $metadata['environment'],
        ));
    }
}
