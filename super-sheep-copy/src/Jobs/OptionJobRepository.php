<?php

declare(strict_types=1);

namespace SuperSheepCopy\Jobs;

final class OptionJobRepository implements JobRepositoryInterface
{
    private const OPTION = 'super_sheep_copy_jobs';

    public function save(Job $job): void
    {
        $jobs = $this->rawJobs();
        $jobs[$job->id()] = $job->toArray();
        update_option(self::OPTION, $jobs, false);
    }

    public function delete(string $id): void
    {
        $jobs = $this->rawJobs();
        unset($jobs[$id]);
        update_option(self::OPTION, $jobs, false);
    }

    public function find(string $id): ?Job
    {
        $jobs = $this->rawJobs();

        if (!isset($jobs[$id]) || !is_array($jobs[$id])) {
            return null;
        }

        return Job::fromArray($jobs[$id]);
    }

    public function all(): array
    {
        $jobs = array();

        foreach ($this->rawJobs() as $job) {
            if (is_array($job)) {
                $jobs[] = Job::fromArray($job);
            }
        }

        return $jobs;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawJobs(): array
    {
        $jobs = get_option(self::OPTION, array());

        return is_array($jobs) ? $jobs : array();
    }
}
