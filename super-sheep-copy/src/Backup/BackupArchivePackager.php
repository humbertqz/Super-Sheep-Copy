<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SuperSheepCopy\Backup\Package\PackageWriterFactory;
use SplFileInfo;

final class BackupArchivePackager implements BackupArchivePackagerInterface
{
    private ArchiveWriter $archive_writer;
    private ManifestBuilder $manifest_builder;
    private PackageWriterFactory $package_writer_factory;

    public function __construct(ArchiveWriter $archive_writer, ManifestBuilder $manifest_builder, ?PackageWriterFactory $package_writer_factory = null)
    {
        $this->archive_writer = $archive_writer;
        $this->manifest_builder = $manifest_builder;
        $this->package_writer_factory = $package_writer_factory ?? new PackageWriterFactory();
    }

    /**
     * @param ScannedFile[] $site_files
     * @param array<string,mixed> $metadata
     */
    public function package(string $job_id, string $working_directory, string $database_directory, array $site_files, array $metadata): ArchivePackageResult
    {
        $database_files = $this->databaseFiles($database_directory);
        $checksums = array_merge(
            $this->checksumsForFiles('files', $site_files),
            $this->checksumsForFiles('database', $database_files)
        );

        $writer = $this->package_writer_factory->bestAvailable();
        $archive_path = rtrim($working_directory, '/\\') . '/' . $job_id . $writer->extension();
        $metadata['file_count'] = count($site_files);
        $metadata['database_table_count'] = count($database_files);
        $archive_size = 0;
        $metadata['archive_size'] = $archive_size;
        $metadata['checksums'] = $checksums;
        $metadata['package_format'] = $writer->format();
        $metadata['package_extension'] = $writer->extension();
        $metadata['package_schema_version'] = 1;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $metadata['archive_size'] = $archive_size;
            $this->archive_writer->write(
                $archive_path,
                $this->manifest_builder->build($metadata),
                $site_files,
                $database_files,
                $checksums,
                'Backup ' . $job_id . ' packaged.',
                $writer
            );

            clearstatcache(true, $archive_path);
            $new_archive_size = $this->packageSize($archive_path);

            if ($new_archive_size === $archive_size) {
                return new ArchivePackageResult($archive_path, $archive_size, count($site_files), count($database_files), $checksums, $writer->format(), $writer->extension());
            }

            $archive_size = $new_archive_size;
        }

        return new ArchivePackageResult($archive_path, $archive_size, count($site_files), count($database_files), $checksums, $writer->format(), $writer->extension());
    }

    /**
     * @return ScannedFile[]
     */
    private function databaseFiles(string $database_directory): array
    {
        if (!is_dir($database_directory)) {
            throw new RuntimeException('Database export directory does not exist.');
        }

        $root = rtrim(str_replace('\\', '/', $database_directory), '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $files = array();
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($absolute, strlen($root)), '/');
            $files[] = new ScannedFile($absolute, $relative, (int) $item->getSize(), $item->isLink());
        }

        usort($files, static function (ScannedFile $a, ScannedFile $b): int {
            return strcmp($a->relativePath(), $b->relativePath());
        });

        return $files;
    }

    /**
     * @param ScannedFile[] $files
     * @return array<string,string>
     */
    private function checksumsForFiles(string $prefix, array $files): array
    {
        $checksums = array();
        foreach ($files as $file) {
            if ($file->isSymlink()) {
                continue;
            }

            $checksum = hash_file('sha256', $file->absolutePath());
            if ($checksum === false) {
                throw new RuntimeException('Unable to calculate checksum for: ' . esc_html($file->relativePath()));
            }

            $checksums[$prefix . '/' . $file->relativePath()] = $checksum;
        }

        return $checksums;
    }

    private function packageSize(string $path): int
    {
        if (is_file($path)) {
            $size = filesize($path);
            if ($size === false) {
                throw new RuntimeException('Unable to read backup archive size.');
            }

            return (int) $size;
        }

        if (!is_dir($path)) {
            throw new RuntimeException('Unable to read backup archive size.');
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile()) {
                $size += (int) $item->getSize();
            }
        }

        return $size;
    }
}
