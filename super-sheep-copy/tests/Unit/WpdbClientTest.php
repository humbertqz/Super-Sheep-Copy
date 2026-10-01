<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\WpdbClient;

final class WpdbClientTest extends TestCase
{
    public function testDoesNotTreatQueryErrorsAsEmptyResults(): void
    {
        $wpdb = new class {
            public string $last_error = '';
            public function get_results(string $sql, string $output) {
                $this->last_error = 'SELECT command denied';
                return null;
            }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database export query failed: SELECT command denied');
        (new WpdbClient($wpdb))->getRows('SELECT * FROM `wp_posts`');
    }

    public function testOnlyUsesSingleSafeIntegerKeysAndSkipsGeneratedColumns(): void
    {
        $wpdb = new class {
            public array $keys = array(array('Column_name' => 'id'));
            public string $type = 'bigint';
            public string $maximum = '18446744073709551615';
            public function get_var(string $sql): string {
                return $this->maximum;
            }
            public function get_results(string $sql, string $output): array {
                return strpos($sql, 'SHOW KEYS') === 0 ? $this->keys : array(
                    array('Field' => 'id', 'Type' => $this->type),
                    array('Field' => 'calculated', 'Extra' => 'STORED GENERATED'),
                    array('Field' => 'virtual', 'Extra' => 'VIRTUAL GENERATED'),
                    array('Field' => 'created', 'Extra' => 'DEFAULT_GENERATED'),
                );
            }
        };
        $client = new WpdbClient($wpdb);
        self::assertSame('id', $client->getPrimaryKey('wp_custom'));
        foreach (array('varchar(36)', 'decimal(20,0)', 'bigint unsigned') as $type) {
            $wpdb->type = $type;
            self::assertNull($client->getPrimaryKey('wp_custom'));
        }
        $wpdb->maximum = '42';
        self::assertSame('id', $client->getPrimaryKey('wp_custom'));
        $wpdb->type = 'int';
        $wpdb->keys[] = array('Column_name' => 'other');
        self::assertNull($client->getPrimaryKey('wp_custom'));
        self::assertSame(array('id', 'created'), $client->getColumns('wp_custom'));
    }

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

    public function testQueriesHyphenatedTableIdentifier(): void
    {
        $client = new WpdbClient(new FakeWpdb());

        self::assertSame(
            'CREATE TABLE `wp-play-large` (`play-large` bigint)',
            $client->getCreateTableSql('wp-play-large')
        );
        self::assertSame(array('play-large'), $client->getColumns('wp-play-large'));
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

        if ($sql === 'SHOW CREATE TABLE `wp-play-large`' && $output === 'ARRAY_N') {
            return array('wp-play-large', 'CREATE TABLE `wp-play-large` (`play-large` bigint)');
        }

        return array('Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4');
    }

    public function get_results(string $sql, string $output): array
    {
        if ($sql === "SHOW KEYS FROM `wp_posts` WHERE Key_name = 'PRIMARY'") {
            return array(array('Column_name' => 'ID'));
        }

        if ($sql === 'SHOW COLUMNS FROM `wp_posts`') {
            return array(array('Field' => 'ID', 'Type' => 'bigint'), array('Field' => 'post_title', 'Type' => 'text'));
        }

        if ($sql === 'SHOW COLUMNS FROM `wp-play-large`') {
            return array(array('Field' => 'play-large'));
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

        if ($sql === 'SHOW COLUMNS FROM `wp_options`') {
            return array(array('Field' => 'option_id', 'Type' => 'bigint'));
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
