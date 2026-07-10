<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone restore engine streams ZipArchive entries before WordPress filesystem APIs are available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use ZipArchive;

final class ZipPackageReader implements PackageReaderInterface
{
    private string $package_path;
    private ?ZipArchive $zip = null;

    public function __construct(string $package_path)
    {
        $this->package_path = $package_path;
    }

    public function __destruct()
    {
        if ($this->zip instanceof ZipArchive) {
            $this->zip->close();
        }
    }

    public function entries(): array
    {
        $zip = $this->open();
        $entries = array();
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && substr($name, -1) !== '/') {
                $entries[] = str_replace('\\', '/', $name);
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

        $zip = $this->open();
        $contents = $zip->getFromName($entry_path);

        return is_string($contents) ? $contents : null;
    }

    public function sha256(string $entry_path): ?string
    {
        if (!PackagePathGuard::isSafe($entry_path)) {
            return null;
        }

        $stream = $this->open()->getStream($entry_path);
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
            return false;
        }

        $directory = dirname($destination_path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
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

        return $copied !== false;
    }

    private function open(): ZipArchive
    {
        if ($this->zip instanceof ZipArchive) {
            return $this->zip;
        }

        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive is not available.');
        }

        $zip = new ZipArchive();
        if ($zip->open($this->package_path) !== true) {
            throw new \RuntimeException('Unable to open ZIP package.');
        }

        $this->zip = $zip;

        return $this->zip;
    }
}
