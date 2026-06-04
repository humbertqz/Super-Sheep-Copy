<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\WpdbClient;

final class WpdbClientTest extends TestCase
{
    public function testWrapsWpdbOperations(): void
    {
        $wpdb = new FakeWpdb();
        $client = new WpdbClient($wpdb);

        self::assertSame(array('wp_posts', 'wp_options'), $client->getTables());
        self::assertSame('CREATE TABLE `wp_posts` (`ID` bigint)', $client->getCreateTableSql('wp_posts'));
        self::assertSame('ID', $client->getPrimaryKey('wp_posts'));
        self::assertSame(12, $client->getRowCount('wp_posts'));
        self::assertSame(array('Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4'), $client->getTableStatus('wp_posts'));
        self::assertSame(array('ID', 'post_title'), $client->getColumns('wp_posts'));
        self::assertSame(array(array('ID' => 1)), $client->getRows('SELECT * FROM `wp_posts`'));
        self::assertSame('SELECT * FROM `wp_posts` LIMIT 10', $client->prepare('SELECT * FROM `wp_posts` LIMIT %d', array(10)));
    }

    public function testGetsPrimaryKeyFromShowKeysColumnName(): void
    {
        $client = new WpdbClient(new FakeWpdbWithShowKeysRows());

        self::assertSame('option_id', $client->getPrimaryKey('wp_options'));
    }

    public function testGetsCreateTableSqlFromSecondShowCreateTableColumn(): void
    {
        $client = new WpdbClient(new FakeWpdbWithShowCreateTableRow());

        self::assertSame('CREATE TABLE `wp_actionscheduler_actions` (`action_id` bigint)', $client->getCreateTableSql('wp_actionscheduler_actions'));
    }

    public function testRejectsUnsafeSqlIdentifiersBeforeQuerying(): void
    {
        $wpdb = new FakeWpdb();
        $client = new WpdbClient($wpdb);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier.');

        $client->getCreateTableSql('wp_posts`; DROP TABLE wp_users; --');
    }
}

final class FakeWpdb
{
    public function get_col(string $sql): array
    {
        if ($sql === 'SHOW TABLES') {
            return array('wp_posts', 'wp_options');
        }

        return array();
    }

    public function get_var(string $sql)
    {
        if ($sql === 'SHOW CREATE TABLE `wp_posts`') {
            return 'CREATE TABLE `wp_posts` (`ID` bigint)';
        }

        if ($sql === 'SELECT COUNT(*) FROM `wp_posts`') {
            return '12';
        }

        return null;
    }

    public function get_row(string $sql, string $output): array
    {
        if ($sql === 'SHOW CREATE TABLE `wp_posts`' && $output === 'ARRAY_N') {
            return array('wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint)');
        }

        return array('Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4');
    }

    public function get_results(string $sql, string $output): array
    {
        if ($sql === "SHOW KEYS FROM `wp_posts` WHERE Key_name = 'PRIMARY'") {
            return array(array('Column_name' => 'ID'));
        }

        if ($sql === 'SHOW COLUMNS FROM `wp_posts`') {
            return array(array('Field' => 'ID'), array('Field' => 'post_title'));
        }

        return array(array('ID' => 1));
    }

    public function prepare(string $sql, ...$args): string
    {
        foreach ($args as $arg) {
            $sql = preg_replace('/%d/', (string) $arg, $sql, 1);
            $sql = preg_replace('/%s/', "'" . (string) $arg . "'", $sql, 1);
        }

        return $sql;
    }
}

final class FakeWpdbWithShowKeysRows
{
    public function get_col(string $sql): array
    {
        if ($sql === "SHOW KEYS FROM `wp_options` WHERE Key_name = 'PRIMARY'") {
            return array('wp_options');
        }

        return array();
    }

    public function get_results(string $sql, string $output): array
    {
        if ($sql === "SHOW KEYS FROM `wp_options` WHERE Key_name = 'PRIMARY'") {
            return array(array(
                'Table' => 'wp_options',
                'Non_unique' => '0',
                'Key_name' => 'PRIMARY',
                'Seq_in_index' => '1',
                'Column_name' => 'option_id',
            ));
        }

        return array();
    }
}

final class FakeWpdbWithShowCreateTableRow
{
    public function get_row(string $sql, string $output): array
    {
        if ($sql === 'SHOW CREATE TABLE `wp_actionscheduler_actions`') {
            return array('wp_actionscheduler_actions', 'CREATE TABLE `wp_actionscheduler_actions` (`action_id` bigint)');
        }

        return array();
    }
}
