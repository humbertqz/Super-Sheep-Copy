<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/WpConfigReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseTextColumnInspector.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseUrlReplacementExecutor.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseUrlReplacementManager.php';

final class DatabaseUrlReplacementManagerTest extends TestCase
{
    private string $root_dir;
    private string $engine_dir;

    protected function setUp(): void
    {
        $this->root_dir = sys_get_temp_dir() . '/ssc-url-manager-' . bin2hex(random_bytes(4));
        $this->engine_dir = $this->root_dir . '/ssc-restore-engine';
        mkdir($this->engine_dir, 0777, true);
        file_put_contents($this->root_dir . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'db');\n"
            . "define('DB_USER', 'user');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");
        file_put_contents($this->engine_dir . '/config.php', "<?php\nreturn array();\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root_dir);
    }

    public function testRejectsMissingTableSwap(): void
    {
        $result = $this->manager()->replace($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_url_replacement_plan' => $this->plan(),
        ));

        self::assertFalse($result['completed']);
        self::assertSame(array('Database tables must be swapped before URL replacement.'), $result['warnings']);
    }

    public function testRejectsMissingUrlPlan(): void
    {
        $result = $this->manager()->replace($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_tables_swapped' => true,
        ));

        self::assertFalse($result['completed']);
        self::assertSame(array('URL replacement plan is missing.'), $result['warnings']);
    }

    public function testRecordsCompletionMetadata(): void
    {
        $manager = $this->manager(
            new FakeUrlManagerColumnInspector(array(
                'wp_posts' => array('columns' => array('post_content'), 'primary_key' => 'ID'),
            )),
            new FakeUrlManagerExecutor(array(
                'completed' => true,
                'table_count' => 1,
                'scanned_rows' => 2,
                'changed_rows' => 1,
                'scanned_cells' => 2,
                'changed_cells' => 1,
                'replacement_count' => 3,
                'warnings' => array(),
            ))
        );

        $result = $manager->replace($this->engine_dir, array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_tables_swapped' => true,
            'database_url_replacement_plan' => $this->plan(),
            'locked' => true,
        ));

        self::assertTrue($result['completed']);
        $config = require $this->engine_dir . '/config.php';
        self::assertTrue($config['database_url_replacement_completed']);
        self::assertTrue($config['locked']);
        self::assertSame(1, $config['database_url_replacement_table_count']);
        self::assertSame(2, $config['database_url_replacement_scanned_rows']);
        self::assertSame(1, $config['database_url_replacement_changed_rows']);
        self::assertSame(3, $config['database_url_replacement_count']);
    }

    private function manager(?FakeUrlManagerColumnInspector $inspector = null, ?FakeUrlManagerExecutor $executor = null): \SuperSheepCopyInstaller\DatabaseUrlReplacementManager
    {
        return new \SuperSheepCopyInstaller\DatabaseUrlReplacementManager(
            new \SuperSheepCopyInstaller\WpConfigReader(),
            new FakeUrlManagerConnectionTester(),
            $inspector === null ? new FakeUrlManagerColumnInspector(array()) : $inspector,
            $executor === null ? new FakeUrlManagerExecutor(array()) : $executor
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function plan(): array
    {
        return array(
            'status' => 'planned',
            'source_urls' => array('https://source.example'),
            'destination_url' => 'https://destination.example',
            'tables' => array('wp_posts'),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }
            unlink($child);
        }
        rmdir($path);
    }
}

final class FakeUrlManagerConnectionTester extends \SuperSheepCopyInstaller\DatabaseConnectionTester
{
    public function test(array $credentials): array
    {
        return array('connected' => true, 'status' => 'ok', 'message' => '', 'database' => 'db', 'host' => 'localhost');
    }
}

final class FakeUrlManagerColumnInspector extends \SuperSheepCopyInstaller\DatabaseTextColumnInspector
{
    /** @var array<string,array{columns:list<string>,primary_key:string}> */
    private array $tables;

    /**
     * @param array<string,array{columns:list<string>,primary_key:string}> $tables
     */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function inspect(array $tables, array $credentials = array()): array
    {
        unset($credentials);
        $metadata = array();
        foreach ($tables as $table) {
            $metadata[$table] = $this->tables[$table] ?? array('columns' => array(), 'primary_key' => '');
        }

        return array('valid' => true, 'tables' => $metadata, 'warnings' => array());
    }
}

final class FakeUrlManagerExecutor extends \SuperSheepCopyInstaller\DatabaseUrlReplacementExecutor
{
    /** @var array<string,mixed> */
    private array $result;

    /**
     * @param array<string,mixed> $result
     */
    public function __construct(array $result)
    {
        $this->result = $result;
    }

    public function execute(array $credentials, array $plan, array $tables, \SuperSheepCopy\Shared\Urls\StructuredValueReplacer $replacer): array
    {
        unset($credentials, $plan, $tables, $replacer);

        return $this->result === array()
            ? array('completed' => true, 'table_count' => 0, 'scanned_rows' => 0, 'changed_rows' => 0, 'scanned_cells' => 0, 'changed_cells' => 0, 'replacement_count' => 0, 'warnings' => array())
            : $this->result;
    }
}
