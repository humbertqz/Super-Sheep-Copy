<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseChunkImporter.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/LegacyZeroDateDefaultDetector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/SqlTableNameRewriter.php';

final class DatabaseChunkImporterTest extends TestCase
{
    public function testSplitsPluginGeneratedSqlStatements(): void
    {
        $sql = "DROP TABLE IF EXISTS `tmp`;\nCREATE TABLE `tmp` (`ID` bigint);\nINSERT INTO `tmp` (`name`) VALUES ('semi; colon');\n";

        $statements = (new \SuperSheepCopyInstaller\DatabaseChunkImporter())->splitStatementsForTest($sql);

        self::assertCount(3, $statements);
        self::assertSame('DROP TABLE IF EXISTS `tmp`', $statements[0]);
        self::assertSame('CREATE TABLE `tmp` (`ID` bigint)', $statements[1]);
        self::assertSame("INSERT INTO `tmp` (`name`) VALUES ('semi; colon')", $statements[2]);
    }

    public function testRejectsOriginalDestinationDropStatement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL statement for staged import.');

        (new \SuperSheepCopyInstaller\DatabaseChunkImporter())->assertSafeStatementForTest(
            'DROP TABLE IF EXISTS `wp_posts`',
            array('ssc_tmp_hash_wp_posts')
        );
    }

    public function testAllowsStagingDropStatement(): void
    {
        (new \SuperSheepCopyInstaller\DatabaseChunkImporter())->assertSafeStatementForTest(
            'DROP TABLE IF EXISTS `ssc_tmp_hash_wp_posts`',
            array('ssc_tmp_hash_wp_posts')
        );

        self::assertTrue(true);
    }

    public function testRejectsUpdateStatement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL statement for staged import.');

        (new \SuperSheepCopyInstaller\DatabaseChunkImporter())->assertSafeStatementForTest(
            "UPDATE `ssc_tmp_hash_wp_posts` SET `post_title` = 'changed'",
            array('ssc_tmp_hash_wp_posts')
        );
    }

    public function testImportsRewrittenChunkStatementsToStagingTables(): void
    {
        $connection = new DatabaseChunkImporterFakeConnection();
        $importer = new class($connection) extends \SuperSheepCopyInstaller\DatabaseChunkImporter {
            private DatabaseChunkImporterFakeConnection $connection;

            public function __construct(DatabaseChunkImporterFakeConnection $connection)
            {
                $this->connection = $connection;
            }

            protected function connect(array $credentials)
            {
                return $this->connection;
            }
        };

        $result = $importer->import(
            array('host' => 'localhost', 'user' => 'root', 'password' => '', 'name' => 'wordpress', 'charset' => 'utf8mb4'),
            array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
            array('wp_posts.part001.sql' => "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (`ID` bigint);\nINSERT INTO `wp_posts` (`post_title`) VALUES ('semi; colon');"),
            array('wp_posts' => 'ssc_tmp_hash_wp_posts'),
            new \SuperSheepCopyInstaller\SqlTableNameRewriter()
        );

        self::assertSame(array(
            'imported' => true,
            'table_count' => 1,
            'chunk_count' => 1,
            'statement_count' => 3,
            'warnings' => array(),
        ), $result);
        self::assertSame(array(
            'DROP TABLE IF EXISTS `ssc_tmp_hash_wp_posts`',
            'CREATE TABLE `ssc_tmp_hash_wp_posts` (`ID` bigint)',
            "INSERT INTO `ssc_tmp_hash_wp_posts` (`post_title`) VALUES ('semi; colon')",
        ), $connection->statements);
        self::assertSame('utf8mb4', $connection->charset);
        self::assertSame(array(
            'set_charset:utf8mb4',
            'query:DROP TABLE IF EXISTS `ssc_tmp_hash_wp_posts`',
            'query:CREATE TABLE `ssc_tmp_hash_wp_posts` (`ID` bigint)',
            "query:INSERT INTO `ssc_tmp_hash_wp_posts` (`post_title`) VALUES ('semi; colon')",
        ), $connection->events);
        self::assertTrue($connection->closed);
    }

    public function testImportStepReturnsCursorWhenBudgetIsExhaustedAndCanResume(): void
    {
        $connection = new DatabaseChunkImporterFakeConnection();
        $connection->query_delay_microseconds = 120000;
        $importer = new class($connection) extends \SuperSheepCopyInstaller\DatabaseChunkImporter {
            private DatabaseChunkImporterFakeConnection $connection;

            public function __construct(DatabaseChunkImporterFakeConnection $connection)
            {
                $this->connection = $connection;
            }

            protected function connect(array $credentials)
            {
                return $this->connection;
            }
        };
        $tables = array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql')));
        $chunks = array('wp_posts.part001.sql' => "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (`ID` bigint);");
        $table_map = array('wp_posts' => 'ssc_tmp_hash_wp_posts');

        $first = $importer->importStep(
            array('host' => 'localhost', 'user' => 'root', 'password' => '', 'name' => 'wordpress', 'charset' => 'utf8mb4'),
            $tables,
            $chunks,
            $table_map,
            new \SuperSheepCopyInstaller\SqlTableNameRewriter(),
            array(),
            0.1
        );
        $connection->query_delay_microseconds = 0;
        $second = $importer->importStep(
            array('host' => 'localhost', 'user' => 'root', 'password' => '', 'name' => 'wordpress', 'charset' => 'utf8mb4'),
            $tables,
            $chunks,
            $table_map,
            new \SuperSheepCopyInstaller\SqlTableNameRewriter(),
            $first['cursor'],
            0.1
        );

        self::assertFalse($first['imported']);
        self::assertTrue($first['in_progress']);
        self::assertSame(array('table_index' => 0, 'chunk_index' => 0, 'statement_index' => 1), $first['cursor']);
        self::assertSame(1, $first['statement_count']);
        self::assertTrue($second['imported']);
        self::assertFalse($second['in_progress']);
        self::assertSame(1, $second['statement_count']);
        self::assertSame(array(
            'DROP TABLE IF EXISTS `ssc_tmp_hash_wp_posts`',
            'CREATE TABLE `ssc_tmp_hash_wp_posts` (`ID` bigint)',
        ), $connection->statements);
    }

    public function testUsesSessionOnlyCompatibilityForLegacyZeroDateSchemas(): void
    {
        $connection = new DatabaseChunkImporterFakeConnection();
        $connection->session_sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE';
        $importer = new class($connection) extends \SuperSheepCopyInstaller\DatabaseChunkImporter {
            private DatabaseChunkImporterFakeConnection $connection;

            public function __construct(DatabaseChunkImporterFakeConnection $connection)
            {
                $this->connection = $connection;
            }

            protected function connect(array $credentials)
            {
                return $this->connection;
            }
        };

        $result = $importer->import(
            array('host' => 'localhost', 'user' => 'root', 'password' => '', 'name' => 'wordpress', 'charset' => 'utf8mb4'),
            array(array('name' => 'wp_actionscheduler_actions', 'chunks' => array('wp_actionscheduler_actions.part001.sql'))),
            array('wp_actionscheduler_actions.part001.sql' => "DROP TABLE IF EXISTS `wp_actionscheduler_actions`;\n"
                . "CREATE TABLE `wp_actionscheduler_actions` (`scheduled_date_gmt` datetime DEFAULT '0000-00-00 00:00:00');"),
            array('wp_actionscheduler_actions' => 'ssc_tmp_hash_wp_actionscheduler_actions'),
            new \SuperSheepCopyInstaller\SqlTableNameRewriter()
        );

        self::assertTrue($result['imported']);
        self::assertSame(array(
            'set_charset:utf8mb4',
            'query:SELECT @@SESSION.sql_mode',
            "query:SET SESSION sql_mode = 'STRICT_TRANS_TABLES'",
            'query:DROP TABLE IF EXISTS `ssc_tmp_hash_wp_actionscheduler_actions`',
            "query:CREATE TABLE `ssc_tmp_hash_wp_actionscheduler_actions` (`scheduled_date_gmt` datetime DEFAULT '0000-00-00 00:00:00')",
        ), $connection->events);
    }

    public function testNormalizesCreateTableCharsetAndCollationToDestination(): void
    {
        $connection = new DatabaseChunkImporterFakeConnection();
        $importer = new class($connection) extends \SuperSheepCopyInstaller\DatabaseChunkImporter {
            private DatabaseChunkImporterFakeConnection $connection;

            public function __construct(DatabaseChunkImporterFakeConnection $connection)
            {
                $this->connection = $connection;
            }

            protected function connect(array $credentials)
            {
                return $this->connection;
            }
        };

        $result = $importer->import(
            array('host' => 'localhost', 'user' => 'root', 'password' => '', 'name' => 'wordpress', 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_ci'),
            array(array(
                'name' => 'wp_posts',
                'charset' => 'latin1',
                'collation' => 'latin1_swedish_ci',
                'chunks' => array('wp_posts.part001.sql'),
            )),
            array(
                'wp_posts.part001.sql' => "DROP TABLE IF EXISTS `wp_posts`;\n"
                    . "CREATE TABLE `wp_posts` (`post_title` varchar(191) CHARACTER SET latin1 COLLATE latin1_swedish_ci) DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;\n"
                    . "INSERT INTO `wp_posts` (`post_title`) VALUES ('latin1_swedish_ci should stay inside values');",
            ),
            array('wp_posts' => 'ssc_tmp_hash_wp_posts'),
            new \SuperSheepCopyInstaller\SqlTableNameRewriter()
        );

        self::assertTrue($result['imported']);
        self::assertSame(
            'CREATE TABLE `ssc_tmp_hash_wp_posts` (`post_title` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $connection->statements[1]
        );
        self::assertSame(
            "INSERT INTO `ssc_tmp_hash_wp_posts` (`post_title`) VALUES ('latin1_swedish_ci should stay inside values')",
            $connection->statements[2]
        );
    }

    public function testRemovesSourceCollationWhenDestinationCollationIsEmpty(): void
    {
        $connection = new DatabaseChunkImporterFakeConnection();
        $importer = new class($connection) extends \SuperSheepCopyInstaller\DatabaseChunkImporter {
            private DatabaseChunkImporterFakeConnection $connection;

            public function __construct(DatabaseChunkImporterFakeConnection $connection)
            {
                $this->connection = $connection;
            }

            protected function connect(array $credentials)
            {
                return $this->connection;
            }
        };

        $result = $importer->import(
            array('host' => 'localhost', 'user' => 'root', 'password' => '', 'name' => 'wordpress', 'charset' => 'utf8mb4', 'collate' => ''),
            array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
            array(
                'wp_posts.part001.sql' => 'CREATE TABLE `wp_posts` (`post_title` varchar(191) CHARACTER SET latin1 COLLATE latin1_swedish_ci) DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;',
            ),
            array('wp_posts' => 'ssc_tmp_hash_wp_posts'),
            new \SuperSheepCopyInstaller\SqlTableNameRewriter()
        );

        self::assertTrue($result['imported']);
        self::assertSame(
            'CREATE TABLE `ssc_tmp_hash_wp_posts` (`post_title` varchar(191) CHARACTER SET utf8mb4) DEFAULT CHARSET=utf8mb4',
            $connection->statements[0]
        );
    }

    public function testReportsFailedStatementDetailsWithoutCredentials(): void
    {
        $connection = new DatabaseChunkImporterFakeConnection();
        $connection->fail_on_statement = 2;
        $connection->errno = 1273;
        $connection->error = 'Unknown collation: utf8mb4_0900_ai_ci';
        $importer = new class($connection) extends \SuperSheepCopyInstaller\DatabaseChunkImporter {
            private DatabaseChunkImporterFakeConnection $connection;

            public function __construct(DatabaseChunkImporterFakeConnection $connection)
            {
                $this->connection = $connection;
            }

            protected function connect(array $credentials)
            {
                return $this->connection;
            }
        };

        $result = $importer->import(
            array('host' => 'localhost', 'user' => 'secret_user', 'password' => 'secret_password', 'name' => 'wordpress'),
            array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
            array('wp_posts.part001.sql' => "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (`ID` bigint) COLLATE=utf8mb4_0900_ai_ci;"),
            array('wp_posts' => 'ssc_tmp_hash_wp_posts'),
            new \SuperSheepCopyInstaller\SqlTableNameRewriter()
        );

        self::assertFalse($result['imported']);
        self::assertSame(1, $result['statement_count']);
        self::assertStringContainsString('Database import statement failed for wp_posts.part001.sql in table wp_posts.', $result['warnings'][0]);
        self::assertStringContainsString('MySQL 1273: Unknown collation: utf8mb4_0900_ai_ci', $result['warnings'][0]);
        self::assertStringContainsString('CREATE TABLE `ssc_tmp_hash_wp_posts`', $result['warnings'][0]);
        self::assertStringNotContainsString('secret_user', $result['warnings'][0]);
        self::assertStringNotContainsString('secret_password', $result['warnings'][0]);
    }

    public function testReportsPacketFailureStatementSizeWithoutCredentials(): void
    {
        $connection = new DatabaseChunkImporterFakeConnection();
        $connection->fail_on_statement = 1;
        $connection->errno = 2006;
        $connection->error = 'MySQL server has gone away';
        $importer = new class($connection) extends \SuperSheepCopyInstaller\DatabaseChunkImporter {
            private DatabaseChunkImporterFakeConnection $connection;

            public function __construct(DatabaseChunkImporterFakeConnection $connection)
            {
                $this->connection = $connection;
            }

            protected function connect(array $credentials)
            {
                return $this->connection;
            }
        };
        $statement = 'CREATE TABLE `wp_posts` (`ID` bigint)';

        $result = $importer->import(
            array('host' => 'localhost', 'user' => 'secret_user', 'password' => 'secret_password', 'name' => 'wordpress'),
            array(array('name' => 'wp_posts', 'chunks' => array('wp_posts.part001.sql'))),
            array('wp_posts.part001.sql' => $statement . ';'),
            array('wp_posts' => 'ssc_tmp_hash_wp_posts'),
            new \SuperSheepCopyInstaller\SqlTableNameRewriter()
        );

        self::assertStringContainsString('The failed statement is ' . strlen('CREATE TABLE `ssc_tmp_hash_wp_posts` (`ID` bigint)') . ' bytes;', $result['warnings'][0]);
        self::assertStringNotContainsString('secret_user', $result['warnings'][0]);
        self::assertStringNotContainsString('secret_password', $result['warnings'][0]);
    }

    public function testFailsWhenTableImportDoesNotCreateExpectedStagingTable(): void
    {
        $connection = new DatabaseChunkImporterFakeConnection();
        $importer = new class($connection) extends \SuperSheepCopyInstaller\DatabaseChunkImporter {
            private DatabaseChunkImporterFakeConnection $connection;

            public function __construct(DatabaseChunkImporterFakeConnection $connection)
            {
                $this->connection = $connection;
            }

            protected function connect(array $credentials)
            {
                return $this->connection;
            }
        };

        $result = $importer->import(
            array('host' => 'localhost', 'user' => 'root', 'password' => '', 'name' => 'wordpress'),
            array(array('name' => 'wp_actionscheduler_actions', 'chunks' => array('wp_actionscheduler_actions.part001.sql'))),
            array('wp_actionscheduler_actions.part001.sql' => "DROP TABLE IF EXISTS `wp_actionscheduler_actions`;"),
            array('wp_actionscheduler_actions' => 'ssc_tmp_hash_wp_actionscheduler_actions'),
            new \SuperSheepCopyInstaller\SqlTableNameRewriter()
        );

        self::assertFalse($result['imported']);
        self::assertSame(array('Database import did not create staging table ssc_tmp_hash_wp_actionscheduler_actions for source table wp_actionscheduler_actions. Check that the backup contains a CREATE TABLE statement for wp_actionscheduler_actions.'), $result['warnings']);
        self::assertTrue($connection->closed);
    }
}

final class DatabaseChunkImporterFakeConnection
{
    public int $connect_errno = 0;

    /** @var list<string> */
    public array $statements = array();

    public bool $closed = false;
    public int $fail_on_statement = 0;
    public int $errno = 0;
    public string $error = '';
    public string $charset = '';
    public int $query_delay_microseconds = 0;
    public string $session_sql_mode = '';

    /** @var list<string> */
    public array $events = array();

    public function set_charset(string $charset): bool
    {
        $this->charset = $charset;
        $this->events[] = 'set_charset:' . $charset;

        return true;
    }

    public function query(string $statement)
    {
        if ($this->query_delay_microseconds > 0) {
            usleep($this->query_delay_microseconds);
        }
        $this->statements[] = $statement;
        $this->events[] = 'query:' . $statement;

        if ($statement === 'SELECT @@SESSION.sql_mode') {
            return new DatabaseChunkImporterFakeResult($this->session_sql_mode);
        }

        if ($this->fail_on_statement === count($this->statements)) {
            return false;
        }

        return true;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class DatabaseChunkImporterFakeResult
{
    private string $sql_mode;

    public function __construct(string $sql_mode)
    {
        $this->sql_mode = $sql_mode;
    }

    /** @return array{0:string} */
    public function fetch_row(): array
    {
        return array($this->sql_mode);
    }
}
