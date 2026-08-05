<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Lock;

interface BackupJobLockStoreInterface
{
    /**
     * @param array{owner:string,expires_at:int} $value
     */
    public function add(string $name, array $value): bool;

    /** @return mixed */
    public function get(string $name);

    /** @param mixed $expected */
    public function deleteIfUnchanged(string $name, $expected): bool;
}
