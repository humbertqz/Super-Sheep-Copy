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

        // Already compressed media gains little from another compression pass.
        $extension = strtolower(pathinfo($entry_path, PATHINFO_EXTENSION));
        $method = in_array($extension, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp3', 'mp4', 'mov', 'm4a', 'woff', 'woff2', 'zip', 'gz', '7z', 'rar'), true)
            ? ZipArchive::CM_STORE
            : ZipArchive::CM_DEFLATE;
        if (!$this->zip->setCompressionName($entry_path, $method, 1)) {
            throw new RuntimeException('Unable to configure ZIP package compression.');
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
            $zip = $this->zip;
            $this->zip = null;
            if (!$zip->close()) {
                throw new RuntimeException('Unable to finalize ZIP package.');
            }
        }
    }

    private function assertOpen(): void
    {
        if ($this->zip === null) {
            throw new RuntimeException('ZIP package is not open.');
        }
    }
}
