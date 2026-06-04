<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTableInspector.php';

final class DatabaseTableInspectorTest extends TestCase
{
    public function testFindsMissingStagingTables(): void
    {
        $inspector = new FakeDatabaseTableInspector(array('ssc_tmp_abcd_wp_posts' => true, 'ssc_tmp_abcd_wp_options' => false));

        $result = $inspector->verifyTables(array('wp_posts' => 'ssc_tmp_abcd_wp_posts', 'wp_options' => 'ssc_tmp_abcd_wp_options'));

        self::assertFalse($result['valid']);
        self::assertSame(array('Missing staging table: ssc_tmp_abcd_wp_options'), $result['warnings']);
    }

    public function testAcceptsExistingStagingTables(): void
    {
        $inspector = new FakeDatabaseTableInspector(array('ssc_tmp_abcd_wp_posts' => true));

        $result = $inspector->verifyTables(array('wp_posts' => 'ssc_tmp_abcd_wp_posts'));

        self::assertTrue($result['valid']);
        self::assertSame(array(), $result['warnings']);
    }

    public function testUsesCredentialsWhenNoConnectionWasInjected(): void
    {
        $inspector = new FakeConnectingDatabaseTableInspector(array('ssc_tmp_abcd_wp_posts' => true));

        $result = $inspector->verifyTables(
            array('wp_posts' => 'ssc_tmp_abcd_wp_posts'),
            array('complete' => true, 'host' => 'localhost')
        );

        self::assertTrue($result['valid']);
        self::assertSame(array(), $result['warnings']);
        self::assertSame(1, $inspector->connection()->close_count);
    }

    public function testRequiresExactTableMatchWhenNamesContainLikeWildcards(): void
    {
        $inspector = new FakeConnectingDatabaseTableInspector(array('sscXtmpXabcdXwpXposts' => true));

        $result = $inspector->verifyTables(
            array('wp_posts' => 'ssc_tmp_abcd_wp_posts'),
            array('complete' => true, 'host' => 'localhost')
        );

        self::assertFalse($result['valid']);
        self::assertSame(array('Missing staging table: ssc_tmp_abcd_wp_posts'), $result['warnings']);
        self::assertStringContainsString('INFORMATION_SCHEMA.TABLES', $inspector->connection()->queries[0]);
        self::assertStringContainsString("TABLE_NAME = 'ssc_tmp_abcd_wp_posts'", $inspector->connection()->queries[0]);
    }

    public function testVerifyTablesReportsInspectionFailureSeparatelyFromMissingTable(): void
    {
        $connection = new FakeTableInspectorMysqli(array('ssc_tmp_abcd_wp_posts' => true), true);
        $inspector = new \SuperSheepCopyInstaller\DatabaseTableInspector($connection);

        $result = $inspector->verifyTables(array('wp_posts' => 'ssc_tmp_abcd_wp_posts'));

        self::assertFalse($result['valid']);
        self::assertSame(array('Unable to inspect staging table: ssc_tmp_abcd_wp_posts'), $result['warnings']);
    }

    public function testDoesNotCloseInjectedConnection(): void
    {
        $connection = new FakeTableInspectorMysqli(array('ssc_tmp_abcd_wp_posts' => true));
        $inspector = new \SuperSheepCopyInstaller\DatabaseTableInspector($connection);

        $result = $inspector->verifyTables(array('wp_posts' => 'ssc_tmp_abcd_wp_posts'));

        self::assertTrue($result['valid']);
        self::assertSame(0, $connection->close_count);
    }

    public function testExistingTablesReportsQueryFailure(): void
    {
        $connection = new FakeTableInspectorMysqli(array('wp_posts' => true), true);
        $inspector = new \SuperSheepCopyInstaller\DatabaseTableInspector($connection);

        $result = $inspector->existingTables(array('wp_posts'));

        self::assertFalse($result['valid']);
        self::assertSame(array(), $result['tables']);
        self::assertSame(array('Unable to inspect destination table: wp_posts'), $result['warnings']);
    }
}

final class FakeDatabaseTableInspector extends \SuperSheepCopyInstaller\DatabaseTableInspector
{
    /** @var array<string,bool> */
    private array $tables;

    /**
     * @param array<string,bool> $tables
     */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    protected function inspectTable(string $table, $connection = null): ?bool
    {
        unset($connection);

        return isset($this->tables[$table]) && $this->tables[$table];
    }
}

final class FakeConnectingDatabaseTableInspector extends \SuperSheepCopyInstaller\DatabaseTableInspector
{
    /** @var array<string,bool> */
    private array $tables;
    private FakeTableInspectorMysqli $connection;

    /**
     * @param array<string,bool> $tables
     */
    public function __construct(array $tables)
    {
        parent::__construct(null);
        $this->tables = $tables;
        $this->connection = new FakeTableInspectorMysqli($tables);
    }

    protected function connect(array $credentials)
    {
        unset($credentials);

        return $this->connection;
    }

    public function connection(): FakeTableInspectorMysqli
    {
        return $this->connection;
    }
}

final class FakeTableInspectorMysqli
{
    /** @var array<string,bool> */
    private array $tables;
    private bool $query_fails;
    /** @var list<string> */
    public array $queries = array();
    public int $close_count = 0;

    /**
     * @param array<string,bool> $tables
     */
    public function __construct(array $tables, bool $query_fails = false)
    {
        $this->tables = $tables;
        $this->query_fails = $query_fails;
    }

    public function real_escape_string(string $value): string
    {
        return addslashes($value);
    }

    public function query(string $sql)
    {
        $this->queries[] = $sql;
        if ($this->query_fails) {
            return false;
        }

        $table_name = '';
        $prefix = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '";
        $start = strpos($sql, $prefix);
        $end = strpos($sql, "' LIMIT 1", strlen($prefix));
        if ($start === 0 && $end !== false) {
            $table_name = substr($sql, strlen($prefix), $end - strlen($prefix));
            $table_name = str_replace("\\'", "'", $table_name);
        }

        foreach ($this->tables as $table => $exists) {
            if ($exists && $table === $table_name) {
                return new FakeTableInspectorResult($table);
            }
        }

        return new FakeTableInspectorResult(null);
    }

    public function close(): void
    {
        ++$this->close_count;
    }
}

final class FakeTableInspectorResult
{
    private ?string $table;

    public function __construct(?string $table)
    {
        $this->table = $table;
    }

    public function fetch_row()
    {
        return $this->table === null ? null : array($this->table);
    }
}
