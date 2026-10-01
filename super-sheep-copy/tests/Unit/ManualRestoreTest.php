<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\ArchiveWriter;
use SuperSheepCopy\Backup\Manifest;
use SuperSheepCopy\Backup\ScannedFile;
use ZipArchive;

final class ManualRestoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-manual-restore-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/source/database/chunks', 0777, true);
        file_put_contents($this->root . '/source/index.php', '<?php');
        $tables = array('tables' => array(array('name' => 'wp_custom', 'chunks' => array('wp_custom.part001.sql', 'wp_custom.part999.sql', 'wp_custom.part1000.sql'))));
        file_put_contents($this->root . '/source/database/tables.json', json_encode($tables));
        file_put_contents($this->root . '/source/database/chunks/wp_custom.part001.sql', "CREATE TABLE `wp_custom` (`id` INT);\n");
        file_put_contents($this->root . '/source/database/chunks/wp_custom.part999.sql', "INSERT INTO `wp_custom` VALUES (999);\n");
        file_put_contents($this->root . '/source/database/chunks/wp_custom.part1000.sql', "INSERT INTO `wp_custom` VALUES (1000);\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExtractedZipBuildsStandardSqlWithoutThePlugin(): void
    {
        $this->extractBackup();
        self::assertFileExists($this->root . '/extracted/MANUAL-RESTORE.md');
        self::assertSame(0, $this->buildSql());
        $sql = file_get_contents($this->root . '/extracted/database.sql');
        self::assertStringContainsString('SET NAMES utf8mb4;', $sql);
        self::assertStringContainsString("SET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO';", $sql);
        self::assertStringContainsString('SET SESSION FOREIGN_KEY_CHECKS=0;', $sql);
        self::assertLessThan(strpos($sql, 'VALUES (1000)'), strpos($sql, 'VALUES (999)'));
        self::assertStringContainsString('SET SESSION SQL_MODE=@SSC_OLD_SQL_MODE;', $sql);
        self::assertSame(1, $this->buildSql());
        self::assertSame($sql, file_get_contents($this->root . '/extracted/database.sql'));
    }

    public function testCorruptionFailsBeforePublishingSql(): void
    {
        $this->extractBackup();
        file_put_contents($this->root . '/extracted/database/chunks/wp_custom.part999.sql', 'corrupted');
        self::assertSame(1, $this->buildSql());
        self::assertFileDoesNotExist($this->root . '/extracted/database.sql');
    }

    public function testMissingPlannedChunkFailsEvenIfOtherFilesVerify(): void
    {
        $this->extractBackup();
        $path = $this->root . '/extracted/checksums.json';
        $checksums = json_decode(file_get_contents($path), true);
        unset($checksums['database/chunks/wp_custom.part1000.sql']);
        file_put_contents($path, json_encode($checksums));
        self::assertSame(1, $this->buildSql());
        self::assertFileDoesNotExist($this->root . '/extracted/database.sql');
    }

    private function extractBackup(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }
        $files = array(new ScannedFile($this->root . '/source/index.php', 'index.php', 5, false));
        $database_files = array();
        $checksums = array('files/index.php' => hash_file('sha256', $this->root . '/source/index.php'));
        foreach (array('tables.json', 'chunks/wp_custom.part001.sql', 'chunks/wp_custom.part999.sql', 'chunks/wp_custom.part1000.sql') as $entry) {
            $source = $this->root . '/source/database/' . $entry;
            $database_files[] = new ScannedFile($source, $entry, filesize($source), false);
            $checksums['database/' . $entry] = hash_file('sha256', $source);
        }
        (new ArchiveWriter())->write($this->root . '/backup.zip', new Manifest(array('project' => 'Super Sheep Copy', 'source_database_charset' => 'utf8mb4')), $files, $database_files, $checksums, 'test');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->root . '/backup.zip'));
        self::assertTrue($zip->extractTo($this->root . '/extracted'));
        $zip->close();
    }

    private function buildSql(): int
    {
        $process = proc_open(array(PHP_BINARY, $this->root . '/extracted/build-database.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertContains($status, array(0, 1), $stdout . $stderr);
        return $status;
    }

    private function removeDirectory(string $path): void
    {
        foreach (array_diff(scandir($path), array('.', '..')) as $name) {
            $child = $path . '/' . $name;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
