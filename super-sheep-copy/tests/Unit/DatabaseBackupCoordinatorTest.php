<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupProgressReporterInterface;
use SuperSheepCopy\Backup\Database\ChunkPlanner;
use SuperSheepCopy\Backup\Database\DatabaseBackupCoordinator;
use SuperSheepCopy\Backup\Database\DatabaseExportManifestBuilder;
use SuperSheepCopy\Backup\Database\DatabaseExportWriter;
use SuperSheepCopy\Backup\Database\SqlDumpFormatter;
use SuperSheepCopy\Backup\Database\TableSelector;
use SuperSheepCopy\Backup\Database\WpdbClientInterface;
use SuperSheepCopy\Backup\Database\WpdbDatabaseExporter;

final class DatabaseBackupCoordinatorTest extends TestCase
{
    public function testPrimaryKeyExportContinuesPastAnUnderestimatedRowCount(): void
    {
        $this->coordinator(new UnderestimatedRowCountClient(), null)->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 2);

        self::assertFileExists($this->root . '/database/chunks/wp_posts.part002.sql');
        $manifest = json_decode((string) file_get_contents($this->root . '/database/tables.json'), true);
        self::assertSame(array('wp_posts.part001.sql', 'wp_posts.part002.sql'), $manifest['tables'][0]['chunks']);
    }
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-db-coordinator-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExportsPrimaryKeyTableInMultipleChunks(): void
    {
        $this->coordinator(new CoordinatorFakeClient())->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 2);

        self::assertStringContainsString('CREATE TABLE `wp_posts` (`ID` bigint);', (string) file_get_contents($this->root . '/database/chunks/wp_posts.part001.sql'));
        self::assertStringContainsString("INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES\n(1, 'Hello'),\n(2, 'World');", (string) file_get_contents($this->root . '/database/chunks/wp_posts.part001.sql'));
        self::assertSame("INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES\n(3, 'Again');\n", file_get_contents($this->root . '/database/chunks/wp_posts.part002.sql'));

        $manifest = json_decode((string) file_get_contents($this->root . '/database/tables.json'), true);
        self::assertSame(array('wp_posts.part001.sql', 'wp_posts.part002.sql'), $manifest['tables'][0]['chunks']);
    }

    public function testExportsEmptyTableWithSchemaOnlyChunk(): void
    {
        $this->coordinator(new EmptyTableFakeClient())->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 100);

        self::assertSame("DROP TABLE IF EXISTS `wp_empty`;\nCREATE TABLE `wp_empty` (`ID` bigint);\n", file_get_contents($this->root . '/database/chunks/wp_empty.part001.sql'));
    }

    public function testExportsOffsetPaginatedTable(): void
    {
        $this->coordinator(new OffsetFakeClient())->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 1);

        self::assertStringContainsString("INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES\n('siteurl', 'https://website.com');", (string) file_get_contents($this->root . '/database/chunks/wp_options.part001.sql'));
        self::assertSame("INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES\n('home', 'https://website.com');\n", file_get_contents($this->root . '/database/chunks/wp_options.part002.sql'));
    }

    public function testReportsTableAndChunkProgressMarkers(): void
    {
        $reporter = new CoordinatorProgressReporter();

        $this->coordinator(new CoordinatorFakeClient(), $reporter)->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 2, 'backup-123');

        self::assertSame(array(
            'table_started',
            'chunk_started',
            'chunk_finished',
            'chunk_started',
            'chunk_finished',
            'table_finished',
        ), $reporter->steps());

        $reports = $reporter->reports();
        self::assertSame('wp_posts', $reports[0]['payload']['table']);
        self::assertSame('wp_posts', $reports[1]['payload']['table']);
        self::assertSame(1, $reports[1]['payload']['chunk']);
        self::assertSame(2, $reports[1]['payload']['chunk_total']);
        self::assertSame(2, $reports[3]['payload']['chunk']);
        self::assertSame(2, $reports[3]['payload']['chunk_total']);
        self::assertSame('Exporting table wp_posts', $reports[0]['payload']['message']);
        self::assertSame('Exporting chunk 1 of 2 for table wp_posts', $reports[1]['payload']['message']);
        self::assertSame('Finished chunk 1 of 2 for table wp_posts', $reports[2]['payload']['message']);
        self::assertSame('Finished exporting table wp_posts', $reports[5]['payload']['message']);
    }

    public function testEmptySelectionWritesEmptyManifest(): void
    {
        $this->coordinator(new NoTablesFakeClient())->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 100);

        $manifest = json_decode((string) file_get_contents($this->root . '/database/tables.json'), true);
        self::assertSame(0, $manifest['table_count']);
        self::assertSame(array(), $manifest['tables']);
    }

    public function testRejectsInvalidChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be greater than zero.');

        $this->coordinator(new CoordinatorFakeClient())->export($this->root, 'wp_', TableSelector::MODE_PREFIXED, 0);
    }

    private function coordinator(WpdbClientInterface $client, ?BackupProgressReporterInterface $reporter = null): DatabaseBackupCoordinator
    {
        return new DatabaseBackupCoordinator(
            new WpdbDatabaseExporter($client, new TableSelector()),
            new ChunkPlanner(),
            new SqlDumpFormatter(),
            new DatabaseExportWriter(new DatabaseExportManifestBuilder()),
            $reporter
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
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

final class CoordinatorProgressReporter implements BackupProgressReporterInterface
{
    /** @var array<int,array{job_id:string,state:string,payload:array<string,mixed>}> */
    private array $reports = array();

    /**
     * @param array<string, mixed> $payload
     */
    public function report(string $job_id, string $state, array $payload): void
    {
        $this->reports[] = array('job_id' => $job_id, 'state' => $state, 'payload' => $payload);
    }

    /**
     * @return string[]
     */
    public function steps(): array
    {
        return array_map(static function (array $report): string {
            return $report['payload']['step'];
        }, $this->reports);
    }

    /**
     * @return array<int,array{job_id:string,state:string,payload:array<string,mixed>}>
     */
    public function reports(): array
    {
        return $this->reports;
    }
}

class CoordinatorFakeClient implements WpdbClientInterface
{
    public function getTables(): array
    {
        return array('wp_posts');
    }

    public function getCreateTableSql(string $table): string
    {
        return 'CREATE TABLE `wp_posts` (`ID` bigint)';
    }

    public function getPrimaryKey(string $table): ?string
    {
        return 'ID';
    }

    public function getRowCount(string $table): int
    {
        return 3;
    }

    public function getTableStatus(string $table): array
    {
        return array();
    }

    public function getColumns(string $table): array
    {
        return array('ID', 'post_title');
    }

    public function getRows(string $sql): array
    {
        if (strpos($sql, 'WHERE `ID` > 2') !== false) {
            return array(array('ID' => 3, 'post_title' => 'Again'));
        }

        return array(array('ID' => 1, 'post_title' => 'Hello'), array('ID' => 2, 'post_title' => 'World'));
    }

    public function prepare(string $sql, array $args): string
    {
        foreach ($args as $arg) {
            $sql = preg_replace('/%d/', (string) $arg, $sql, 1);
        }
        return $sql;
    }
}

final class UnderestimatedRowCountClient extends CoordinatorFakeClient
{
    public function getRowCount(string $table): int
    {
        return 1;
    }
}

final class EmptyTableFakeClient extends CoordinatorFakeClient
{
    public function getTables(): array
    {
        return array('wp_empty');
    }

    public function getCreateTableSql(string $table): string
    {
        return 'CREATE TABLE `wp_empty` (`ID` bigint)';
    }

    public function getRowCount(string $table): int
    {
        return 0;
    }

    public function getColumns(string $table): array
    {
        return array('ID');
    }

    public function getRows(string $sql): array
    {
        return array();
    }
}

final class OffsetFakeClient extends CoordinatorFakeClient
{
    public function getTables(): array
    {
        return array('wp_options');
    }

    public function getCreateTableSql(string $table): string
    {
        return 'CREATE TABLE `wp_options` (`option_name` varchar(191))';
    }

    public function getPrimaryKey(string $table): ?string
    {
        return null;
    }

    public function getRowCount(string $table): int
    {
        return 2;
    }

    public function getColumns(string $table): array
    {
        return array('option_name', 'option_value');
    }

    public function getRows(string $sql): array
    {
        if (strpos($sql, 'OFFSET 1') !== false) {
            return array(array('option_name' => 'home', 'option_value' => 'https://website.com'));
        }

        return array(array('option_name' => 'siteurl', 'option_value' => 'https://website.com'));
    }
}

final class NoTablesFakeClient extends CoordinatorFakeClient
{
    public function getTables(): array
    {
        return array();
    }
}
