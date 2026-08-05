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

    /** @return mixed */
    public function get(string $name)
    {
        $missing = new \stdClass();
        $value = get_option($name, $missing);

        return $value === $missing ? null : $value;
    }

    /** @param mixed $expected */
    public function deleteIfUnchanged(string $name, $expected): bool
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
