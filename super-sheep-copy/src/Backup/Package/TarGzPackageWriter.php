<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SuperSheepCopy\Support\Filesystem;

final class TarGzPackageWriter implements PackageWriterInterface
{
    private string $package_path = '';
    private string $staging_path = '';
    private ?DirectoryPackageWriter $staging_writer = null;

    public function format(): string
    {
        return 'tar.gz';
    }

    public function extension(): string
    {
        return '.tar.gz';
    }

    public function isAvailable(): bool
    {
        return class_exists(PharData::class);
    }

    public function open(string $package_path): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('PharData is not available.');
        }

        $this->package_path = $package_path;
        $this->staging_path = $package_path . '.staging';
        $this->removeDirectory($this->staging_path);

        $this->staging_writer = new DirectoryPackageWriter();
        $this->staging_writer->open($this->staging_path);
    }

    public function addFile(string $source_path, string $entry_path): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $this->assertOpen();
        $this->staging_writer->addFile($source_path, $entry_path);
    }

    public function addString(string $entry_path, string $contents): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $this->assertOpen();
        $this->staging_writer->addString($entry_path, $contents);
    }

    public function close(): void
    {
        $this->assertOpen();

        $this->staging_writer->close();
        $temp_tar_path = $this->package_path . '.tmp.tar';
        $temp_gz_path = $temp_tar_path . '.gz';

        Filesystem::deleteFile($temp_tar_path);
        Filesystem::deleteFile($temp_gz_path);
        Filesystem::deleteFile($this->package_path);

        $tar = new PharData($temp_tar_path);
        foreach ($this->stagingFiles() as $source_path => $entry_path) {
            $tar->addFile($source_path, $entry_path);
        }

        $tar->compress(Phar::GZ);
        unset($tar);
        Filesystem::deleteFile($temp_tar_path);

        if (!Filesystem::move($temp_gz_path, $this->package_path)) {
            throw new RuntimeException('Unable to finalize TAR.GZ package.');
        }

        $this->removeDirectory($this->staging_path);
        $this->package_path = '';
        $this->staging_path = '';
        $this->staging_writer = null;
    }

    /**
     * @return array<string,string>
     */
    private function stagingFiles(): array
    {
        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->staging_path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $source_path = $file->getPathname();
            $entry_path = substr($source_path, strlen($this->staging_path) + 1);
            $files[$source_path] = str_replace('\\', '/', $entry_path);
        }

        return $files;
    }

    private function removeDirectory(string $path): void
    {
        Filesystem::removeDirectory($path);
    }

    private function assertOpen(): void
    {
        if ($this->staging_writer === null || $this->package_path === '' || $this->staging_path === '') {
            throw new RuntimeException('TAR.GZ package is not open.');
        }
    }
}
