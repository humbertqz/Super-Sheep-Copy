<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use SuperSheepCopy\Support\EnvironmentCheckerInterface;

final class BackupMetadataCollector implements BackupMetadataCollectorInterface
{
    private EnvironmentCheckerInterface $environment_checker;

    public function __construct(EnvironmentCheckerInterface $environment_checker)
    {
        $this->environment_checker = $environment_checker;
    }

    /**
     * @return array<string,mixed>
     */
    public function collect(): array
    {
        global $wpdb;

        return array(
            'source_site_url' => function_exists('site_url') ? site_url() : '',
            'source_home_url' => function_exists('home_url') ? home_url() : '',
            'wordpress_version' => function_exists('get_bloginfo') ? get_bloginfo('version') : '',
            'php_version' => PHP_VERSION,
            'database_version' => is_object($wpdb) && method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : '',
            'table_prefix' => is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : '',
            'is_multisite' => function_exists('is_multisite') ? is_multisite() : false,
            'active_theme' => function_exists('get_stylesheet') ? get_stylesheet() : '',
            'active_plugins' => array_values((array) (function_exists('get_option') ? get_option('active_plugins', array()) : array())),
            'must_use_plugins' => function_exists('get_mu_plugins') ? array_keys((array) get_mu_plugins()) : array(),
            'created_at' => gmdate('c'),
            'file_count' => 0,
            'database_table_count' => 0,
            'archive_size' => 0,
            'checksums' => array(),
            'exclusions' => array('wp-content/cache', 'wp-content/uploads/super-sheep-copy'),
            'environment' => $this->environment_checker->check(),
        );
    }
}
