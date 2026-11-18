<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

final class DatabaseManifestValidator
{
    /**
     * @param string[] $entries
     * @return string[]
     */
    public static function errors(PackageReaderInterface $reader, array $entries): array
    {
        $json = $reader->read('database/tables.json');
        $manifest = is_string($json) ? json_decode($json, true) : null;
        if (empty($manifest['tables']) || !is_array($manifest['tables'])) {
            return array('Invalid database/tables.json: expected at least one table.');
        }

        $available = array_fill_keys($entries, true);
        $tables = array();
        $chunks = array();
        $errors = array();
        foreach ($manifest['tables'] as $table) {
            if (!is_array($table) || !is_string($table['name'] ?? null) || $table['name'] === ''
                || empty($table['chunks']) || !is_array($table['chunks'])) {
                $errors[] = 'Invalid database table manifest entry.';
                continue;
            }
            if (isset($tables[$table['name']])) {
                $errors[] = 'Duplicate database table: ' . $table['name'];
            }
            $tables[$table['name']] = true;

            foreach ($table['chunks'] as $chunk) {
                if (!is_string($chunk) || preg_match('/^[A-Za-z0-9_.-]+\.sql$/D', $chunk) !== 1) {
                    $errors[] = 'Invalid database chunk file name.';
                    continue;
                }
                $entry = 'database/chunks/' . $chunk;
                if (!isset($available[$entry])) {
                    $errors[] = 'Missing database chunk entry: ' . $entry;
                } elseif (isset($chunks[$entry])) {
                    $errors[] = 'Duplicate database chunk reference: ' . $entry;
                }
                $chunks[$entry] = true;
            }
        }

        return $errors;
    }
}
