<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\IncrementalArchiveValidator;
use SuperSheepCopy\Shared\Archive\ArchiveValidator;
use SuperSheepCopy\Support\Filesystem;

final class IncrementalArchiveValidatorTest extends TestCase
{
    public function testRejectsMissingDatabaseChunkEvenWhenAllPackagedChecksumsMatch(): void
    {
        $root = sys_get_temp_dir() . '/ssc-incomplete-database-' . bin2hex(random_bytes(4));
        mkdir($root . '/database/chunks', 0777, true);
        mkdir($root . '/files');
        try {
            $files = array(
                'files/index.php' => '<?php',
                'database/tables.json' => '{"tables":[{"name":"wp_posts","chunks":["present.sql","missing.sql"]}]}',
                'database/chunks/present.sql' => 'SELECT 1;',
            );
            $checksums = array();
            foreach ($files as $name => $contents) {
                file_put_contents($root . '/' . $name, $contents);
                $checksums[$name] = hash('sha256', $contents);
            }
            file_put_contents($root . '/manifest.json', '{"project":"Super Sheep Copy"}');
            file_put_contents($root . '/checksums.json', json_encode($checksums, JSON_THROW_ON_ERROR));

            $error = 'Missing database chunk entry: database/chunks/missing.sql';
            $validator = new IncrementalArchiveValidator();
            $payload = $validator->step($root, $validator->prepare($root, array()));
            self::assertTrue($payload['validation_complete']);
            self::assertSame(array($error), $payload['validation_errors']);
            self::assertSame(array($error), (new ArchiveValidator())->validatePackage($root)->errors());
        } finally {
            Filesystem::removeDirectory($root);
        }
    }

    public function testFinalValidationHandlesLargeFileListsAndRejectsExtraChecksums(): void
    {
        $root = sys_get_temp_dir() . '/ssc-incremental-validator-' . bin2hex(random_bytes(4));
        mkdir($root . '/files', 0777, true);
        file_put_contents($root . '/files/last.txt', 'last');
        try {
            $entries = array();
            $checksums = array();
            for ($index = 0; $index < 20000; $index++) {
                $entry = 'files/file-' . $index . '.txt';
                $entries[] = $entry;
                $checksums[$entry] = hash('sha256', 'previously validated');
            }
            $entries[] = 'files/last.txt';
            $checksums['files/last.txt'] = hash('sha256', 'last');
            $checksums['files/absent.txt'] = hash('sha256', 'absent');
            $checksums[123] = hash('sha256', 'invalid key');
            $payload = (new IncrementalArchiveValidator())->step($root, array(
                'validation_entries' => $entries,
                'validation_checksums' => $checksums,
                'validation_entry_index' => count($entries) - 1,
                'validation_errors' => array(),
            ));

            self::assertTrue($payload['validation_complete']);
            self::assertSame(count($entries), $payload['validation_entry_index']);
            self::assertSame(array(
                'Unexpected checksum for archive entry: files/absent.txt',
                'Unexpected checksum for archive entry: 123',
            ), $payload['validation_errors']);
        } finally {
            Filesystem::removeDirectory($root);
        }
    }
}
