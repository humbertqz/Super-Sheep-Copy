<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Lock;

final class WordPressOptionBackupJobLockStore implements BackupJobLockStoreInterface
{
    /** @var object */
    private $wpdb;

    /** @param object $wpdb */
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function add(string $name, array $value): bool
    {
        return add_option($name, $value, '', 'no');
    }

    public function get(string $name): ?array
    {
        $value = get_option($name, null);

        return is_array($value) ? $value : null;
    }

    public function deleteIfUnchanged(string $name, array $expected): bool
    {
        $deleted = $this->wpdb->delete(
            $this->wpdb->options,
            array(
                'option_name' => $name,
                'option_value' => maybe_serialize($expected),
            ),
            array('%s', '%s')
        );

        if ($deleted !== 1) {
            return false;
        }

        wp_cache_delete($name, 'options');

        return true;
    }
}
