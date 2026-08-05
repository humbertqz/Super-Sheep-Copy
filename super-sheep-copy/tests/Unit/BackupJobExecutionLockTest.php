<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Lock\BackupJobExecutionLock;
use SuperSheepCopy\Backup\Lock\BackupJobLockStoreInterface;

final class BackupJobExecutionLockTest extends TestCase
{
    public function testFirstRequestAcquiresMissingJobLock(): void
    {
        $store = new InMemoryBackupJobLockStore();
        $lock = new BackupJobExecutionLock($store, 120, new MutableLockClock(1000), new SequentialTokenGenerator());

        self::assertSame('owner-1', $lock->acquire('backup-123'));
        self::assertSame(array(
            'owner' => 'owner-1',
            'expires_at' => 1120,
        ), $store->get($this->optionName('backup-123')));
    }

    public function testLiveLeaseAllowsOnlyOneOwner(): void
    {
        $store = new InMemoryBackupJobLockStore();
        $lock = new BackupJobExecutionLock($store, 120, new MutableLockClock(1000), new SequentialTokenGenerator());

        self::assertSame('owner-1', $lock->acquire('backup-123'));
        self::assertNull($lock->acquire('backup-123'));
    }

    public function testExpiredLeaseCanBeReclaimed(): void
    {
        $store = new InMemoryBackupJobLockStore(array(
            $this->optionName('backup-123') => array(
                'owner' => 'dead-owner',
                'expires_at' => 999,
            ),
        ));
        $lock = new BackupJobExecutionLock($store, 120, new MutableLockClock(1000), new SequentialTokenGenerator());

        self::assertSame('owner-1', $lock->acquire('backup-123'));
        self::assertSame('owner-1', $store->get($this->optionName('backup-123'))['owner']);
    }

    public function testOldOwnerCannotReleaseReplacementLease(): void
    {
        $store = new InMemoryBackupJobLockStore();
        $clock = new MutableLockClock(1000);
        $tokens = new SequentialTokenGenerator();
        $lock = new BackupJobExecutionLock($store, 120, $clock, $tokens);
        $old_owner = $lock->acquire('backup-123');
        $clock->now = 1121;
        $new_owner = $lock->acquire('backup-123');

        $lock->release('backup-123', (string) $old_owner);

        self::assertNull($lock->acquire('backup-123'));
        $lock->release('backup-123', (string) $new_owner);
        self::assertSame('owner-4', $lock->acquire('backup-123'));
    }

    public function testTokenGenerationFailureLeavesJobUnlocked(): void
    {
        $store = new InMemoryBackupJobLockStore();
        $lock = new BackupJobExecutionLock(
            $store,
            120,
            new MutableLockClock(1000),
            static function (): string {
                throw new \RuntimeException('secure random source failed');
            }
        );

        self::assertNull($lock->acquire('backup-123'));
        self::assertNull($store->get($this->optionName('backup-123')));
    }

    private function optionName(string $job_id): string
    {
        return 'super_sheep_copy_backup_lock_' . hash('sha256', $job_id);
    }
}

final class InMemoryBackupJobLockStore implements BackupJobLockStoreInterface
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values = array())
    {
        $this->values = $values;
    }

    public function add(string $name, array $value): bool
    {
        if (isset($this->values[$name])) {
            return false;
        }

        $this->values[$name] = $value;

        return true;
    }

    /** @return mixed */
    public function get(string $name)
    {
        return $this->values[$name] ?? null;
    }

    /** @param mixed $expected */
    public function deleteIfUnchanged(string $name, $expected): bool
    {
        if (!isset($this->values[$name]) || $this->values[$name] !== $expected) {
            return false;
        }

        unset($this->values[$name]);

        return true;
    }
}

final class MutableLockClock
{
    public int $now;

    public function __construct(int $now)
    {
        $this->now = $now;
    }

    public function __invoke(): int
    {
        return $this->now;
    }
}

final class SequentialTokenGenerator
{
    private int $sequence = 0;

    public function __invoke(): string
    {
        $this->sequence++;

        return 'owner-' . $this->sequence;
    }
}
