# Installer Preparation and Token Protection Design

## Goal

Add the next restore-side milestone: after an administrator uploads and validates a backup, the plugin can prepare a WordPress-root `installer.php`, copy the installer engine beside it, generate a one-time restore token, and provide a launch URL protected by that token.

This slice still avoids destructive restore work. It does not extract files, import a database, replace URLs, create rollback backups, or modify WordPress content.

## Current Context

The project already has:

- `RestorePreparationManager`, which validates an uploaded Super Sheep Copy ZIP, stages it in the private restore staging directory, and stores a completed restore job with safe payload fields.
- `RestorePage`, which handles the nonce-protected upload POST and shows generic status notices.
- `installer/installer.php`, which loads `installer/restore-engine/Bootstrap.php`.
- `installer/restore-engine/Security.php`, which currently checks only for a non-empty `token` query value.
- `installer/restore-engine/EnvironmentChecker.php`, used by `Bootstrap` to show basic environment information.
- Shared `ArchiveValidator`, which can validate a backup ZIP without extraction.

The missing piece is the bridge between the plugin and the standalone installer: deploy installer files to the WordPress root, create a one-time token, write a minimal installer config, and make the installer verify that token before showing restore information.

## Chosen Approach

The installer will be deployed into the destination WordPress root as:

```text
ABSPATH/installer.php
ABSPATH/ssc-restore-engine/
```

This matches `guide.md`, makes local/manual testing straightforward, and keeps the installer independent from the active WordPress runtime. The engine directory name will be plugin-specific so it is less likely to collide with existing site files than a generic `restore-engine/` directory.

The plugin will not place the backup ZIP in the public WordPress root. The ZIP stays in the private restore staging directory created by `RestorePreparationManager`. The installer config will reference that staged archive by absolute path because the installer runs outside WordPress and needs to open the package directly. The admin UI will display only a launch URL and safe source metadata, not server paths.

## Plugin-Side Installer Preparation

Add an installer preparation service under `super-sheep-copy/src/Restore/`.

New classes:

- `InstallerPreparationManager`
- `InstallerPreparationManagerInterface`
- `InstallerPreparationResult`

The manager receives:

- source installer directory path, normally `SUPER_SHEEP_COPY_DIR . 'installer'`
- WordPress root path, normally `ABSPATH`
- restore staging directory path, normally `Plugin::backupDirectory() . '/restore'`
- `JobRepositoryInterface`

It exposes:

```php
prepare(string $restore_job_id): InstallerPreparationResult
```

The manager will:

1. Load the restore job by ID.
2. Require job type `restore`.
3. Require job state `completed`.
4. Read the staged archive basename from the job payload.
5. Resolve the archive path under the restore staging directory only.
6. Reject missing or unreadable staged archives.
7. Generate a random one-time token with `random_bytes()`.
8. Hash the token with `password_hash()`.
9. Copy `installer/installer.php` to `ABSPATH/installer.php`.
10. Recursively copy `installer/restore-engine/` to `ABSPATH/ssc-restore-engine/`.
11. Rewrite the copied `installer.php` require path so it loads `ssc-restore-engine/Bootstrap.php`.
12. Write `ABSPATH/ssc-restore-engine/config.php` returning a PHP array.
13. Save the restore job with updated payload fields.
14. Return launch URL and safe display metadata.

The copied config will include:

- restore job ID
- staged archive absolute path
- staged archive basename
- source site URL
- source home URL
- token hash
- token creation timestamp
- installer locked flag, initially `false`

The job payload will include:

- existing safe restore-preparation fields
- `installer_prepared` set to `true`
- `installer_url`, without the token
- `installer_engine_dir` basename only
- `installer_prepared_at`

The job payload will not store the plaintext token. The plaintext token will be returned only in `InstallerPreparationResult` so the admin page can include it in the immediate redirect/launch URL.

## Restore Page Behavior

The Restore page will add a second nonce-protected POST action:

```text
super_sheep_copy_action=prepare_installer
super_sheep_copy_restore_job_id=<job id>
```

After a backup upload succeeds, the page should have enough job context to offer installer preparation. Because redirects currently only carry a generic status, this slice will update the success redirect to include the completed restore job ID:

```text
super_sheep_copy_status=restore_prepared
super_sheep_copy_restore_job_id=<job id>
```

On the success view, the page will show:

- source site URL
- source home URL
- archive entry count
- database entry count
- a clear warning that the installer is still non-destructive in this milestone
- a button to prepare the standalone installer

After installer preparation succeeds, the page redirects with:

```text
super_sheep_copy_status=installer_prepared
super_sheep_copy_restore_job_id=<job id>
super_sheep_copy_installer_token=<one-time token>
```

The page will render a launch link:

```text
{site_url}/installer.php?token=<one-time token>
```

The token is shown only immediately after preparation. If the user leaves the page, they must prepare the installer again to receive a new token.

## Installer-Side Token Protection

Update the copied installer runtime so `installer.php` loads `ssc-restore-engine/Bootstrap.php`.

`Bootstrap` will load `config.php` from the engine directory if present. If the config is missing, invalid, locked, or has no token hash, the installer displays a generic locked/unavailable message.

`Security` will verify the provided token using:

```php
password_verify($provided_token, $config['token_hash'])
```

If the token is missing or invalid, the installer will display only a token form and no archive details.

If the token is valid, the installer will:

1. Run environment checks.
2. Validate the staged archive again using the shared archive validator logic copied into the installer engine.
3. Display source URL, home URL, archive entry count, database entry count, and a warning that destructive restore execution is not implemented yet.

The token is one-time in the sense that only the generated plaintext value can unlock the prepared installer. Token consumption and installer locking after restore will be handled in a later destructive-restore slice, because no destructive action exists yet.

## Archive Validation Inside Installer

The installer must not trust plugin-side validation alone. This slice will add a standalone installer `ArchiveValidator` under:

```text
super-sheep-copy/installer/restore-engine/ArchiveValidator.php
```

It will mirror the shared package validation behavior needed by the installer:

- open ZIP with `ZipArchive`
- reject unsafe paths
- require `manifest.json`
- require `checksums.json`
- require at least one `database/...` entry
- require manifest project `Super Sheep Copy`
- return validation status, manifest, entry count, and database entry count

The class stays installer-local so the deployed installer can run without Composer or WordPress autoloading.

## Security

This slice enforces:

- admin capability and nonce before installer preparation
- staged archive path resolution confined to the restore staging directory
- no plaintext token stored in WordPress job payload or installer config
- password-hashed token in installer config
- installer config loaded from the deployed engine directory only
- generic installer error messages for missing config, invalid token, invalid archive, and locked installer
- no destructive restore operation

The plugin will overwrite its own `ABSPATH/installer.php` and `ABSPATH/ssc-restore-engine/` deployment when preparing a new installer. It will not delete unrelated root files.

## Error Handling

Plugin-side preparation throws `RuntimeException` for:

- missing restore job
- wrong job type
- incomplete restore job
- missing staged archive payload
- unsafe archive basename
- missing source installer files
- inability to copy installer files
- inability to write config

`RestorePage` catches failures, logs a generic warning, and redirects with `installer_failed`.

Installer-side failures render generic messages and keep destructive actions unavailable.

## Testing

Add focused unit tests:

- `InstallerPreparationManagerTest`
  - prepares root `installer.php`, engine directory, and config for a completed restore job
  - returns a launch URL containing the plaintext token
  - stores only safe installer fields in the job payload
  - rejects unsafe staged archive basenames
  - rejects incomplete or missing restore jobs
- `RestorePageTest`
  - restore upload success redirects with restore job ID
  - prepared restore view renders an installer preparation form
  - installer preparation success redirects with token
  - installer preparation failure redirects with generic failure status
- `InstallerSecurityTest`
  - valid token verifies against config hash
  - missing or invalid token fails
- `InstallerArchiveValidatorTest`
  - valid Super Sheep Copy archive passes
  - unsafe archive entry fails
  - missing manifest fails
- `InstallerBootstrapTest`
  - missing/invalid token output does not include archive details
  - valid token output includes environment checks and archive metadata

Final verification:

- focused PHPUnit tests for new restore and installer classes
- full PHPUnit suite
- Composer lint
- request/global scan confirming direct request access stays inside admin/security/installer boundary files
- git status clean

## Scope Exclusions

This slice does not implement:

- restore confirmation
- database import
- archive extraction
- file restore
- URL replacement during restore
- rollback backup creation
- maintenance mode
- token consumption after a destructive action
- installer self-delete or lock after restore completion
- cache clearing
- health checks

## Next Slice

After this slice, the next useful work is installer-side restore preflight and confirmation: environment and database credential checks, destination URL detection, restore warning confirmation, and a locked path toward rollback creation before any destructive import or file restore.
