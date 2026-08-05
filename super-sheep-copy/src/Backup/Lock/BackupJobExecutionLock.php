<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Lock;

use Closure;

final class BackupJobExecutionLock implements BackupJobExecutionLockInterface
{
    private const DEFAULT_LEASE_SECONDS = 120;

    private BackupJobLockStoreInterface $store;
    private int $lease_seconds;
    private Closure $clock;
    private Closure $token_generator;

    public function __construct(
        BackupJobLockStoreInterface $store,
        int $lease_seconds = self::DEFAULT_LEASE_SECONDS,
        ?callable $clock = null,
        ?callable $token_generator = null
    ) {
        $this->store = $store;
        $this->lease_seconds = max(1, $lease_seconds);
        $this->clock = Closure::fromCallable($clock ?? static function (): int {
            return time();
        });
        $this->token_generator = Closure::fromCallable($token_generator ?? static function (): string {
            return bin2hex(random_bytes(16));
        });
    }

    public function acquire(string $job_id): ?string
    {
        $name = $this->optionName($job_id);
        $owner = (string) ($this->token_generator)();
        $value = array(
            'owner' => $owner,
            'expires_at' => (int) ($this->clock)() + $this->lease_seconds,
        );

        if ($this->store->add($name, $value)) {
            return $owner;
        }

        $existing = $this->store->get($name);
        if (
            $existing !== null
            && isset($existing['expires_at'])
            && is_numeric($existing['expires_at'])
            && (int) $existing['expires_at'] > (int) ($this->clock)()
        ) {
            return null;
        }

        if ($existing === null || !$this->store->deleteIfUnchanged($name, $existing)) {
            return null;
        }

        return $this->store->add($name, $value) ? $owner : null;
    }

    public function release(string $job_id, string $owner_token): void
    {
        $name = $this->optionName($job_id);
        $existing = $this->store->get($name);
        if (
            $existing === null
            || !isset($existing['owner'])
            || !is_scalar($existing['owner'])
            || !hash_equals((string) $existing['owner'], $owner_token)
        ) {
            return;
        }

        $this->store->deleteIfUnchanged($name, $existing);
    }

    private function optionName(string $job_id): string
    {
        return 'super_sheep_copy_backup_lock_' . hash('sha256', $job_id);
    }
}
