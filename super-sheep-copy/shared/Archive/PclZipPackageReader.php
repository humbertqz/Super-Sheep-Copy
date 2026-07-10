<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

use SuperSheepCopy\Support\Filesystem;

final class PclZipPackageReader implements PackageReaderInterface
{
    private string $package_path;

    public function __construct(string $package_path)
    {
        $this->package_path = $package_path;
    }

    public function entries(): array
    {
        $archive = $this->open();
        $contents = $archive->listContent();
        if (!is_array($contents)) {
            return array();
        }

        $entries = array();
        foreach ($contents as $entry) {
            if (!is_array($entry) || !empty($entry['folder']) || !isset($entry['filename'])) {
                continue;
            }
            $entries[] = str_replace('\\', '/', (string) $entry['filename']);
        }
        sort($entries);

        return $entries;
    }

    public function read(string $entry_path): ?string
    {
        if (!PackagePathGuard::isSafe($entry_path)) {
            return null;
        }

        $archive = $this->open();
        $contents = $archive->extract(PCLZIP_OPT_BY_NAME, $entry_path, PCLZIP_OPT_EXTRACT_AS_STRING);
        if (!is_array($contents) || !isset($contents[0]) || !is_array($contents[0]) || !isset($contents[0]['content'])) {
            return null;
        }

        return is_string($contents[0]['content']) ? $contents[0]['content'] : null;
    }

    public function sha256(string $entry_path): ?string
    {
        $contents = $this->read($entry_path);

        return $contents === null ? null : hash('sha256', $contents);
    }

    public function copyToFile(string $entry_path, string $destination_path): bool
    {
        $contents = $this->read($entry_path);
        if ($contents === null) {
            return false;
        }

        $directory = dirname($destination_path);
        if (!Filesystem::makeDirectory($directory)) {
            return false;
        }

        return file_put_contents($destination_path, $contents) !== false;
    }

    private function open(): \PclZip
    {
        if (!$this->loadPclZip()) {
            throw new \RuntimeException('PclZip is not available.');
        }

        return new \PclZip($this->package_path);
    }

    private function loadPclZip(): bool
    {
        if (class_exists(\PclZip::class)) {
            return true;
        }

        $path = defined('ABSPATH') ? rtrim((string) ABSPATH, '/\\') . '/wp-admin/includes/class-pclzip.php' : '';
        if ($path !== '' && is_file($path)) {
            require_once $path;
        }

        return class_exists(\PclZip::class);
    }
}
