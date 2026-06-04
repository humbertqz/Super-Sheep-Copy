<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

use RuntimeException;
use SuperSheepCopy\Support\Filesystem;

final class DirectoryPackageWriter implements PackageWriterInterface
{
    private string $package_path = '';

    public function format(): string
    {
        return 'directory';
    }

    public function extension(): string
    {
        return '';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function open(string $package_path): void
    {
        $this->package_path = rtrim($package_path, '/\\');
        $this->ensureDirectory($this->package_path, 'Unable to create package directory.');
    }

    public function addFile(string $source_path, string $entry_path): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $this->assertOpen();

        $destination = $this->destinationPath($entry_path);
        $this->ensureDirectory(dirname($destination), 'Unable to create package entry directory.');

        if (!copy($source_path, $destination)) {
            throw new RuntimeException('Unable to copy package file.');
        }
    }

    public function addString(string $entry_path, string $contents): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $this->assertOpen();

        $destination = $this->destinationPath($entry_path);
        $this->ensureDirectory(dirname($destination), 'Unable to create package entry directory.');

        if (file_put_contents($destination, $contents) === false) {
            throw new RuntimeException('Unable to write package entry.');
        }
    }

    public function close(): void
    {
    }

    private function destinationPath(string $entry_path): string
    {
        return $this->package_path . '/' . str_replace('\\', '/', $entry_path);
    }

    private function ensureDirectory(string $directory, string $message): void
    {
        if (!Filesystem::makeDirectory($directory)) {
            throw new RuntimeException(esc_html($message));
        }
    }

    private function assertOpen(): void
    {
        if ($this->package_path === '') {
            throw new RuntimeException('Directory package is not open.');
        }
    }
}
