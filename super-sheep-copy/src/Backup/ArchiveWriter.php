<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RuntimeException;
use SuperSheepCopy\Backup\Package\PackageWriterInterface;
use SuperSheepCopy\Backup\Package\ZipPackageWriter;
use SuperSheepCopy\Support\Filesystem;

final class ArchiveWriter
{
    /**
     * @param ScannedFile[] $site_files
     * @param ScannedFile[] $database_files
     * @param array<string,string> $checksums
     */
    public function write(string $archive_path, Manifest $manifest, array $site_files, array $database_files, array $checksums, string $log, ?PackageWriterInterface $writer = null): void
    {
        $writer = $writer ?? new ZipPackageWriter();
        $this->removeExistingPackage($archive_path);

        $writer->open($archive_path);
        $writer->addString('manifest.json', $manifest->toJson());
        $writer->addString('checksums.json', (string) json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $writer->addString('logs/backup.log', $log);

        foreach ($site_files as $file) {
            if ($file->isSymlink()) {
                continue;
            }
            $writer->addFile($file->absolutePath(), 'files/' . $file->relativePath());
        }

        foreach ($database_files as $file) {
            if ($file->isSymlink()) {
                continue;
            }
            $writer->addFile($file->absolutePath(), 'database/' . $file->relativePath());
        }

        $writer->close();
    }

    private function removeExistingPackage(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            if (!Filesystem::deleteFile($path)) {
                throw new RuntimeException('Unable to replace backup package.');
            }
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
            $child = $path . '/' . $item;
            $this->removeExistingPackage($child);
        }

        if (!Filesystem::removeDirectory($path)) {
            throw new RuntimeException('Unable to replace backup package directory.');
        }
    }
}
