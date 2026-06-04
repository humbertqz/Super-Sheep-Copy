<?php

declare(strict_types=1);

namespace SuperSheepCopy\Jobs;

interface JobRepositoryInterface
{
    public function save(Job $job): void;

    public function delete(string $id): void;

    public function find(string $id): ?Job;

    /**
     * @return list<Job>
     */
    public function all(): array;
}
