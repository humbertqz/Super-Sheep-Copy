<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RuntimeException;
use SuperSheepCopy\Shared\Archive\PackageReaderFactory;
use SuperSheepCopy\Shared\Archive\PackageReaderInterface;

final class IncrementalArchiveValidator
{
    private const DEFAULT_BUDGET_SECONDS = 10.0;

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function prepare(string $archive_path, array $payload): array
    {
        $reader = $this->reader($archive_path);
        $entries = $reader->entries();
        $content_entries = array_values(array_filter($entries, static function (string $entry): bool {
            return (strpos($entry, 'files/') === 0 || strpos($entry, 'database/') === 0) && substr($entry, -1) !== '/';
        }));
        $checksum_json = $reader->read('checksums.json');
        $checksums = is_string($checksum_json) ? json_decode($checksum_json, true) : null;

        $payload['validation_entries'] = $content_entries;
        $payload['validation_entry_index'] = 0;
        $payload['validation_total_entries'] = count($content_entries);
        $payload['validation_errors'] = array();
        $payload['validation_checksums'] = is_array($checksums) ? $checksums : array();
        if (!is_array($checksums) || $checksums === array()) {
            $payload['validation_errors'][] = 'checksums.json must contain SHA-256 checksums.';
        }

        if ($content_entries === array()) {
            $payload['validation_errors'][] = 'No backup content entries were found.';
        }

        $required = array('manifest.json', 'checksums.json', 'database/tables.json');
        foreach ($required as $required_entry) {
            if (!in_array($required_entry, $entries, true)) {
                $payload['validation_errors'][] = 'Missing ' . $required_entry . '.';
            }
        }
        if ($this->firstEntryWithPrefix($entries, 'database/chunks/') === '') {
            $payload['validation_errors'][] = 'Missing database/chunks/*.sql.';
        }
        if ($this->firstEntryWithPrefix($entries, 'files/') === '') {
            $payload['validation_errors'][] = 'Missing files/ entries.';
        }
        $manifest_json = $reader->read('manifest.json');
        $manifest = is_string($manifest_json) ? json_decode($manifest_json, true) : null;
        if (!is_array($manifest) || ($manifest['project'] ?? null) !== 'Super Sheep Copy') {
            $payload['validation_errors'][] = 'Archive manifest is invalid.';
        }

        return $payload;
    }

    /** @param string[] $entries */
    private function firstEntryWithPrefix(array $entries, string $prefix): string
    {
        foreach ($entries as $entry) {
            if (strpos($entry, $prefix) === 0) {
                return $entry;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function step(string $archive_path, array $payload, float $budget_seconds = self::DEFAULT_BUDGET_SECONDS): array
    {
        $entries = isset($payload['validation_entries']) && is_array($payload['validation_entries']) ? array_values($payload['validation_entries']) : array();
        $checksums = isset($payload['validation_checksums']) && is_array($payload['validation_checksums']) ? $payload['validation_checksums'] : array();
        $index = isset($payload['validation_entry_index']) ? max(0, (int) $payload['validation_entry_index']) : 0;
        $started = microtime(true);
        $reader = $this->reader($archive_path);

        for (; $index < count($entries); $index++) {
            $entry = (string) $entries[$index];
            if (!array_key_exists($entry, $checksums)) {
                $payload['validation_errors'][] = 'Missing checksum for archive entry: ' . $entry;
            } elseif (!is_string($checksums[$entry]) || preg_match('/^[a-f0-9]{64}$/', $checksums[$entry]) !== 1) {
                $payload['validation_errors'][] = 'Invalid SHA-256 checksum for archive entry: ' . $entry;
            } elseif ($reader->sha256($entry) !== $checksums[$entry]) {
                $payload['validation_errors'][] = 'Checksum mismatch for archive entry: ' . $entry;
            }

            if ($index + 1 < count($entries) && microtime(true) - $started >= max(0.1, $budget_seconds)) {
                ++$index;
                break;
            }
        }

        $payload['validation_entry_index'] = $index;
        $payload['validation_complete'] = $index >= count($entries);
        $payload['validation_message'] = $payload['validation_complete']
            ? 'Backup validation finished.'
            : 'Validated ' . $index . ' of ' . count($entries) . ' backup entries.';

        if ($payload['validation_complete']) {
            foreach ($checksums as $entry => $checksum) {
                if (!is_string($entry) || !in_array($entry, $entries, true)) {
                    $payload['validation_errors'][] = 'Unexpected checksum for archive entry: ' . (string) $entry;
                }
            }
        }

        return $payload;
    }

    private function reader(string $archive_path): PackageReaderInterface
    {
        try {
            return (new PackageReaderFactory())->create($archive_path);
        } catch (\Throwable $throwable) {
            throw new RuntimeException('Unable to open backup package: ' . $throwable->getMessage(), 0, $throwable);
        }
    }
}
