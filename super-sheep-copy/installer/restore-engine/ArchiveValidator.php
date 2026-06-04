<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class ArchiveValidator
{
    public function isSafePath(string $path): bool
    {
        return PackagePathGuard::isSafe($path);
    }

    public function validatePackage(string $archive_path): ArchiveValidationResult
    {
        $errors = array();
        $manifest = array();
        $entry_count = 0;
        $database_entry_count = 0;
        $has_manifest = false;
        $has_checksums = false;
        $has_database_tables = false;
        $has_database_chunk = false;
        $has_file_entry = false;

        try {
            $reader = (new PackageReaderFactory())->create($archive_path);
            $entries = $reader->entries();
        } catch (\RuntimeException $exception) {
            return new ArchiveValidationResult(false, array($exception->getMessage()), array(), 0, 0);
        }

        foreach ($entries as $name) {
            $entry_count++;
            if (!$this->isSafePath($name)) {
                $errors[] = 'Unsafe archive entry: ' . $name;
            }

            if ($name === 'manifest.json') {
                $has_manifest = true;
            }

            if ($name === 'checksums.json') {
                $has_checksums = true;
            }

            if (strpos($name, 'database/') === 0 && substr($name, -1) !== '/') {
                $database_entry_count++;
            }

            if ($name === 'database/tables.json') {
                $has_database_tables = true;
            }

            if (strpos($name, 'database/chunks/') === 0 && substr($name, -4) === '.sql') {
                $has_database_chunk = true;
            }

            if (strpos($name, 'files/') === 0 && substr($name, -1) !== '/') {
                $has_file_entry = true;
            }
        }

        if (!$has_manifest) {
            $errors[] = 'Missing manifest.json.';
        }

        if (!$has_checksums) {
            $errors[] = 'Missing checksums.json.';
        }

        if ($database_entry_count === 0) {
            $errors[] = 'No database entries were found.';
        }

        if (!$has_database_tables) {
            $errors[] = 'Missing database/tables.json.';
        }

        if (!$has_database_chunk) {
            $errors[] = 'Missing database/chunks/*.sql.';
        }

        if (!$has_file_entry) {
            $errors[] = 'Missing files/ entries.';
        }

        if ($has_manifest) {
            $manifest_json = $reader->read('manifest.json');
            $decoded = is_string($manifest_json) ? json_decode($manifest_json, true) : null;
            if (!is_array($decoded)) {
                $errors[] = 'manifest.json is not valid JSON.';
            } else {
                $manifest = $decoded;
                if (($manifest['project'] ?? null) !== 'Super Sheep Copy') {
                    $errors[] = 'Archive manifest project is not Super Sheep Copy.';
                }
            }
        }

        return new ArchiveValidationResult($errors === array(), $errors, $manifest, $entry_count, $database_entry_count);
    }
}
