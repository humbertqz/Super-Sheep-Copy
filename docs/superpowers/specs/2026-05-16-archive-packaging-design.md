# Archive Packaging Design

## Goal

Add the first complete backup packaging path: after the backup manager exports the database and scans site files, it creates a ZIP archive containing site files, database export files, manifest metadata, checksums, and a safe backup log.

This slice connects the existing backup orchestration to the existing archive writer. It does not add admin download links, live WordPress metadata collection, background chunking, or restore validation.

## Current Context

The project already has:

- `BackupManager`, which creates a job working directory, exports the database, scans files, and returns a summary.
- `DatabaseBackupCoordinator`, which writes database export output below `working/database`.
- `FileScanner`, which returns `ScannedFile` objects for eligible site files.
- `ManifestBuilder`, which creates the top-level backup manifest.
- `ArchiveWriter`, which writes `manifest.json`, `checksums.json`, `logs/backup.log`, and site files into a ZIP.

The missing piece is a packaging coordinator that gathers the database export files, calculates checksums, builds the manifest, calls `ArchiveWriter`, and returns archive details to `BackupManager`.

## Architecture

Add `BackupArchivePackager` under `super-sheep-copy/src/Backup/`.

`BackupArchivePackager` is responsible for archive orchestration:

1. Receive a job id, working directory, database directory, scanned site files, and manifest metadata.
2. Discover regular database files below the database directory.
3. Compute SHA-256 checksums for site files and database files using their archive entry paths.
4. Build a `Manifest` through `ManifestBuilder`.
5. Write the ZIP archive through `ArchiveWriter`.
6. Return an `ArchivePackageResult` with archive path, archive size, site file count, database file count, and checksums.

`ArchiveWriter` remains the low-level ZIP writer. It will be expanded to accept database export files separately and write them under `database/...`, while continuing to write scanned site files under `files/...`.

`BackupManager` will depend on `BackupArchivePackager`. After file scanning, it will transition the job to `packaging_archive`, call the packager, then persist the completed payload with archive details.

## Data Flow

1. `BackupManager::run()` creates the working directory and records `created`.
2. It records `exporting_database` and calls `DatabaseBackupCoordinatorInterface::export()`.
3. It records `scanning_files` and scans the site root with `FileScanner`.
4. It records `packaging_archive`.
5. It calls `BackupArchivePackager::package()` with:
   - job id
   - working directory
   - database directory
   - scanned site files
   - manifest metadata
6. The packager writes `working/<job-id>.zip`.
7. `BackupManager` records `completed` with archive path, archive size, scanned file count, and database file count.
8. `BackupResult` exposes archive path and archive size.

## Manifest Metadata

This slice keeps live WordPress metadata injectable instead of collecting it from WordPress globals.

`BackupOptions` will carry a metadata array for the packager. The manager will merge runtime values into that metadata before packaging:

- `created_at`
- `file_count`
- `database_table_count`
- `archive_size`
- `checksums`

Tests will pass complete metadata with deterministic values for fields that normally come from WordPress:

- source site URL
- source home URL
- WordPress version
- PHP version
- database version
- table prefix
- multisite flag
- active theme
- active plugins
- must-use plugins
- exclusions
- environment

Live WordPress metadata collection belongs to a later plugin wiring slice.

## Archive Structure

The ZIP archive will contain:

```text
manifest.json
checksums.json
logs/backup.log
files/<site-relative-path>
database/<database-relative-path>
```

Database relative paths are relative to the database export directory. For example:

- `working/database/tables.json` becomes `database/tables.json`
- `working/database/wp_posts/chunk-000001.sql` becomes `database/wp_posts/chunk-000001.sql`

Site files continue to use the existing `files/<relative path>` layout.

## Checksums

Checksums use SHA-256 and are keyed by the ZIP entry path:

- `files/wp-content/uploads/image.jpg`
- `database/tables.json`
- `database/wp_posts/chunk-000001.sql`

`checksums.json` and the manifest `checksums` field will contain the same checksum map for this slice.

`manifest.json` and `checksums.json` do not checksum themselves in this slice because doing so would require a second write pass and creates a circular metadata problem. That can be added later with a dedicated archive finalization design if needed.

## Error Handling

`BackupArchivePackager` throws `RuntimeException` when:

- The database directory does not exist.
- A database export item cannot be read.
- A file checksum cannot be calculated.
- The archive cannot be written through `ArchiveWriter`.

`ArchiveWriter` continues to throw `RuntimeException` when `ZipArchive` is unavailable or the ZIP cannot be opened.

`BackupManager` does not catch these exceptions in this slice. Admin-friendly error conversion belongs to the future UI/job runner layer.

## Testing

Add focused unit tests:

- `BackupArchivePackagerTest` creates temp site files and database files, packages them, and verifies:
  - ZIP exists.
  - Site files are under `files/...`.
  - Database files are under `database/...`.
  - `manifest.json` has file counts and archive size.
  - `checksums.json` includes SHA-256 checksums keyed by archive entry path.
- `ArchiveWriterTest` is expanded to verify database files can be written.
- `BackupManagerTest` uses a fake packager to verify:
  - State order includes `packaging_archive`.
  - Completed payload includes archive path and archive size.
  - `BackupResult` exposes archive path and archive size.

Run focused tests, full PHPUnit, lint, and a dependency scan confirming the packager and result classes stay WordPress-free.

## Scope Exclusions

This slice does not implement:

- Admin download URLs.
- Backup list UI.
- Live WordPress metadata gathering.
- Background or chunked archive writing.
- Archive validation during restore.
- Standalone restore installer changes.
- Cleanup of working directories after archive creation.

## Next Slice

After archive packaging, the next useful slice is plugin wiring: construct the real backup manager dependencies inside the WordPress plugin layer, gather source-site metadata through WordPress APIs, and trigger the manager from a secured admin action.

