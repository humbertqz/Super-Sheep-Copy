<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class DatabaseImportManifestReader
{
    /**
     * @return array{valid:bool,tables:array<int,array{name:string,charset:string,collation:string,chunks:array<int,string>}>,chunks:array<string,string>,warnings:array<int,string>}
     */
    public function read(string $archive_path): array
    {
        if (!is_readable($archive_path)) {
            return $this->result(false, array(), array(), array('Database import archive is not readable.'));
        }

        try {
            $reader = (new PackageReaderFactory())->create($archive_path);
        } catch (\RuntimeException $exception) {
            return $this->result(false, array(), array(), array('Database import archive could not be opened.'));
        }

        $json = $reader->read('database/tables.json');
        if (!is_string($json)) {
            return $this->result(false, array(), array(), array('Missing database/tables.json.'));
        }

        $manifest = json_decode($json, true);
        if (!is_array($manifest) || !isset($manifest['tables']) || !is_array($manifest['tables'])) {
            return $this->result(false, array(), array(), array('Invalid database/tables.json.'));
        }

        $tables = array();
        $chunks = array();
        $warnings = array();

        foreach ($manifest['tables'] as $table) {
            if (
                !is_array($table)
                || !isset($table['name'], $table['chunks'])
                || !is_string($table['name'])
                || $table['name'] === ''
                || !is_array($table['chunks'])
            ) {
                $warnings[] = 'Invalid database table manifest entry.';
                continue;
            }

            $table_chunks = array();
            if ($table['chunks'] === array()) {
                $warnings[] = 'Database table has no chunks: ' . $table['name'];
            }

            foreach ($table['chunks'] as $chunk) {
                if (!is_string($chunk) || !$this->isSafeChunkName($chunk)) {
                    $warnings[] = 'Unsafe database chunk file name: ' . (is_scalar($chunk) ? (string) $chunk : '');
                    continue;
                }

                $entry = 'database/chunks/' . $chunk;
                $sql = $reader->read($entry);
                if (!is_string($sql)) {
                    $warnings[] = 'Missing database chunk entry: ' . $entry;
                    continue;
                }

                $table_chunks[] = $chunk;
                $chunks[$chunk] = $sql;
            }

            $tables[] = array(
                'name' => $table['name'],
                'charset' => isset($table['charset']) && is_string($table['charset']) ? $table['charset'] : '',
                'collation' => isset($table['collation']) && is_string($table['collation']) ? $table['collation'] : '',
                'chunks' => $table_chunks,
            );
        }

        return $this->result($warnings === array(), $tables, $chunks, $warnings);
    }

    private function isSafeChunkName(string $name): bool
    {
        return basename($name) === $name && preg_match('/^[A-Za-z0-9_.-]+\.sql$/', $name) === 1;
    }

    /**
     * @param array<int,array{name:string,charset:string,collation:string,chunks:array<int,string>}> $tables
     * @param array<string,string> $chunks
     * @param array<int,string> $warnings
     * @return array{valid:bool,tables:array<int,array{name:string,charset:string,collation:string,chunks:array<int,string>}>,chunks:array<string,string>,warnings:array<int,string>}
     */
    private function result(bool $valid, array $tables, array $chunks, array $warnings): array
    {
        return array(
            'valid' => $valid,
            'tables' => $tables,
            'chunks' => $chunks,
            'warnings' => $warnings,
        );
    }
}
