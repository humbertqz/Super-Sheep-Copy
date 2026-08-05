<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Lock\WordPressOptionBackupJobLockStore;

final class WordPressOptionBackupJobLockStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ssc_test_options'] = array();
        $GLOBALS['ssc_test_add_option_calls'] = array();
        $GLOBALS['ssc_test_cache_deletes'] = array();
    }

    public function testAddCreatesNonAutoloadedOptionAtomically(): void
    {
        $store = new WordPressOptionBackupJobLockStore(new LockStoreWpdb());
        $value = array('owner' => 'owner-1', 'expires_at' => 1120);

        self::assertTrue($store->add('job-lock', $value));
        self::assertFalse($store->add('job-lock', $value));
        self::assertSame(array('job-lock', $value, '', 'no'), $GLOBALS['ssc_test_add_option_calls'][0]);
    }

    public function testDeleteUsesExactSerializedValueAndClearsOptionCache(): void
    {
        $wpdb = new LockStoreWpdb();
        $store = new WordPressOptionBackupJobLockStore($wpdb);
        $expected = array('owner' => 'owner-1', 'expires_at' => 1120);

        self::assertTrue($store->deleteIfUnchanged('job-lock', $expected));
        self::assertSame('wp_options', $wpdb->deleted_table);
        self::assertSame(array(
            'option_name' => 'job-lock',
            'option_value' => serialize($expected),
        ), $wpdb->deleted_where);
        self::assertSame(array(array('job-lock', 'options')), $GLOBALS['ssc_test_cache_deletes']);
    }

    public function testFailedCompareDeleteDoesNotClearCache(): void
    {
        $wpdb = new LockStoreWpdb(0);
        $store = new WordPressOptionBackupJobLockStore($wpdb);

        self::assertFalse($store->deleteIfUnchanged('job-lock', array('owner' => 'old', 'expires_at' => 1)));
        self::assertSame(array(), $GLOBALS['ssc_test_cache_deletes']);
    }
}

final class LockStoreWpdb
{
    public string $options = 'wp_options';
    public string $deleted_table = '';
    /** @var array<string,mixed> */
    public array $deleted_where = array();
    private int $delete_result;

    public function __construct(int $delete_result = 1)
    {
        $this->delete_result = $delete_result;
    }

    /**
     * @param array<string,mixed> $where
     * @param string[] $where_format
     */
    public function delete(string $table, array $where, array $where_format): int
    {
        $this->deleted_table = $table;
        $this->deleted_where = $where;

        return $this->delete_result;
    }
}
