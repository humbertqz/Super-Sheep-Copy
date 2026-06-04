<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

use PharData;
use RecursiveIteratorIterator;
use SplFileInfo;
use SuperSheepCopy\Support\Filesystem;

final class TarGzPackageReader implements PackageReaderInterface
{
    private string $package_path;

    public function __construct(string $package_path)
    {
        $this->package_path = $package_path;
    }

    public function entries(): array
    {
        $archive = $this->open();
        $entries = array();
        $iterator = new RecursiveIteratorIterator($archive);
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $entry = $this->entryPath($item->getPathname());
            if ($entry !== '') {
                $entries[] = $entry;
            }
        }

        sort($entries);

        return $entries;
    }

    public function read(string $entry_path): ?string
    {
        if (!PackagePathGuard::isSafe($entry_path)) {
            return null;
        }

        $path = 'phar://' . $this->package_path . '/' . str_replace('\\', '/', $entry_path);
        if (!is_readable($path)) {
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

        $source = 'phar://' . $this->package_path . '/' . str_replace('\\', '/', $entry_path);
        if (!is_readable($source)) {
            return false;
        }

        $directory = dirname($destination_path);
        if (!Filesystem::makeDirectory($directory)) {
            return false;
        }

        return copy($source, $destination_path);
    }

    private function open(): PharData
    {
        if (!class_exists(PharData::class)) {
            throw new \RuntimeException('PharData is not available.');
        }

        try {
            return new PharData($this->package_path);
        } catch (\Exception $exception) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is chained, not output.
            throw new \RuntimeException('Unable to open TAR.GZ package.', 0, $exception);
        }
    }

    private function entryPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $marker = str_replace('\\', '/', $this->package_path) . '/';
        $position = strpos($normalized, $marker);
        if ($position !== false) {
            return substr($normalized, $position + strlen($marker));
        }

        $phar_marker = 'phar://' . $marker;
        $position = strpos($normalized, $phar_marker);
        if ($position !== false) {
            return substr($normalized, $position + strlen($phar_marker));
        }

        return ltrim($normalized, '/');
    }
}
