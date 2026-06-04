<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Restore staging creates plugin-owned upload directories.

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

use RuntimeException;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Shared\Archive\ArchiveValidatorInterface;

final class RestorePreparationManager implements RestorePreparationManagerInterface
{
    private ArchiveValidatorInterface $archive_validator;
    private JobRepositoryInterface $jobs;
    private string $staging_directory;

    public function __construct(ArchiveValidatorInterface $archive_validator, JobRepositoryInterface $jobs, string $staging_directory)
    {
        $this->archive_validator = $archive_validator;
        $this->jobs = $jobs;
        $this->staging_directory = $staging_directory;
    }

    public function prepare(array $upload): RestorePreparationResult
    {
        $this->assertUpload($upload);

        $job_id = 'restore-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $this->save($job_id, Job::VALIDATING_RESTORE, array());

        $tmp_name = (string) $upload['tmp_name'];
        $validation = $this->archive_validator->validatePackage($tmp_name);
        if (!$validation->isValid()) {
            throw new RuntimeException('Restore archive is not a valid Super Sheep Copy backup.');
        }

        $this->ensureDirectory($this->staging_directory);
        $extension = $this->packageExtension((string) $upload['name'], $tmp_name);
        $basename = $job_id . $extension;
        $destination = rtrim($this->staging_directory, '/\\') . '/' . $basename;
        if (is_dir($tmp_name)) {
            $this->copyDirectory($tmp_name, $destination);
        } elseif (!copy($tmp_name, $destination)) {
            throw new RuntimeException('Unable to stage restore archive.');
        }

        $manifest = $validation->manifest();
        $source_site_url = isset($manifest['source_site_url']) ? (string) $manifest['source_site_url'] : '';
        $source_home_url = isset($manifest['source_home_url']) ? (string) $manifest['source_home_url'] : '';
        $active_theme = isset($manifest['active_theme']) && is_scalar($manifest['active_theme']) ? (string) $manifest['active_theme'] : '';
        $active_plugins = isset($manifest['active_plugins']) && is_array($manifest['active_plugins']) ? array_values(array_map('strval', $manifest['active_plugins'])) : array();
        $must_use_plugins = isset($manifest['must_use_plugins']) && is_array($manifest['must_use_plugins']) ? array_values(array_map('strval', $manifest['must_use_plugins'])) : array();
        $source_table_prefix = isset($manifest['source_table_prefix']) && is_scalar($manifest['source_table_prefix']) ? (string) $manifest['source_table_prefix'] : '';
        $payload = array(
            'staged_archive' => $basename,
            'source_site_url' => $source_site_url,
            'source_home_url' => $source_home_url,
            'active_theme' => $active_theme,
            'active_plugins' => $active_plugins,
            'must_use_plugins' => $must_use_plugins,
            'source_table_prefix' => $source_table_prefix,
            'database_entry_count' => $validation->databaseEntryCount(),
            'archive_entry_count' => $validation->entryCount(),
        );
        $this->save($job_id, Job::COMPLETED, $payload);

        return new RestorePreparationResult(
            $job_id,
            $basename,
            $source_site_url,
            $source_home_url,
            $validation->databaseEntryCount(),
            $validation->entryCount(),
            Job::COMPLETED
        );
    }

    private function assertUpload(array $upload): void
    {
        $error = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Restore archive upload failed.');
        }

        $tmp_name = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
        if ($tmp_name === '' || !is_readable($tmp_name)) {
            throw new RuntimeException('Restore archive upload is not readable.');
        }

        $name = isset($upload['name']) ? (string) $upload['name'] : '';
        if ($this->packageExtension($name, $tmp_name) === '' && !is_dir($tmp_name)) {
            throw new RuntimeException('Restore package must be a .zip, .tar, or .tar.gz file.');
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create restore staging directory.');
        }
    }

    private function packageExtension(string $name, string $path): string
    {
        if (is_dir($path)) {
            return '';
        }

        $lower = strtolower($name);
        if (substr($lower, -7) === '.tar.gz') {
            return '.tar.gz';
        }
        if (substr($lower, -4) === '.tar') {
            return '.tar';
        }
        if (substr($lower, -4) === '.zip') {
            return '.zip';
        }

        return '';
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $this->ensureDirectory($destination);
        $entries = scandir($source);
        if ($entries === false) {
            throw new RuntimeException('Unable to read restore package directory.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source_path = rtrim($source, '/\\') . '/' . $entry;
            $destination_path = rtrim($destination, '/\\') . '/' . $entry;
            if (is_dir($source_path) && !is_link($source_path)) {
                $this->copyDirectory($source_path, $destination_path);
                continue;
            }

            if (!copy($source_path, $destination_path)) {
                throw new RuntimeException('Unable to stage restore package directory.');
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function save(string $job_id, string $state, array $payload): void
    {
        $this->jobs->save(new Job($job_id, 'restore', $state, $payload));
    }
}
