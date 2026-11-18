<?php

declare(strict_types=1);

namespace SuperSheepCopy\Jobs;

use RuntimeException;

final class OptionJobRepository implements JobRepositoryInterface, RefreshableJobRepositoryInterface
{
    private const LEGACY_OPTION = 'super_sheep_copy_jobs';
    private const JOB_OPTION_PREFIX = 'super_sheep_copy_job_';

    public function save(Job $job): void
    {
        $this->writeJobOption($this->optionName($job->id()), $job->toArray());
    }

    public function delete(string $id): void
    {
        $option = $this->optionName($id);
        $legacy = $this->legacyJobs();
        if (isset($legacy[$id])) {
            // Keep legacy data read-only so deleting one job cannot overwrite another job's changes.
            $this->writeJobOption($option, array());
        } elseif (!delete_option($option) && $this->readJobOption($option) !== null) {
            throw new RuntimeException('Unable to delete the saved job.');
        }
    }

    public function find(string $id): ?Job
    {
        $data = $this->readJobOption($this->optionName($id));
        if ($data === null) {
            $data = $this->legacyJobs()[$id] ?? null;
        }

        return is_array($data) && ($data['id'] ?? null) === $id ? Job::fromArray($data) : null;
    }

    public function all(): array
    {
        $jobs = array();

        foreach ($this->legacyJobs() as $id => $data) {
            if (is_array($data) && ($data['id'] ?? null) === (string) $id) {
                $jobs[$this->optionName((string) $id)] = Job::fromArray($data);
            }
        }
        foreach ($this->jobOptions() as $row) {
            $data = maybe_unserialize($row['option_value']);
            if (is_array($data) && isset($data['id']) && is_string($data['id'])) {
                $jobs[$row['option_name']] = Job::fromArray($data);
            } else {
                unset($jobs[$row['option_name']]);
            }
        }
        return array_values($jobs);
    }

    public function refresh(): void
    {
        wp_cache_delete(self::LEGACY_OPTION, 'options');
    }

    public function deleteAll(): void
    {
        foreach ($this->jobOptions() as $row) {
            delete_option($row['option_name']);
        }
        delete_option(self::LEGACY_OPTION);
    }

    private function optionName(string $id): string
    {
        return self::JOB_OPTION_PREFIX . hash('sha256', $id);
    }

    /** @param array<string,mixed> $data */
    private function writeJobOption(string $option, array $data): void
    {
        if (!update_option($option, $data, false) && $this->readJobOption($option) !== $data) {
            throw new RuntimeException('Unable to save the job state.');
        }
    }

    /** @return mixed */
    private function readJobOption(string $option)
    {
        global $wpdb;
        // Read the persisted checkpoint after acquiring the job lock, including on hosts with an object cache.
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Unable to read the saved job.');
        }
        return maybe_unserialize($value);
    }

    /** @return array<int,array{option_name:string,option_value:string}> */
    private function jobOptions(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id",
            $wpdb->esc_like(self::JOB_OPTION_PREFIX) . '%'
        ), 'ARRAY_A');
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException('Unable to list saved jobs.');
        }
        return $rows;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function legacyJobs(): array
    {
        $jobs = get_option(self::LEGACY_OPTION, array());

        return is_array($jobs) ? $jobs : array();
    }
}
