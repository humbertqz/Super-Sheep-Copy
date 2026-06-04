# Normal User Settings V1 Design

## Problem

The Settings page currently shows only the backup storage path and placeholder copy. The first useful version should give normal WordPress administrators safe defaults for future backups without exposing advanced restore or performance controls that can create broken backups, unsafe restores, or confusing support cases.

## Goals

1. Add a Settings page focused on normal users and safe presets.
2. Let administrators control only low-risk defaults for future backup jobs.
3. Make storage cleanup and diagnostics visible without requiring technical knowledge.
4. Keep running, completed, and existing queued backup jobs stable after settings changes.
5. Preserve current security model with capability checks, nonces, sanitization, and escaped output.

## Non-Goals

- Do not add per-backup override controls on the Backup page in V1.
- Do not expose database table selection.
- Do not expose performance profiles, chunk sizes, request time budgets, or aggressive modes.
- Do not expose restore safety toggles such as disabling URL replacement, disabling preflight, replacing absolute paths, or bypassing confirmation.
- Do not allow editing the backup storage directory in V1.
- Do not change existing backup archive format.

## Recommended Approach

Use one WordPress option named `super_sheep_copy_settings` for normal-user settings. The Settings page will show three sections:

1. Backup Defaults
2. Storage & Cleanup
3. Diagnostics

Settings apply only to new backup jobs created after the settings are saved. Backup job creation copies the effective settings into the job payload. Backup runners read the job payload snapshot, not the live option, so changes made during a running backup do not alter that job.

## Settings

The option value will be an associative array:

```php
array(
    'exclude_cache_files' => true,
    'skip_large_files' => true,
    'large_file_limit_mb' => 250,
    'retention_count' => 5,
    'auto_clean_failed_jobs' => true,
    'debug_logging' => false,
)
```

Defaults are applied whenever the option is missing or partially invalid.

## Backup Defaults

The page will present backup behavior as a full-site backup preset with a few safe controls:

- `Exclude cache folders`: checkbox, default on.
- `Skip very large files`: checkbox, default on.
- `Large file limit`: number input in MB, default `250`, enabled when skipping large files is on.
- `Keep successful backups`: number input, default `5`.

Validation rules:

- `exclude_cache_files`: boolean.
- `skip_large_files`: boolean.
- `large_file_limit_mb`: integer from `10` to `2048`.
- `retention_count`: integer from `1` to `20`.

Backup creation will store these values in the job payload. Backup execution will use them as follows:

- Cache directories are excluded when `exclude_cache_files` is true.
- Files larger than `large_file_limit_mb` are skipped when `skip_large_files` is true.
- Skipped files are recorded in backup metadata or job diagnostics so the Backup page can show a count and make support/debugging possible.
- Retention cleanup runs after a successful backup and removes older successful backups beyond `retention_count`.

## Storage & Cleanup

The page will show safe storage information and cleanup controls:

- `Backup storage path`: readonly value from `Plugin::backupDirectory()`.
- `Current storage used`: readonly summary for the backup storage directory.
- `Auto-clean failed backup files`: checkbox, default on.
- `Clean failed backup files`: button.

Validation rules:

- `auto_clean_failed_jobs`: boolean.

Behavior:

- Auto-clean removes failed job temporary directories older than 24 hours.
- Manual cleanup removes failed job temporary directories that are no longer associated with running jobs.
- Cleanup actions require the same backup-management capability and a valid nonce.
- Cleanup does not delete successful backup archives.

## Diagnostics

The page will expose support-oriented diagnostics:

- `Debug logging`: checkbox, default off.
- `Download diagnostic report`: button.
- `Last backup summary`: readonly status, if available.

Validation rules:

- `debug_logging`: boolean.

Diagnostic report should include:

- Plugin version.
- WordPress version.
- PHP version.
- Backup storage path writability.
- ZIP support status.
- Relevant PHP limits.
- Last backup status, size, duration, and skipped file count when available.

Diagnostic report must not include secrets, database passwords, nonce values, restore tokens, full backup archive contents, or private file contents.

## Backup Page Integration

The Backup page will not provide per-backup overrides in V1. It should show a concise summary near the backup action so administrators understand what settings will apply:

- `Cache folders excluded`
- `Files over 250 MB skipped`
- `Keeping last 5 successful backups`

The summary reads the saved settings before backup creation. Once the job is created, the job payload snapshot is the source of truth for that job.

## Data Flow

1. Settings page loads defaults plus saved option value.
2. Administrator changes values and submits the form.
3. Save handler checks capability and nonce.
4. Save handler sanitizes and validates values.
5. Save handler stores `super_sheep_copy_settings`.
6. Backup page reads current settings and displays the short summary.
7. New backup job copies current settings into job payload.
8. Backup runner reads settings from job payload.
9. Successful backup triggers retention cleanup.
10. Failed-job cleanup uses saved cleanup setting or manual cleanup action.

## Error Handling

Invalid submitted values are clamped or reset to defaults rather than saved raw. Save failures should show a generic admin notice. Cleanup failures should preserve remaining files and report a concise failure notice. Backup jobs created before this feature or before new payload keys exist must continue to run with defaults.

## Testing

Unit tests should cover:

1. Settings page renders the three V1 sections.
2. Defaults are used when no option exists.
3. Save handler checks capability and nonce.
4. Submitted booleans and integers are sanitized.
5. Invalid numeric values are clamped or defaulted.
6. Backup creation copies settings into job payload.
7. Running jobs use payload settings rather than live option settings.
8. Backup page displays the settings summary.
9. Cleanup action does not delete successful backup archives.
10. Diagnostic report excludes secret-like values.

## Rollout

Implement in small slices:

1. Add settings value object/helper with defaults and sanitization.
2. Add Settings page form, save handler, and tests.
3. Wire Backup page summary and backup job payload snapshot.
4. Apply cache and large-file settings in backup scanning.
5. Add retention cleanup after successful backups.
6. Add failed-job cleanup controls.
7. Add diagnostic report and last backup summary.
