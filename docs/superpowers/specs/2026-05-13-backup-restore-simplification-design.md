# Backup and Restore Simplification Design

## Goal

Make the plugin work more predictably by reducing backup and restore conflicts. The main objective is maintainability and operational reliability, not adding new restore features.

## Current Problem

The plugin currently has two backup execution paths:

- `SSC_Backup_Manager::start()` runs a complete synchronous backup.
- `SSC_Backup_Manager::init()` plus `advance()` runs a resumable chunked backup through AJAX.

Manual backups use the chunked path, while restore creates its pre-restore safety snapshot by calling the synchronous path. This creates duplicate behavior for locks, state, cleanup, progress, and errors. It also makes restore harder to reason about because it starts a nested backup using a different execution model before destructive restore steps.

## Recommended Approach

Use one canonical backup job lifecycle for all backup creation:

1. Prepare the job.
2. Dump the database.
3. Create or reopen the ZIP.
4. Scan files.
5. Add files in batches.
6. Finalize the manifest, checksum, and metadata.
7. Release locks and persist final state.

Manual backups and pre-restore safety snapshots should use this same lifecycle. The restore flow should coordinate a snapshot through the canonical backup job instead of directly running a separate synchronous backup engine.

## Architecture

`SSC_Backup_Manager` remains the backup orchestrator, but its public surface should center on the chunked job flow:

- `init()` creates the job state and performs the setup work.
- `advance()` processes file batches and finalizes the job.
- Shared helpers handle lock acquisition, cleanup, failure state, manifest creation, checksum writing, and metadata writing.

The older `start()` method should no longer be the restore snapshot mechanism. It may be kept temporarily as a compatibility wrapper or test helper, but new production flows should not depend on it.

`SSC_Restore_Manager` remains mostly background/synchronous for the first implementation. It should not be converted to a fully chunked restore yet because restore touches files, the database, sessions, cache, and self-update behavior. The first simplification should target the known conflict point: nested pre-restore backup creation.

## Data Flow

Manual backup:

1. Admin AJAX creates a backup job with `init()`.
2. Browser calls `advance()` repeatedly until completion.
3. Backup state lives in `ssc_backup_state_<job_id>`.

Pre-restore snapshot:

1. Restore requests a safety snapshot before destructive operations.
2. The snapshot is created through the same backup job lifecycle as manual backups.
3. Restore proceeds only after the snapshot completes, or after a snapshot failure is logged and explicitly treated as non-fatal.

Restore:

1. Validate ZIP and manifest.
2. Create/coordinate safety snapshot.
3. Enable maintenance mode.
4. Restore files and database.
5. Rewrite destination-specific config, URLs, cache rules, and locks.
6. Mark restore complete and destroy sessions.

## Conflict Avoidance

Backup creation should use one lock model:

- `ssc_backup_running` for active backup work.
- `ssc_backup_state_<job_id>` for job progress and result.
- Per-job advance locks only to prevent duplicate `advance()` calls for the same offset.

Restore should use only its own restore lock:

- `ssc_restore_running` for active restore work.
- `ssc_restore_state_<job_id>` for restore progress and result.

Restore may coordinate a backup snapshot, but it should not introduce a separate backup state model.

## Error Handling

Backup failure should clean up incomplete ZIPs, checksum files, metadata files, temporary SQL dumps, and file-list JSON. The same cleanup path should apply to manual backups and pre-restore snapshots.

Snapshot failure during restore remains non-fatal for now, matching current behavior, but it must be explicit in logs and state transitions. Restore should not silently hide snapshot failures.

Restore failure should continue to disable maintenance mode, release the restore lock, write failed state, and log the error.

## Testing

Focused tests should cover:

- Manual backup still uses the chunked lifecycle.
- Pre-restore snapshot uses the same backup lifecycle as manual backup.
- Backup lock conflicts are handled consistently.
- Failed chunked backups clean up partial artifacts.
- Restore proceeds when snapshot failure is intentionally tolerated.
- Restore does not call the old synchronous backup path for snapshots.

## Out of Scope

This design does not convert restore into a fully resumable chunked restore. That may be useful later, but it is larger and riskier than needed for the current goal.

This design does not change the admin UI behavior except where needed to preserve accurate status and error reporting.
