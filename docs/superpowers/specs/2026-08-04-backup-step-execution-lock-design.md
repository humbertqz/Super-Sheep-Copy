# Backup Step Execution Lock Design

## Goal

Prevent overlapping requests from executing the same backup job step when a
limited host or reverse proxy times out before the original PHP request has
finished.

## Problem

The admin client retries transient HTTP failures after five seconds. A timeout
only proves that the client stopped waiting; it does not prove that PHP stopped
executing. The retry can therefore load the same saved job state while the
first request is still exporting a database chunk, updating an archive, or
saving progress. Scheduled continuation events can overlap the same work as
well.

Concurrent steps can overwrite job progress, repeat database work, or open the
same archive for writing at the same time. Archive corruption is the highest
risk because retries are most likely during expensive packaging work.

## Design

Add a focused `BackupJobExecutionLock` service that stores one non-autoloaded
WordPress option per backup job. The option name will contain a SHA-256 digest
of the job ID, keeping the database key bounded and preventing unsafe option
names. Its value will contain a cryptographically random owner token and a UTC
expiry timestamp.

Acquisition will first use `add_option()`. The unique option name makes the
database insert the atomic contention point: exactly one request can acquire a
missing lock. If the option already exists and has not expired, acquisition
returns a busy result without running a backup step.

An expired lock may be reclaimed only through an ownership-safe compare and
delete operation. Release uses the same comparison: a request deletes the lock
only when the stored owner token still matches its token. This prevents an old
request from deleting a replacement lock acquired by a newer request. The
lock's database compare/delete operation will be isolated behind the service so
callers do not depend on WordPress option serialization details.

The default lease will be 120 seconds. This is longer than the current maximum
45-second archive work budget plus normal WordPress request overhead, while
still allowing recovery after a PHP fatal or forcibly terminated request.

`BackupStepAjaxHandler` and `ScheduledBackupRunner` will use the same lock
service. Each caller will:

1. Attempt to acquire the job lock.
2. Return or reschedule without executing when the job is already locked.
3. Run exactly the existing backup step after successful acquisition.
4. Release the acquired lock in a `finally` block.

The runner itself will remain unaware of locking. This keeps its unit-level
state-machine behavior unchanged and lets each execution boundary provide the
correct busy response: AJAX returns the current job with a safe progress
message, while scheduled execution queues another continuation event.

## AJAX Behavior

When the lock is busy, the AJAX endpoint will return a successful response for
the current job without changing its persisted payload or state. The response
message will say that another backup step is still running. The existing client
will wait briefly and request another step, so transient timeouts remain
recoverable without starting duplicate work.

The endpoint will not mark lock contention as a failed backup and will not show
the manual retry control.

## Scheduled Backup Behavior

The scheduled continuation handler will attempt to acquire the lock before
each step. If the lock is busy, it will stop the current cron callback and
schedule another continuation. A lock acquired for one step will be released
before the next loop iteration, preserving the current three-step limit while
preventing overlap with AJAX or another cron callback.

Reducing scheduled callbacks to one step and adapting request budgets belong to
the next limited-host slice; this specification changes only concurrency.

## Error Handling

- Failure to generate a secure owner token prevents acquisition and leaves the
  job unchanged.
- A malformed lock value is treated as expired but can be removed only by an
  exact compare/delete operation.
- Exceptions from backup execution follow the existing failure behavior, and
  the `finally` block still attempts lock release.
- A release failure does not replace the backup result or exception. The lease
  expires and permits later recovery.

## Testing

Add focused unit coverage proving that:

- The first request acquires a missing job lock.
- A second request cannot acquire a live lock.
- An expired lock can be reclaimed.
- An old owner cannot release a replacement lock.
- The AJAX handler does not invoke the step runner when the lock is busy.
- The AJAX handler releases the lock after successful and failed execution.
- Scheduled continuation reschedules without executing when the lock is busy.
- Scheduled continuation releases the lock between executed steps.

Run the focused PHPUnit tests, the complete PHPUnit suite, and PHP syntax
checks.

## Scope

This slice covers backup-step mutual exclusion for admin AJAX and scheduled
execution. It does not change archive streaming, checksum persistence,
WordPress job storage, adaptive execution limits, TAR/GZIP finalization, or
disk-space preflight behavior.
