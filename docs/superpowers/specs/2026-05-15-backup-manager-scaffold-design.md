# Backup Manager Scaffold Design

## Goal

Add a synchronous `BackupManager` scaffold that coordinates the first real backup workflow steps:

- Create a backup working directory.
- Create and save a backup job.
- Run database export into the working directory.
- Scan site files.
- Return a summary result for later archive packaging.

This slice creates the orchestration contract. It does not create ZIP archives, expose admin form handling, run AJAX/background jobs, or wire live `$wpdb` in `Plugin`.

## Scope

Included:

- `BackupOptions` value object.
- `BackupResult` value object.
- `BackupManager` service.
- Unit tests with fake job repository, fake database coordinator, temporary directories, and local file fixtures.
- Job state transitions:
  - `created`
  - `exporting_database`
  - `scanning_files`
  - `completed`

Excluded:

- ZIP archive creation.
- Admin form submission.
- AJAX or REST job runner.
- WP-Cron.
- Real `$wpdb` service wiring.
- Download links.
- Restore work.

## Architecture

Add classes under `super-sheep-copy/src/Backup/`.

`BackupOptions` contains:

- `site_root`
- `working_base_directory`
- `table_prefix`
- `table_selection_mode`
- `database_chunk_size`

`BackupResult` contains:

- `job_id`
- `working_directory`
- `database_directory`
- `scanned_file_count`
- `state`

`BackupManager` receives:

- `JobRepositoryInterface`
- `DatabaseBackupCoordinator`
- `FileScanner`

It exposes:

```php
public function run(BackupOptions $options): BackupResult
```

## Data Flow

1. Generate a unique backup job ID.
2. Create a working directory under `working_base_directory`.
3. Save a `Job` with state `created`.
4. Save state `exporting_database`.
5. Run `DatabaseBackupCoordinator::export()` with the working directory, table prefix, selection mode, and chunk size.
6. Save state `scanning_files`.
7. Run `FileScanner::scan()` against the site root.
8. Save state `completed` with a payload containing working directory, database directory, and scanned file count.
9. Return `BackupResult`.

## Error Handling

`BackupOptions` rejects empty paths, empty table prefix, and chunk sizes less than one.

`BackupManager` throws `RuntimeException` when the working directory cannot be created.

If database export or file scanning throws, failure-state handling is left for a later job-runner slice. This keeps the first manager contract small and deterministic.

## Testing

Unit tests cover:

- Successful run creates a working directory.
- Database export is invoked with expected options.
- File scanner counts eligible files.
- Job repository receives states in order.
- Result contains job ID, working directory, database directory, file count, and completed state.
- Invalid options reject empty paths and invalid chunk size.

Tests use temporary directories and no WordPress bootstrap.

## Future Work

The next slice should add archive packaging: feed the working directory database output, scanned files, manifest metadata, and checksums into `ArchiveWriter`.

