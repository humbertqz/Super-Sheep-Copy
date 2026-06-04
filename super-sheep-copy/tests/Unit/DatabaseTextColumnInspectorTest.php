<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTextColumnInspector.php';

final class DatabaseTextColumnInspectorTest extends TestCase
{
    public function testReturnsTextColumnsAndPrimaryKey(): void
    {
        $connection = new FakeTextColumnMysqli(array(
            'wp_posts' => array(
                array('Field' => 'ID', 'Type' => 'bigint(20) unsigned', 'Key' => 'PRI'),
                array('Field' => 'post_title', 'Type' => 'varchar(255)', 'Key' => ''),
                array('Field' => 'post_content', 'Type' => 'longtext', 'Key' => ''),
                array('Field' => 'post_date', 'Type' => 'datetime', 'Key' => ''),
                array('Field' => 'binary_payload', 'Type' => 'blob', 'Key' => ''),
            ),
        ));
        $inspector = new \SuperSheepCopyInstaller\DatabaseTextColumnInspector($connection);

        $result = $inspector->inspect(array('wp_posts'));

        self::assertTrue($result['valid']);
        self::assertSame(array(), $result['warnings']);
        self::assertSame(array('post_title', 'post_content'), $result['tables']['wp_posts']['columns']);
        self::assertSame('ID', $result['tables']['wp_posts']['primary_key']);
    }

    public function testIncludesJsonAndTinyTextButExcludesBinaryTypes(): void
    {
        $connection = new FakeTextColumnMysqli(array(
            'wp_options' => array(
                array('Field' => 'option_id', 'Type' => 'bigint(20)', 'Key' => 'PRI'),
                array('Field' => 'option_value', 'Type' => 'longtext', 'Key' => ''),
                array('Field' => 'settings_json', 'Type' => 'json', 'Key' => ''),
                array('Field' => 'short_note', 'Type' => 'tinytext', 'Key' => ''),
                array('Field' => 'raw_file', 'Type' => 'longblob', 'Key' => ''),
            ),
        ));

        $result = (new \SuperSheepCopyInstaller\DatabaseTextColumnInspector($connection))->inspect(array('wp_options'));

        self::assertTrue($result['valid']);
        self::assertSame(array('option_value', 'settings_json', 'short_note'), $result['tables']['wp_options']['columns']);
    }

    public function testRejectsInvalidTableIdentifier(): void
    {
        $connection = new FakeTextColumnMysqli(array());

        $result = (new \SuperSheepCopyInstaller\DatabaseTextColumnInspector($connection))->inspect(array('wp-posts'));

        self::assertFalse($result['valid']);
        self::assertSame(array('Invalid database table identifier: wp-posts'), $result['warnings']);
        self::assertSame(array(), $connection->queries);
    }

    public function testReportsColumnInspectionFailure(): void
    {
        $connection = new FakeTextColumnMysqli(array(), true);

        $result = (new \SuperSheepCopyInstaller\DatabaseTextColumnInspector($connection))->inspect(array('wp_posts'));

        self::assertFalse($result['valid']);
        self::assertSame(array('Unable to inspect columns for table: wp_posts'), $result['warnings']);
    }
}

final class FakeTextColumnMysqli
{
    /** @var array<string,list<array<string,string>>> */
    private array $columns;
    private bool $query_fails;
    /** @var list<string> */
    public array $queries = array();
    public int $close_count = 0;

    /**
     * @param array<string,list<array<string,string>>> $columns
     */
    public function __construct(array $columns, bool $query_fails = false)
    {
        $this->columns = $columns;
        $this->query_fails = $query_fails;
    }

    public function query(string $sql)
    {
        $this->queries[] = $sql;
        if ($this->query_fails) {
            return false;
        }

        if (preg_match('/^SHOW COLUMNS FROM `([^`]+)`$/', $sql, $matches) !== 1) {
            return false;
        }

        return new FakeTextColumnResult($this->columns[$matches[1]] ?? array());
    }

    public function close(): void
    {
        ++$this->close_count;
    }
}

final class FakeTextColumnResult
{
    /** @var list<array<string,string>> */
    private array $rows;

    /**
     * @param list<array<string,string>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function fetch_assoc()
    {
        return array_shift($this->rows);
    }
}
