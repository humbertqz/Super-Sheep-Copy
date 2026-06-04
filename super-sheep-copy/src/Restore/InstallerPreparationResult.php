<?php

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

final class InstallerPreparationResult
{
    private string $job_id;
    private string $installer_url;
    private string $launch_url;
    private string $token;
    private string $engine_directory_basename;
    private string $staged_archive_basename;
    private string $source_site_url;
    private string $source_home_url;

    public function __construct(
        string $job_id,
        string $installer_url,
        string $launch_url,
        string $token,
        string $engine_directory_basename,
        string $staged_archive_basename,
        string $source_site_url,
        string $source_home_url
    ) {
        $this->job_id = $job_id;
        $this->installer_url = $installer_url;
        $this->launch_url = $launch_url;
        $this->token = $token;
        $this->engine_directory_basename = $engine_directory_basename;
        $this->staged_archive_basename = $staged_archive_basename;
        $this->source_site_url = $source_site_url;
        $this->source_home_url = $source_home_url;
    }

    public function jobId(): string
    {
        return $this->job_id;
    }

    public function installerUrl(): string
    {
        return $this->installer_url;
    }

    public function launchUrl(): string
    {
        return $this->launch_url;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function engineDirectoryBasename(): string
    {
        return $this->engine_directory_basename;
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
}
