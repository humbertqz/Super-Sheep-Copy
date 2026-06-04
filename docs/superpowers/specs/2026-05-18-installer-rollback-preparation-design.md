# Installer Rollback Preparation Design

## Goal

Add first rollback-preparation gate inside standalone installer. After token verification, successful preflight, and explicit restore confirmation, installer can prepare a rollback artifact before any future destructive restore step.

This slice remains non-destructive. It does not import database, dump database, extract restore archive, replace files, run URL replacement, enter maintenance mode, or execute rollback.

## Current Context

Project already has:

- token-gated standalone installer
- installer-local archive validation
- preflight checks
- destination URL detection
- `wp-config.php` credential detection
- explicit restore confirmation stored in installer `config.php`

Missing safety gate: concrete rollback preparation state. Future destructive restore must require both:

- `restore_confirmed=true`
- `rollback_prepared=true`

## Chosen Approach

Implement minimal rollback artifact now:

- rollback directory under `ssc-restore-engine/rollback/`
- rollback manifest JSON
- optional `wp-config.php` snapshot when readable
- config state update marking rollback prepared

No database dump yet. Database rollback needs database connection testing and chunked export first.

## New Installer Classes

Add installer-local classes:

- `RollbackManifestBuilder`
- `RollbackFileCollector`
- `RollbackPreparationManager`

These classes live under:

```text
super-sheep-copy/installer/restore-engine/
```

They run without WordPress and without Composer.

## Rollback Directory

Rollback artifacts will live under:

```text
{engine_dir}/rollback/
```

Each preparation creates a timestamped subdirectory:

```text
rollback/rollback-YYYYmmdd-His-random/
```

Contents in this slice:

```text
manifest.json
files/wp-config.php
```

`files/wp-config.php` exists only when destination `wp-config.php` is readable.

## Rollback Manifest

`RollbackManifestBuilder` returns array data for `manifest.json`.

Manifest fields:

- `project`: `Super Sheep Copy`
- `type`: `rollback`
- `created_at`
- `destination_url`
- `wordpress_root`
- `restore_job_id`
- `source_site_url`
- `source_home_url`
- `staged_archive_basename`
- `files`
- `warnings`

File entries include:

- `relative_path`
- `rollback_path`
- `sha256`
- `size`

The manifest must not contain database passwords, salts, auth keys, token hash, plaintext token, or absolute path to staged backup archive.

`wordpress_root` is allowed because this is a local installer artifact, but admin/browser UI should not expose raw paths beyond rollback status.

## File Collection

`RollbackFileCollector` receives WordPress root and rollback directory.

In this slice it only considers:

```text
wp-config.php
```

Behavior:

1. If `wp-config.php` missing or unreadable, add warning and continue.
2. If readable, copy to `files/wp-config.php`.
3. Compute SHA-256 and size for copied file.
4. Return file entries and warnings.

No recursive file backup in this slice. Full files rollback needs extraction/restore design first.

## Rollback Preparation Manager

`RollbackPreparationManager` exposes:

```php
prepare(string $engine_dir, array $config, array $server): array
```

It will:

1. Require `restore_confirmed=true`.
2. Reject if config is locked.
3. Resolve WordPress root as `dirname($engine_dir)`.
4. Create rollback root and timestamped rollback directory.
5. Use `RollbackFileCollector` to snapshot allowed files.
6. Use `RollbackManifestBuilder` to write `manifest.json`.
7. Rewrite installer `config.php`, preserving existing config and adding:
   - `rollback_prepared=true`
   - `rollback_prepared_at`
   - `rollback_directory` basename only
   - `rollback_manifest` relative path under engine dir
8. Return safe status array:
   - `prepared`
   - `rollback_directory`
   - `file_count`
   - `warnings`

The manager must not run if restore confirmation is missing.

## Bootstrap Flow

Add rollback-preparation UI to token-verified installer page.

After confirmation status:

- If not confirmed: show rollback unavailable message.
- If confirmed and `rollback_prepared=true`: show rollback prepared status.
- If confirmed and not rollback prepared: show “Prepare Rollback” form.

POST action:

```text
prepare_rollback=1
token=<token>
```

When submitted:

1. Verify token as current flow already does.
2. Run preflight as current flow already does.
3. Require no blocking preflight errors.
4. Require confirmed config.
5. Call `RollbackPreparationManager`.
6. Reload config.
7. Show success/failure status.

No destructive action runs after rollback preparation.

## Security

This slice enforces:

- token required before rollback UI or action
- restore confirmation required before rollback preparation
- blocking preflight errors prevent rollback preparation
- rollback directory stays under installer engine dir
- file collection allowlist is only `wp-config.php`
- no database password rendered in HTML
- no token/hash copied into rollback manifest
- config stores rollback directory basename, not arbitrary user-controlled path

## Error Handling

Rollback preparation failures render generic failure message.

Warnings are allowed for missing unreadable `wp-config.php`; rollback can still be prepared with manifest-only artifact. This lets local dev and fresh installs proceed while keeping future destructive restore gated by `rollback_prepared=true`.

## Testing

Add focused unit tests:

- `RollbackFileCollectorTest`
  - copies readable `wp-config.php`
  - records checksum and size
  - warns when `wp-config.php` missing
- `RollbackManifestBuilderTest`
  - builds manifest with expected metadata
  - excludes token hash and database password
- `RollbackPreparationManagerTest`
  - rejects unconfirmed config
  - creates rollback directory, copied file, and manifest
  - updates config with rollback fields
  - allows manifest-only rollback when `wp-config.php` missing
- `InstallerBootstrapTest`
  - confirmed restore shows rollback preparation form
  - rollback POST records rollback prepared status
  - unconfirmed restore does not show rollback form

Final verification:

- focused rollback tests
- full PHPUnit suite
- composer lint
- request-global scan still limited to admin, nonce, and installer boundary files
- git status clean

## Scope Exclusions

This slice does not implement:

- database dump rollback
- database connection test
- recursive file backup
- archive extraction
- restore execution
- rollback execution
- maintenance mode
- installer lock/delete
- cache clearing
- health checks

## Next Slice

After this slice, next useful work is database connection testing and rollback database dump planning. Only after rollback can protect database state should installer start database import design.
