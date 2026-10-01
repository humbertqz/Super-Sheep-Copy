<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\IncrementalArchiveValidator;
use SuperSheepCopy\Support\Filesystem;

final class IncrementalArchiveValidatorTest extends TestCase
{
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
