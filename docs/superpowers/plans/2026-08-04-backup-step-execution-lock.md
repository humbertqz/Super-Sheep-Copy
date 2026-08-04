# Backup Step Execution Lock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent AJAX retries and overlapping cron callbacks from executing the same backup job step concurrently.

**Architecture:** Add a database-backed lease service under `src/Backup/Lock`. Atomic `add_option()` acquisition and ownership-safe compare/delete operations provide one lock per job with stale-lock recovery. Inject the shared abstraction into the AJAX and scheduled execution boundaries; keep `BackupStepRunner` unchanged.

**Tech Stack:** PHP 7.4+, WordPress Options API and `$wpdb`, PHPUnit 9.6.

---

## File Structure

- Create `super-sheep-copy/src/Backup/Lock/BackupJobExecutionLockInterface.php`: execution-boundary contract.
- Create `super-sheep-copy/src/Backup/Lock/BackupJobLockStoreInterface.php`: atomic persistence contract.
- Create `super-sheep-copy/src/Backup/Lock/BackupJobExecutionLock.php`: lease acquisition, expiry, and owner-token behavior.
- Create `super-sheep-copy/src/Backup/Lock/WordPressOptionBackupJobLockStore.php`: WordPress option storage with compare/delete through `$wpdb`.
- Create `super-sheep-copy/tests/Unit/BackupJobExecutionLockTest.php`: core lease regression coverage.
- Modify `super-sheep-copy/src/Admin/BackupStepAjaxHandler.php`: acquire/release around one AJAX step.
- Modify `super-sheep-copy/tests/Unit/BackupStepAjaxHandlerTest.php`: busy and release-path tests.
- Modify `super-sheep-copy/src/Schedule/ScheduledBackupRunner.php`: acquire/release per scheduled step.
- Modify `super-sheep-copy/tests/Unit/ScheduledBackupRunnerTest.php`: contention and per-step release tests.
- Modify `super-sheep-copy/src/Admin/AdminMenu.php`: inject the production lock into the AJAX handler.
- Modify `super-sheep-copy/src/Plugin.php`: construct and share the production lock service.
- Modify `super-sheep-copy/tests/Unit/PluginTest.php`: verify production wiring remains bootable.

### Task 1: Implement ownership-safe expiring job leases

**Files:**
- Create: `super-sheep-copy/src/Backup/Lock/BackupJobExecutionLockInterface.php`
- Create: `super-sheep-copy/src/Backup/Lock/BackupJobLockStoreInterface.php`
- Create: `super-sheep-copy/src/Backup/Lock/BackupJobExecutionLock.php`
- Create: `super-sheep-copy/src/Backup/Lock/WordPressOptionBackupJobLockStore.php`
- Create: `super-sheep-copy/tests/Unit/BackupJobExecutionLockTest.php`

- [ ] **Step 1: Write failing service tests**

Create an in-memory store in the test and cover first acquisition, live
contention, expired-lock reclamation, and rejection of an old owner's release:

```php
public function testLiveLeaseAllowsOnlyOneOwner(): void
{
    $store = new InMemoryBackupJobLockStore();
    $lock = new BackupJobExecutionLock($store, 120, static fn (): int => 1000, new SequentialTokenGenerator());

    $owner = $lock->acquire('backup-123');

    self::assertSame('owner-1', $owner);
    self::assertNull($lock->acquire('backup-123'));
}

public function testExpiredLeaseCanBeReclaimed(): void
{
    $store = new InMemoryBackupJobLockStore(array(
        $this->optionName('backup-123') => array('owner' => 'dead-owner', 'expires_at' => 999),
    ));
    $lock = new BackupJobExecutionLock($store, 120, static fn (): int => 1000, new SequentialTokenGenerator());

    self::assertSame('owner-1', $lock->acquire('backup-123'));
}

public function testOldOwnerCannotReleaseReplacementLease(): void
{
    $store = new InMemoryBackupJobLockStore();
    $clock = new MutableLockClock(1000);
    $lock = new BackupJobExecutionLock($store, 120, $clock, new SequentialTokenGenerator());
    $old_owner = $lock->acquire('backup-123');
    $clock->now = 1121;
    $new_owner = $lock->acquire('backup-123');

    $lock->release('backup-123', (string) $old_owner);

    self::assertNull($lock->acquire('backup-123'));
    $lock->release('backup-123', (string) $new_owner);
    self::assertSame('owner-4', $lock->acquire('backup-123'));
}
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
cd super-sheep-copy
vendor/bin/phpunit tests/Unit/BackupJobExecutionLockTest.php
```

Expected: FAIL because the lock contracts and implementation do not exist.

- [ ] **Step 3: Implement the lock contracts and lease service**

Use this public contract:

```php
interface BackupJobExecutionLockInterface
{
    public function acquire(string $job_id): ?string;

    public function release(string $job_id, string $owner_token): void;
}
```

Use a small atomic store contract:

```php
interface BackupJobLockStoreInterface
{
    /** @param array{owner:string,expires_at:int} $value */
    public function add(string $name, array $value): bool;

    /** @return array<string,mixed>|null */
    public function get(string $name): ?array;

    /** @param array<string,mixed> $expected */
    public function deleteIfUnchanged(string $name, array $expected): bool;
}
```

`BackupJobExecutionLock` must:

```php
private function optionName(string $job_id): string
{
    return 'super_sheep_copy_backup_lock_' . hash('sha256', $job_id);
}

public function acquire(string $job_id): ?string
{
    $name = $this->optionName($job_id);
    $owner = ($this->token_generator)();
    $value = array('owner' => $owner, 'expires_at' => ($this->clock)() + $this->lease_seconds);

    if ($this->store->add($name, $value)) {
        return $owner;
    }

    $existing = $this->store->get($name);
    if ($existing !== null && isset($existing['expires_at']) && is_numeric($existing['expires_at']) && (int) $existing['expires_at'] > ($this->clock)()) {
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
    if ($existing === null || !isset($existing['owner']) || !hash_equals((string) $existing['owner'], $owner_token)) {
        return;
    }

    $this->store->deleteIfUnchanged($name, $existing);
}
```

Store the injected clock and token generator as `Closure` properties using
`Closure::fromCallable()`. The production token generator returns
`bin2hex(random_bytes(16))`.

`WordPressOptionBackupJobLockStore::add()` calls
`add_option($name, $value, '', 'no')`. Its compare/delete serializes the exact
expected value with `maybe_serialize()`, then performs one conditional delete:

```php
$deleted = $this->wpdb->delete(
    $this->wpdb->options,
    array('option_name' => $name, 'option_value' => maybe_serialize($expected)),
    array('%s', '%s')
);
if ($deleted === 1) {
    wp_cache_delete($name, 'options');
    return true;
}
return false;
```

- [ ] **Step 4: Run the focused test and verify GREEN**

Run `vendor/bin/phpunit tests/Unit/BackupJobExecutionLockTest.php`.

Expected: PASS with all four lease behaviors covered.

- [ ] **Step 5: Commit the core lease service**

```bash
git add super-sheep-copy/src/Backup/Lock super-sheep-copy/tests/Unit/BackupJobExecutionLockTest.php
git commit -m "feat: add backup job execution lease"
```

### Task 2: Guard AJAX backup steps

**Files:**
- Modify: `super-sheep-copy/src/Admin/BackupStepAjaxHandler.php`
- Modify: `super-sheep-copy/tests/Unit/BackupStepAjaxHandlerTest.php`

- [ ] **Step 1: Write failing AJAX contention and release tests**

Add a recording fake implementing `BackupJobExecutionLockInterface`. Verify a
busy result never calls the step runner and returns the current state:

```php
public function testBusyJobReturnsCurrentProgressWithoutRunningStep(): void
{
    $job = new Job('backup-123', 'backup', Job::PACKAGING_ARCHIVE, array('message' => 'Packaging archive.'));
    $jobs = new BackupStepAjaxJobRepository(array($job));
    $runner = new BackupStepAjaxRunner();
    $lock = new BackupStepAjaxLock(null);
    $handler = new BackupStepAjaxHandler(new Capability(), new Nonce(), $jobs, $runner, $lock);

    $this->sendRequest($handler, 'backup-123');

    self::assertNull($runner->receivedJob());
    self::assertSame(Job::PACKAGING_ARCHIVE, $GLOBALS['ssc_test_json_response']['data']['state']);
    self::assertSame('Another backup step is still running.', $GLOBALS['ssc_test_json_response']['data']['message']);
}
```

Add one successful-run test and one throwing-runner test asserting the fake
records `release('backup-123', 'ajax-owner')` in both paths.

- [ ] **Step 2: Run the AJAX test and verify RED**

Run `vendor/bin/phpunit tests/Unit/BackupStepAjaxHandlerTest.php`.

Expected: FAIL because the handler does not accept or use the lock.

- [ ] **Step 3: Add lock acquisition and guaranteed release**

Inject `BackupJobExecutionLockInterface` as the fifth constructor dependency.
After the foreign-site guard and before retry-state mutation, acquire the job
lock. On contention, build a response from a temporary copy of the current job
whose message is `Another backup step is still running.` without saving it.

Wrap retry handling and `runStep()` in:

```php
$owner_token = $this->lock->acquire($job->id());
if ($owner_token === null) {
    $payload = $job->payload();
    $payload['message'] = 'Another backup step is still running.';
    wp_send_json_success($this->responsePayload(new Job($job->id(), $job->type(), $job->state(), $payload)));
}

try {
    // Existing retry and step execution behavior.
} finally {
    $this->lock->release($job->id(), $owner_token);
}
```

Keep the existing conversion of runner exceptions to failed jobs inside the
outer `try`; the lock release must not suppress that response.

- [ ] **Step 4: Run the AJAX test and verify GREEN**

Run `vendor/bin/phpunit tests/Unit/BackupStepAjaxHandlerTest.php`.

Expected: PASS, including existing capability, nonce, retry, and site-guard
coverage.

- [ ] **Step 5: Commit AJAX locking**

```bash
git add super-sheep-copy/src/Admin/BackupStepAjaxHandler.php super-sheep-copy/tests/Unit/BackupStepAjaxHandlerTest.php
git commit -m "fix: serialize ajax backup steps"
```

### Task 3: Guard scheduled steps and wire production storage

**Files:**
- Modify: `super-sheep-copy/src/Schedule/ScheduledBackupRunner.php`
- Modify: `super-sheep-copy/tests/Unit/ScheduledBackupRunnerTest.php`
- Modify: `super-sheep-copy/src/Admin/AdminMenu.php`
- Modify: `super-sheep-copy/src/Plugin.php`
- Modify: `super-sheep-copy/tests/Unit/PluginTest.php`

- [ ] **Step 1: Write failing scheduled-lock tests**

Add a busy-lock test asserting zero step-runner calls and a newly scheduled
continuation. Add a recording lock that yields three different owner tokens,
then assert three acquire/release pairs around the existing three-step loop.

```php
self::assertSame(array(
    'acquire:backup-scheduled', 'release:backup-scheduled:owner-1',
    'acquire:backup-scheduled', 'release:backup-scheduled:owner-2',
    'acquire:backup-scheduled', 'release:backup-scheduled:owner-3',
), $lock->events);
```

- [ ] **Step 2: Run scheduled tests and verify RED**

Run `vendor/bin/phpunit tests/Unit/ScheduledBackupRunnerTest.php`.

Expected: FAIL because scheduled execution does not use a lock.

- [ ] **Step 3: Lock each scheduled step**

Inject `BackupJobExecutionLockInterface` into `ScheduledBackupRunner`. In each
loop iteration, acquire before `runStep()`. If busy, schedule a continuation and
return. Release in `finally` immediately after that single step.

```php
$owner_token = $this->lock->acquire($job->id());
if ($owner_token === null) {
    $this->events->scheduleContinuation();
    return;
}

try {
    $job = $this->step_runner->runStep($job);
    $this->jobs->save($job);
} finally {
    $this->lock->release($job->id(), $owner_token);
}
```

- [ ] **Step 4: Wire one production service into both execution boundaries**

In `Plugin::boot()` construct:

```php
$backup_lock = new BackupJobExecutionLock(new WordPressOptionBackupJobLockStore($wpdb));
```

Pass it to `ScheduledBackupRunner` and `AdminMenu`. Store it on `AdminMenu` and
pass it into `BackupStepAjaxHandler`. Update direct constructor calls in tests.

- [ ] **Step 5: Run focused integration tests and verify GREEN**

Run:

```bash
vendor/bin/phpunit tests/Unit/ScheduledBackupRunnerTest.php tests/Unit/BackupStepAjaxHandlerTest.php tests/Unit/PluginTest.php
```

Expected: PASS.

- [ ] **Step 6: Run complete verification**

Run:

```bash
composer test
composer lint
```

Expected: all PHPUnit tests pass and every PHP file reports no syntax errors.

- [ ] **Step 7: Commit production integration**

```bash
git add super-sheep-copy/src/Schedule/ScheduledBackupRunner.php super-sheep-copy/src/Admin/AdminMenu.php super-sheep-copy/src/Plugin.php super-sheep-copy/tests/Unit/ScheduledBackupRunnerTest.php super-sheep-copy/tests/Unit/PluginTest.php
git commit -m "fix: prevent overlapping backup steps"
```
