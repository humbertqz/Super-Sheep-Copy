<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class FileScanner
{
    private string $backup_exclusion = '';
    /**
     * @var string[]
     */
    private array $excluded_segments = array(
        '.git',
        '.hg',
        '.svn',
        'node_modules',
        'wp-content/cache',
        'wp-content/uploads/super-sheep-copy',
    );

    /**
     * @var string[]
     */
    private array $excluded_names = array('.DS_Store', 'Thumbs.db');

    /**
     * @return ScannedFile[]
     */
    public function scan(string $root, string $backup_directory = ''): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $this->setBackupExclusion($root, $backup_directory);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $files = array();
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($absolute, strlen($root)), '/');
            if ($this->isExcluded($relative, true)) {
                continue;
            }

            $files[] = new ScannedFile($absolute, $relative, (int) $item->getSize(), $item->isLink());
        }

        usort($files, static function (ScannedFile $a, ScannedFile $b): int {
            return strcmp($a->relativePath(), $b->relativePath());
        });

        return $files;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function scanStep(string $root, array $payload, int $batch_size = 100): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $this->setBackupExclusion($root, isset($payload['working_directory']) ? dirname((string) $payload['working_directory']) : '');
        $batch_size = max(1, $batch_size);
        $settings = isset($payload['backup_settings']) && is_array($payload['backup_settings']) ? $payload['backup_settings'] : array();
        $exclude_cache = !array_key_exists('exclude_cache_files', $settings) || (bool) $settings['exclude_cache_files'];
        $skip_large_files = isset($settings['skip_large_files']) ? (bool) $settings['skip_large_files'] : true;
        $large_file_limit_mb = isset($settings['large_file_limit_mb']) && is_numeric($settings['large_file_limit_mb']) ? (int) $settings['large_file_limit_mb'] : 250;
        $large_file_limit_bytes = max(0, $large_file_limit_mb) * 1024 * 1024;

        $scanned_files_path = $this->scannedFilesPath($payload);
        if (!isset($payload['file_scan_directories']) || !is_array($payload['file_scan_directories'])
            || !array_key_exists('file_scan_last_entry', $payload)
            || ($scanned_files_path !== '' && !isset($payload['scanned_files_offset']))) {
            $payload['file_scan_directories'] = array('');
            $payload['file_scan_current_directory'] = null;
            $payload['file_scan_last_entry'] = null;
            $payload['scanned_file_count'] = 0;
            unset($payload['skipped_large_files'], $payload['skipped_large_file_count'], $payload['skipped_symlinks']);
            if ($scanned_files_path !== '') {
                $this->writeScannedFilesManifest($scanned_files_path, '');
                $payload['scanned_files_offset'] = 0;
                unset($payload['scanned_files']);
            } else {
                $payload['scanned_files'] = array();
            }
        }

        $directories = array_values($payload['file_scan_directories']);
        $current_directory = isset($payload['file_scan_current_directory']) && $payload['file_scan_current_directory'] !== null ? (string) $payload['file_scan_current_directory'] : null;
        $last_entry = $payload['file_scan_last_entry'];
        $manifest_offset = (int) ($payload['scanned_files_offset'] ?? 0);
        if ($scanned_files_path !== '') {
            $this->restoreManifestCheckpoint($scanned_files_path, $manifest_offset);
        }
        $files = $scanned_files_path === '' && isset($payload['scanned_files']) && is_array($payload['scanned_files']) ? $payload['scanned_files'] : array();
        $scanned_file_count = isset($payload['scanned_file_count']) ? (int) $payload['scanned_file_count'] : count($files);
        $processed = 0;
        while ($processed < $batch_size) {
            if ($current_directory === null) {
                if ($directories === array()) {
                    break;
                }

                $current_directory = (string) array_shift($directories);
                $last_entry = null;
            }

            $entries = $this->directoryEntries($root, $current_directory);
            $current_index = $this->nextEntryIndex($entries, $last_entry);
            while ($processed < $batch_size && isset($entries[$current_index])) {
                $entry = $entries[$current_index++];
                $last_entry = $entry;
                $processed++;

                $relative = trim($current_directory . '/' . $entry, '/');
                if ($this->isExcluded($relative, $exclude_cache)) {
                    continue;
                }

                $absolute = $root . '/' . $relative;
                if (is_link($absolute)) {
                    $payload['skipped_symlinks'][] = $relative;
                    continue;
                }
                if (is_dir($absolute) && !is_link($absolute)) {
                    $directories[] = $relative;
                    continue;
                }

                if (!is_file($absolute) || !is_readable($absolute)) {
                    throw new RuntimeException('Unable to read site file: ' . $relative);
                }

                $size = filesize($absolute);
                if ($size === false) {
                    throw new RuntimeException('Unable to read site file size: ' . $relative);
                }
                if ($skip_large_files && $size > $large_file_limit_bytes) {
                    if (!isset($payload['skipped_large_files']) || !is_array($payload['skipped_large_files'])) {
                        $payload['skipped_large_files'] = array();
                    }
                    $payload['skipped_large_files'][] = array(
                        'relative_path' => str_replace('\\', '/', $relative),
                        'size' => $size,
                    );
                    $payload['skipped_large_file_count'] = count($payload['skipped_large_files']);
                    continue;
                }

                $file = array(
                    'absolute_path' => str_replace('\\', '/', $absolute),
                    'relative_path' => str_replace('\\', '/', $relative),
                    'size' => $size,
                    'symlink' => is_link($absolute),
                );
                if ($scanned_files_path !== '') {
                    $manifest_offset += $this->appendScannedFile($scanned_files_path, $file);
                } else {
                    $files[] = $file;
                }
                $scanned_file_count++;
            }

            if ($current_index >= count($entries)) {
                $current_directory = null;
                $last_entry = null;
            }
        }

        $complete = $directories === array() && $current_directory === null;
        if ($complete && $scanned_files_path === '') {
            usort($files, static function (array $a, array $b): int {
                return strcmp((string) $a['relative_path'], (string) $b['relative_path']);
            });
        }

        $payload['file_scan_directories'] = $directories;
        $payload['file_scan_current_directory'] = $current_directory;
        $payload['file_scan_last_entry'] = $last_entry;
        unset($payload['file_scan_current_index']);
        if ($scanned_files_path === '') {
            $payload['scanned_files'] = $files;
        } else {
            unset($payload['scanned_files']);
            $payload['scanned_files_offset'] = $manifest_offset;
        }
        $payload['scanned_file_count'] = $scanned_file_count;
        $payload['file_scan_complete'] = $complete;
        $payload['message'] = $complete ? 'File scan finished.' : 'Scanned ' . $scanned_file_count . ' files.';

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function scannedFilesPath(array $payload): string
    {
        return isset($payload['scanned_files_path']) && is_scalar($payload['scanned_files_path'])
            ? (string) $payload['scanned_files_path']
            : '';
    }

    /**
     * @param array<string,mixed> $file
     */
    private function appendScannedFile(string $path, array $file): int
    {
        $encoded = json_encode($file, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Unable to encode scanned file entry.');
        }

        $line = $encoded . "\n";
        $this->writeScannedFilesManifest($path, $line, FILE_APPEND);
        return strlen($line);
    }

    private function writeScannedFilesManifest(string $path, string $contents, int $flags = 0): void
    {
        if (file_put_contents($path, $contents, $flags) !== strlen($contents)) {
            throw new RuntimeException('Unable to write scanned files manifest.');
        }
    }

    private function restoreManifestCheckpoint(string $path, int $offset): void
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $offset < 0 || $size < $offset) {
            throw new RuntimeException('Scanned files manifest is missing or incomplete. Restart this backup.');
        }
        if ($size === $offset) {
            return;
        }

        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open scanned files manifest.');
        }
        try {
            if (!ftruncate($handle, $offset)) {
                throw new RuntimeException('Unable to restore scanned files manifest to its saved position.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param string[] $entries */
    private function nextEntryIndex(array $entries, ?string $last_entry): int
    {
        if ($last_entry === null) {
            return 0;
        }

        $first = 0;
        $end = count($entries);
        while ($first < $end) {
            $middle = intdiv($first + $end, 2);
            if (strcmp($entries[$middle], $last_entry) <= 0) {
                $first = $middle + 1;
            } else {
                $end = $middle;
            }
        }
        return $first;
    }

    /**
     * @return string[]
     */
    private function directoryEntries(string $root, string $relative_directory): array
    {
        $absolute_directory = $relative_directory === '' ? $root : $root . '/' . $relative_directory;
        $entries = @scandir($absolute_directory);
        if ($entries === false) {
            throw new RuntimeException('Unable to scan site directory: ' . $relative_directory);
        }

        sort($entries, SORT_STRING);

        return array_values(array_filter($entries, static function (string $entry): bool {
            return $entry !== '.' && $entry !== '..';
        }));
    }

    private function isWcpdfTemporaryAttachment(string $relative): bool
    {
        return preg_match(
            '#^wp-content/uploads/wpo_wcpdf_[^/]+/attachments(?:/|$)#',
            $relative
        ) === 1;
    }

    private function isExcluded(string $relative, bool $exclude_cache = true): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($this->backup_exclusion !== '' && ($relative === $this->backup_exclusion || strpos($relative, $this->backup_exclusion . '/') === 0)) {
            return true;
        }
        $name = basename($relative);
        if (in_array($name, $this->excluded_names, true)) {
            return true;
        }

        if ($this->isWcpdfTemporaryAttachment($relative)) {
            return true;
        }

        foreach ($this->excluded_segments as $segment) {
            if (!$exclude_cache && $segment === 'wp-content/cache') {
                continue;
            }

            if ($relative === $segment || strpos($relative, $segment . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    private function setBackupExclusion(string $root, string $directory): void
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $this->backup_exclusion = strpos($directory, $root . '/') === 0 ? substr($directory, strlen($root) + 1) : '';
    }
}
