<?php

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

final class RestorePreparationResult
{
    private string $job_id;
    private string $staged_archive_basename;
    private string $source_site_url;
    private string $source_home_url;
    private int $database_entry_count;
    private int $archive_entry_count;
    private string $state;

    public function __construct(
        string $job_id,
        string $staged_archive_basename,
        string $source_site_url,
        string $source_home_url,
        int $database_entry_count,
        int $archive_entry_count,
        string $state
    ) {
        $this->job_id = $job_id;
        $this->staged_archive_basename = $staged_archive_basename;
        $this->source_site_url = $source_site_url;
        $this->source_home_url = $source_home_url;
        $this->database_entry_count = $database_entry_count;
        $this->archive_entry_count = $archive_entry_count;
        $this->state = $state;
    }

    public function jobId(): string
    {
        return $this->job_id;
    }

    public function stagedArchiveBasename(): string
    {
        return $this->staged_archive_basename;
    }

    public function sourceSiteUrl(): string
    {
        return $this->source_site_url;
    }

    public function sourceHomeUrl(): string
    {
        return $this->source_home_url;
    }

    public function databaseEntryCount(): int
    {
        return $this->database_entry_count;
    }

    public function archiveEntryCount(): int
    {
        return $this->archive_entry_count;
    }

    public function state(): string
    {
        return $this->state;
    }
}
