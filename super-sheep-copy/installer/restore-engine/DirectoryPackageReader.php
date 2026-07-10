<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone restore engine runs before WordPress filesystem APIs are available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
        $root = str_replace('\\', '/', $this->package_path);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->package_path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $item->getPathname());
            $entries[] = substr($path, strlen($root) + 1);
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

    public function sha256(string $entry_path): ?string
    {
        if (!PackagePathGuard::isSafe($entry_path)) {
            return null;
        }

        $path = $this->package_path . '/' . str_replace('\\', '/', $entry_path);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $checksum = hash_file('sha256', $path);

        return is_string($checksum) ? $checksum : null;
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
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return false;
        }

        return copy($source, $destination_path);
    }
}
