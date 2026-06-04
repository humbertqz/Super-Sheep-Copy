<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

use RuntimeException;
use ZipArchive;

final class ZipPackageWriter implements PackageWriterInterface
{
    private ?ZipArchive $zip = null;

    public function format(): string
    {
        return 'zip';
    }

    public function extension(): string
    {
        return '.zip';
    }

    public function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function open(string $package_path): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('ZipArchive is not available.');
        }

        $zip = new ZipArchive();
        $flags = file_exists($package_path) ? 0 : ZipArchive::CREATE;
        if ($zip->open($package_path, $flags) !== true) {
            throw new RuntimeException('Unable to create ZIP package.');
        }

        $this->zip = $zip;
    }

    public function addFile(string $source_path, string $entry_path): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $this->assertOpen();

        if (!$this->zip->addFile($source_path, $entry_path)) {
            throw new RuntimeException('Unable to add ZIP package file.');
        }
    }

    public function addString(string $entry_path, string $contents): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $this->assertOpen();

        if ($this->zip->locateName($entry_path) !== false) {
            $this->zip->deleteName($entry_path);
        }

        if (!$this->zip->addFromString($entry_path, $contents)) {
            throw new RuntimeException('Unable to add ZIP package entry.');
        }
    }

    public function close(): void
    {
        if ($this->zip !== null) {
            $this->zip->close();
            $this->zip = null;
        }
    }

    private function assertOpen(): void
    {
        if ($this->zip === null) {
            throw new RuntimeException('ZIP package is not open.');
        }
    }
}
