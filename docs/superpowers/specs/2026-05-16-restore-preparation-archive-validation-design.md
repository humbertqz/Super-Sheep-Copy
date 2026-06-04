# Restore Preparation and Archive Validation Design

## Goal

Add the first safe restore-side workflow: an administrator can upload a Super Sheep Copy backup ZIP, the plugin validates the archive structure without extraction, stages the archive in a private restore directory, records a restore-preparation job, and returns to the Restore page with a generic status notice.

This slice deliberately avoids destructive restore work. It does not extract files, import a database, replace URLs, create rollback backups, generate installer tokens, or launch the standalone installer.

## Current Context

The project already has:

- `RestorePage`, which renders a disabled restore-preparation scaffold.
- `ArchiveValidator`, which currently validates individual archive entry paths through `isSafePath()`.
- `Job` states for restore work, including `validating_restore` and `completed`.
- `OptionJobRepository`, which can persist restore-preparation job snapshots.
- A protected plugin backup directory from `Plugin::backupDirectory()`.
- A standalone installer scaffold under `installer/`, but no plugin-side installer preparation yet.

The missing piece is validating uploaded backup packages and staging a selected archive for a future installer-driven restore.

## Architecture

Expand shared archive validation and add a plugin-layer restore preparation manager.

Shared archive validation:

- Add `ArchiveValidationResult` under `super-sheep-copy/shared/Archive/`.
- Extend `ArchiveValidator` with `validatePackage(string $archive_path): ArchiveValidationResult`.
- Keep `isSafePath()` as the lower-level path safety primitive.

Plugin restore preparation:

- Add `RestorePreparationResult` under `super-sheep-copy/src/Restore/`.
- Add `RestorePreparationManager` under `super-sheep-copy/src/Restore/`.
- Update `RestorePage` to accept the manager, handle a nonce-protected upload POST, and render status notices.
- Update `AdminMenu` and `Plugin` to wire the manager into `RestorePage`.

## Archive Validation

`ArchiveValidator::validatePackage()` will:

1. Ensure `ZipArchive` is available.
2. Open the ZIP archive.
3. Iterate every entry name and reject unsafe paths using `isSafePath()`.
4. Reject empty names, absolute paths, path traversal, Windows absolute paths, and null bytes.
5. Require `manifest.json`.
6. Require `checksums.json`.
7. Require at least one regular `database/...` entry.
8. Parse `manifest.json` as JSON.
9. Require the decoded manifest to be an array.
10. Require `manifest.project` to equal `Super Sheep Copy`.

The result object will expose:

- `isValid(): bool`
- `errors(): array`
- `manifest(): array`
- `entryCount(): int`
- `databaseEntryCount(): int`

Validation will not compare every checksum in this slice. Checksum verification is important, but it should be a dedicated follow-up because it needs careful behavior around large archives, missing files, and manifest/checksum self-reference.

## Restore Preparation Manager

`RestorePreparationManager` receives:

- `ArchiveValidator`
- `JobRepositoryInterface`
- staging directory path

It exposes:

```php
prepare(array $upload): RestorePreparationResult
```

The upload array is shaped like a single `$_FILES` entry:

- `name`
- `tmp_name`
- `error`
- `size`

The manager will:

1. Validate upload error code is `UPLOAD_ERR_OK`.
2. Require a `.zip` filename.
3. Require the temporary file to be readable.
4. Validate the package with `ArchiveValidator::validatePackage()`.
5. Create the restore staging directory if missing.
6. Copy the uploaded archive to `restore-YYYYmmdd-His-random.zip` inside the staging directory.
7. Save a restore job in `validating_restore` before validation/staging details are complete.
8. Save the restore job in `completed` after staging succeeds.
9. Return `RestorePreparationResult`.

The completed job payload will include only safe display/storage fields:

- staged archive basename
- source site URL from manifest
- source home URL from manifest
- database entry count
- archive entry count

It will not store or display absolute staged paths in the admin UI.

## Restore Page Behavior

The Restore page will render:

- Existing environment checks.
- A warning that this only prepares a restore and does not modify the site.
- A POST form with `enctype="multipart/form-data"`.
- Existing nonce field.
- File input named `super_sheep_copy_restore_archive`.
- Hidden action `super_sheep_copy_action=prepare_restore`.
- Submit button `Validate Backup`.

On POST:

1. Require backup management capability.
2. Verify nonce.
3. Read the uploaded file from `$_FILES['super_sheep_copy_restore_archive']`.
4. Call `RestorePreparationManager::prepare()`.
5. Redirect to the Restore page with `super_sheep_copy_status=restore_prepared` on success.
6. Redirect with `super_sheep_copy_status=restore_failed` on failure.

The page will show generic success/failure notices. Failure notices do not include exception messages or absolute server paths.

## Security

This slice keeps restore preparation non-destructive:

- No extraction.
- No file replacement.
- No database import.
- No installer launch.

Upload handling is restricted by:

- `Capability::requireManageBackups()` for page access.
- `Capability::assertManageBackups()` before POST work.
- `Nonce::verifyRequest()` before reading the uploaded archive.
- `.zip` extension check.
- ZIP entry path validation before staging is considered successful.
- Private staging under `Plugin::backupDirectory()/restore`.

## Error Handling

`ArchiveValidator` returns invalid results for structural archive problems instead of throwing when possible.

`RestorePreparationManager` throws `RuntimeException` for invalid upload state, unreadable files, invalid archive result, staging-directory creation failure, and copy failure.

`RestorePage` catches `Throwable` and redirects with a generic failure status.

## Testing

Add focused unit tests:

- `ArchiveValidatorTest`
  - Existing path tests continue to pass.
  - Valid package with `manifest.json`, `checksums.json`, and `database/tables.json` passes.
  - Missing manifest fails.
  - Unsafe entry path fails.
  - Missing database entries fails.
- `RestorePreparationManagerTest`
  - Valid uploaded ZIP is copied to the staging directory.
  - Completed restore job stores safe payload fields.
  - Upload errors are rejected.
  - Invalid extension is rejected.
- `RestorePageTest`
  - Renders multipart upload form.
  - POST success calls manager and redirects with `restore_prepared`.
  - POST failure redirects with `restore_failed`.

Final verification:

- Focused tests for archive validation, restore preparation manager, and restore page.
- Full PHPUnit suite.
- Composer lint.
- Request-global scan confirming upload/request access is limited to page/security boundary files.

## Scope Exclusions

This slice does not implement:

- Archive extraction.
- Checksum verification.
- Database import.
- File replacement.
- URL replacement during restore.
- Rollback creation.
- Installer token generation.
- Installer copy/launch.
- Restore progress UI.
- Displaying staged archive paths.

## Next Slice

After this slice, the next useful work is installer preparation: generate a one-time restore token, copy the standalone installer into a protected location, and make the installer validate the staged archive again before any future restore action.

