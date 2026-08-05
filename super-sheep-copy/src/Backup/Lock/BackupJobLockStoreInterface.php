<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Lock;

interface BackupJobLockStoreInterface
{
    /**
     * @param array{owner:string,expires_at:int} $value
     */
    public function add(string $name, array $value): bool;

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $name): ?array;

    /**
     * @param array<string,mixed> $expected
     */
    public function deleteIfUnchanged(string $name, array $expected): bool;
}
