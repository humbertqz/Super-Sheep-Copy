# Plugin Backup Wiring Design

## Goal

Add the first usable WordPress admin backup trigger. The Backup page will submit a secured POST request, gather live WordPress metadata, run the existing backup pipeline synchronously, persist job state through the existing job repository, and return the administrator to the Backup page with a status notice.

This slice turns the backup pipeline from unit-tested services into a WordPress-reachable workflow. It does not add background processing, progress polling, download links, retention cleanup, or restore execution.

## Current Context

The project already has:

- `Plugin`, which boots the admin menu and creates the protected backup directory on activation.
- `AdminMenu`, which wires `BackupPage`, `RestorePage`, and `SettingsPage`.
- `BackupPage`, which currently renders a manifest preview, environment checks, jobs, and a disabled button.
- `OptionJobRepository`, which persists job snapshots in a WordPress option.
- `BackupManager`, which creates a working directory, exports the database, scans files, packages the archive, and saves job states.
- Database export services, file scanning, manifest building, archive packaging, and ZIP writing.

The missing piece is WordPress-layer construction and a secured admin action that calls `BackupManager`.

## Architecture

Add two WordPress-facing backup support classes under `super-sheep-copy/src/Backup/`:

- `BackupMetadataCollector`
- `BackupManagerFactory`

`BackupMetadataCollector` gathers live metadata through WordPress APIs and `$wpdb`. It returns the metadata array expected by `BackupOptions` and `ManifestBuilder`.

`BackupManagerFactory` builds the production backup service graph:

1. `OptionJobRepository`
2. `WpdbClient`
3. `WpdbDatabaseExporter`
4. `DatabaseExportWriter`
5. `DatabaseBackupCoordinator`
6. `FileScanner`
7. `BackupArchivePackager`
8. `BackupManager`

`BackupPage` receives the factory and metadata collector. It handles POST submissions before rendering:

1. Require backup capability.
2. Detect the create-backup POST action.
3. Verify nonce.
4. Build `BackupOptions`.
5. Run `BackupManager::run()`.
6. Redirect to the Backup page with a success or failure query flag.

The template changes the disabled button into a real POST form using the existing `Nonce::field()`.

## Admin Action

The form posts to the current admin page with:

- `super_sheep_copy_action=create_backup`
- `super_sheep_copy_nonce=<nonce>`

`BackupPage` will only handle this exact action. GET requests and other actions render normally.

On success, it redirects to:

```text
admin.php?page=super-sheep-copy&super_sheep_copy_status=backup_created
```

On failure, it redirects to:

```text
admin.php?page=super-sheep-copy&super_sheep_copy_status=backup_failed
```

The notice text stays generic and does not expose absolute server paths.

## Backup Options

`BackupPage` builds `BackupOptions` with:

- site root: `ABSPATH`
- working base directory: `Plugin::backupDirectory()`
- table prefix: `$wpdb->prefix`
- table selection mode: `prefixed`
- database chunk size: `500`
- manifest metadata: collected by `BackupMetadataCollector`

The table prefix comes from metadata to avoid reading `$wpdb` in more than one page-level location.

## Metadata Collection

`BackupMetadataCollector` returns:

- `source_site_url`: `site_url()`
- `source_home_url`: `home_url()`
- `wordpress_version`: `get_bloginfo('version')`
- `php_version`: `PHP_VERSION`
- `database_version`: `$wpdb->db_version()` when available, otherwise an empty string
- `table_prefix`: `$wpdb->prefix`
- `is_multisite`: `is_multisite()`
- `active_theme`: current stylesheet when available
- `active_plugins`: `get_option('active_plugins', array())`
- `must_use_plugins`: `get_mu_plugins()` keys when available
- `created_at`: current UTC timestamp in ISO 8601 format
- `file_count`: `0`
- `database_table_count`: `0`
- `archive_size`: `0`
- `checksums`: empty array
- `exclusions`: current scanner defaults expressed as strings
- `environment`: output from `EnvironmentCheckerInterface::check()`

`BackupArchivePackager` overwrites runtime count, size, and checksum fields during packaging.

## Error Handling

`BackupPage` catches `Throwable` from backup creation. It redirects with a generic failure status and does not print the exception message into the admin UI.

This slice does not introduce a failed-job writer outside `BackupManager`. If the failure occurs after the manager starts, the last saved job state remains available. If it occurs before manager execution, no job is created.

Detailed logging can be added later through the existing logger abstraction when user-visible job logs are designed.

## Security

The action requires:

- `Capability::requireManageBackups()` for page access.
- `Capability::assertManageBackups()` before running the POST action.
- `Nonce::verifyRequest()` before reading action-specific input.

The form uses `method="post"` and includes the existing nonce field. The backup archive stays in `Plugin::backupDirectory()`, which activation protects with deny/index files through `Filesystem::ensureProtectedDirectory()`.

## Testing

Add focused unit tests where the current WordPress shim allows it:

- `BackupMetadataCollectorTest`
  - Stubs WordPress functions/globals through `tests/bootstrap.php`.
  - Verifies metadata keys and table prefix.
- `BackupManagerFactoryTest`
  - Verifies the factory returns a `BackupManager`.
  - Keeps the assertion shallow to avoid requiring a live WordPress database.
- `BackupPageTest`
  - Uses fake capability, nonce, metadata collector, and manager/factory collaborators.
  - Verifies POST handling calls the manager with expected options.
  - Verifies rendering exposes a create-backup form and existing job list.

If concrete collaborators make direct fakes difficult, introduce narrow interfaces for the page boundary:

- `BackupMetadataCollectorInterface`
- `BackupManagerFactoryInterface`

Use those interfaces only where they make unit tests and page wiring clearer.

Final verification:

- Focused tests for new and modified units.
- Full PHPUnit suite.
- Composer lint.
- Production dependency scan for direct `$_POST`/`$_REQUEST` access outside `Nonce` and `BackupPage`.

## Scope Exclusions

This slice does not implement:

- AJAX or REST backup start.
- Background or resumable job runner.
- Progress UI.
- Download links.
- Backup retention and cleanup.
- Restore flow.
- Admin display of archive paths.
- Manual backup option controls.

## Next Slice

After this slice, the next useful work is backup management UI: list completed backups, expose a secured download action, and add retention/delete controls.

