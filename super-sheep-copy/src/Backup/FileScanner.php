<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class FileScanner
{
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
    public function scan(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
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
        $batch_size = max(1, $batch_size);
        $settings = isset($payload['backup_settings']) && is_array($payload['backup_settings']) ? $payload['backup_settings'] : array();
        $exclude_cache = !array_key_exists('exclude_cache_files', $settings) || (bool) $settings['exclude_cache_files'];
        $skip_large_files = isset($settings['skip_large_files']) ? (bool) $settings['skip_large_files'] : true;
        $large_file_limit_mb = isset($settings['large_file_limit_mb']) && is_numeric($settings['large_file_limit_mb']) ? (int) $settings['large_file_limit_mb'] : 250;
        $large_file_limit_bytes = max(0, $large_file_limit_mb) * 1024 * 1024;

        if (!isset($payload['file_scan_directories']) || !is_array($payload['file_scan_directories'])) {
            $payload['file_scan_directories'] = array('');
            $payload['file_scan_current_directory'] = null;
            $payload['file_scan_current_index'] = 0;
            $payload['scanned_file_count'] = 0;
            if ($this->scannedFilesPath($payload) !== '') {
                $this->writeScannedFilesManifest($this->scannedFilesPath($payload), '');
                unset($payload['scanned_files']);
            } else {
                $payload['scanned_files'] = array();
            }
        }

        $directories = array_values($payload['file_scan_directories']);
        $current_directory = isset($payload['file_scan_current_directory']) && $payload['file_scan_current_directory'] !== null ? (string) $payload['file_scan_current_directory'] : null;
        $current_index = isset($payload['file_scan_current_index']) ? (int) $payload['file_scan_current_index'] : 0;
        $scanned_files_path = $this->scannedFilesPath($payload);
        $files = $scanned_files_path === '' && isset($payload['scanned_files']) && is_array($payload['scanned_files']) ? $payload['scanned_files'] : array();
        $scanned_file_count = isset($payload['scanned_file_count']) ? (int) $payload['scanned_file_count'] : count($files);
        $processed = 0;
        $current_directory_entries_for = null;
        $current_directory_entries = array();

        while ($processed < $batch_size) {
            if ($current_directory === null) {
                if ($directories === array()) {
                    break;
                }

                $current_directory = (string) array_shift($directories);
                $current_index = 0;
            }

            $entries = $this->cachedDirectoryEntries($root, $current_directory, $current_directory_entries_for, $current_directory_entries);
            if (!isset($entries[$current_index])) {
                $current_directory = null;
                $current_index = 0;
                continue;
            }

            $entry = (string) $entries[$current_index];
            $current_index++;
            $processed++;

            $relative = trim($current_directory . '/' . $entry, '/');
            if ($this->isExcluded($relative, $exclude_cache)) {
                continue;
            }

            $absolute = $root . '/' . $relative;
            if (is_dir($absolute) && !is_link($absolute)) {
                $directories[] = $relative;
                continue;
            }

            if (!is_file($absolute)) {
                continue;
            }

            $size = (int) filesize($absolute);
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
                $this->appendScannedFile($scanned_files_path, $file);
            } else {
                $files[] = $file;
            }
            $scanned_file_count++;
        }

        if ($current_directory !== null && $current_index >= count($this->cachedDirectoryEntries($root, $current_directory, $current_directory_entries_for, $current_directory_entries))) {
            $current_directory = null;
            $current_index = 0;
        }

        $complete = $directories === array() && $current_directory === null;
        if ($complete && $scanned_files_path === '') {
            usort($files, static function (array $a, array $b): int {
                return strcmp((string) $a['relative_path'], (string) $b['relative_path']);
            });
        }

        $payload['file_scan_directories'] = $directories;
        $payload['file_scan_current_directory'] = $current_directory;
        $payload['file_scan_current_index'] = $current_index;
        if ($scanned_files_path === '') {
            $payload['scanned_files'] = $files;
        } else {
            unset($payload['scanned_files']);
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
    private function appendScannedFile(string $path, array $file): void
    {
        $encoded = json_encode($file, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Unable to encode scanned file entry.');
        }

        $this->writeScannedFilesManifest($path, $encoded . "\n", FILE_APPEND);
    }

    private function writeScannedFilesManifest(string $path, string $contents, int $flags = 0): void
    {
        if (file_put_contents($path, $contents, $flags) === false) {
            throw new RuntimeException('Unable to write scanned files manifest.');
        }
    }

    /**
     * @return string[]
     */
    private function cachedDirectoryEntries(string $root, string $relative_directory, ?string &$cached_directory, array &$cached_entries): array
    {
        if ($cached_directory !== $relative_directory) {
            $cached_directory = $relative_directory;
            $cached_entries = $this->directoryEntries($root, $relative_directory);
        }

        return $cached_entries;
    }

    /**
     * @return string[]
     */
    private function directoryEntries(string $root, string $relative_directory): array
    {
        $absolute_directory = $relative_directory === '' ? $root : $root . '/' . $relative_directory;
        $entries = scandir($absolute_directory);
        if ($entries === false) {
            return array();
        }

        sort($entries);

        return array_values(array_filter($entries, static function (string $entry): bool {
            return $entry !== '.' && $entry !== '..';
        }));
    }

    private function isExcluded(string $relative, bool $exclude_cache = true): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        $name = basename($relative);
        if (in_array($name, $this->excluded_names, true)) {
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
}
