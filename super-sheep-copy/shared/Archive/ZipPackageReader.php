<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- ZipArchive::getStream returns PHP streams; streaming avoids loading large backup entries into memory.

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

use SuperSheepCopy\Support\Filesystem;
use ZipArchive;

final class ZipPackageReader implements PackageReaderInterface
{
    private string $package_path;
    private ?ZipArchive $hash_zip = null;

    public function __construct(string $package_path)
    {
        $this->package_path = $package_path;
    }

    public function __destruct()
    {
        if ($this->hash_zip instanceof ZipArchive) {
            $this->hash_zip->close();
        }
    }

    public function entries(): array
    {
        $zip = $this->open();
        $entries = array();
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || substr($name, -1) === '/') {
                continue;
            }
            $entries[] = str_replace('\\', '/', $name);
        }
        $zip->close();

        sort($entries);

        return $entries;
    }

    public function read(string $entry_path): ?string
    {
        if (!PackagePathGuard::isSafe($entry_path)) {
            return null;
        }

        $zip = $this->open();
        $contents = $zip->getFromName($entry_path);
        $zip->close();

        return is_string($contents) ? $contents : null;
    }

    public function sha256(string $entry_path): ?string
    {
        if (!PackagePathGuard::isSafe($entry_path)) {
            return null;
        }

        $zip = $this->hashArchive();
        $stream = $zip->getStream($entry_path);
        if (!is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                fclose($stream);

                return null;
            }
            hash_update($context, $chunk);
        }
        fclose($stream);

        return hash_final($context);
    }

    public function copyToFile(string $entry_path, string $destination_path): bool
    {
        if (!PackagePathGuard::isSafe($entry_path)) {
            return false;
        }

        $zip = $this->open();
        $source = $zip->getStream($entry_path);
        if (!is_resource($source)) {
            $zip->close();

            return false;
        }

        $directory = dirname($destination_path);
        if (!Filesystem::makeDirectory($directory)) {
            fclose($source);
            $zip->close();

            return false;
        }

        $target = fopen($destination_path, 'wb');
        if (!is_resource($target)) {
            fclose($source);
            $zip->close();

            return false;
        }

        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
        $zip->close();

        return $copied !== false;
    }

    private function open(): ZipArchive
    {
        $zip = new ZipArchive();
        if (!class_exists(ZipArchive::class) || $zip->open($this->package_path) !== true) {
            throw new \RuntimeException('Unable to open ZIP package.');
        }

        return $zip;
    }

    private function hashArchive(): ZipArchive
    {
        if (!$this->hash_zip instanceof ZipArchive) {
            $this->hash_zip = $this->open();
        }

        return $this->hash_zip;
    }
}
