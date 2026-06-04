<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SuperSheepCopy\Support\Filesystem;

final class DirectoryPackageReader implements PackageReaderInterface
{
    private string $package_path;

    public function __construct(string $package_path)
    {
        $this->package_path = rtrim($package_path, '/\\');
    }

    public function entries(): array
    {
        if (!is_dir($this->package_path)) {
            return array();
        }

        $entries = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->package_path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $item->getPathname());
            $entry = substr($path, strlen(str_replace('\\', '/', $this->package_path)) + 1);
            $entries[] = $entry;
        }

        sort($entries);

        return $entries;
    }

    public function read(string $entry_path): ?string
    {
        if (!PackagePathGuard::isSafe($entry_path)) {
            return null;
        }

        $path = $this->package_path . '/' . str_replace('\\', '/', $entry_path);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    public function copyToFile(string $entry_path, string $destination_path): bool
    {
        if (!PackagePathGuard::isSafe($entry_path)) {
            return false;
        }

        $source = $this->package_path . '/' . str_replace('\\', '/', $entry_path);
        if (!is_file($source) || !is_readable($source)) {
            return false;
        }

        $directory = dirname($destination_path);
        if (!Filesystem::makeDirectory($directory)) {
            return false;
        }

        return copy($source, $destination_path);
    }
}
