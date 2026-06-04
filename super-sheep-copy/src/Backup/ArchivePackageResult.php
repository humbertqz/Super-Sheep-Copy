<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class ArchivePackageResult
{
    private string $archive_path;
    private int $archive_size;
    private int $site_file_count;
    private int $database_file_count;
    /** @var array<string,string> */
    private array $checksums;
    private string $package_format;
    private string $package_extension;

    /**
     * @param array<string,string> $checksums
     */
    public function __construct(string $archive_path, int $archive_size, int $site_file_count, int $database_file_count, array $checksums, string $package_format = 'zip', string $package_extension = '.zip')
    {
        $this->archive_path = $archive_path;
        $this->archive_size = $archive_size;
        $this->site_file_count = $site_file_count;
        $this->database_file_count = $database_file_count;
        $this->checksums = $checksums;
        $this->package_format = $package_format;
        $this->package_extension = $package_extension;
    }

    public function archivePath(): string
    {
        return $this->archive_path;
    }

    public function archiveSize(): int
    {
        return $this->archive_size;
    }

    public function siteFileCount(): int
    {
        return $this->site_file_count;
    }

    public function databaseFileCount(): int
    {
        return $this->database_file_count;
    }

    /**
     * @return array<string,string>
     */
    public function checksums(): array
    {
        return $this->checksums;
    }

    public function packageFormat(): string
    {
        return $this->package_format;
    }

    public function packageExtension(): string
    {
        return $this->package_extension;
    }
}
