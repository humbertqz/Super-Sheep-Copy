# Package Driver Layer Design

## Goal

Make backup packaging work on hosts where PHP `ZipArchive` is unavailable, without weakening restore validation or changing the current ZIP behavior when ZIP support exists.

The backup system will choose the best available package format:

1. ZIP through `ZipArchive`.
2. TAR.GZ through `PharData`.
3. Uncompressed package directory through filesystem copy.

The restore system must understand every package format that backup creation can produce.

## Current Context

The project is currently ZIP-centered:

- `src/Backup/ArchiveWriter.php` writes a full ZIP archive with `ZipArchive`.
- `src/Backup/BackupArchivePackager.php` writes `<job-id>.zip`.
- `src/Backup/BackupArchiveStepPackager.php` incrementally writes `<job-id>.zip`.
- `shared/Archive/ArchiveValidator.php` validates ZIP packages through `ZipArchive`.
- `installer/restore-engine/ArchiveValidator.php` independently validates ZIP packages for the standalone installer.
- Admin and restore UI copy refers to "ZIP" archives.
- `src/Support/EnvironmentChecker.php` reports only ZIP extension availability.

Because restore depends on the package structure, fallback packaging cannot be backup-only. The plugin admin restore path and standalone installer must validate and read the same formats.

## Architecture

Add a package writer abstraction under `src/Backup/Package/`.

`PackageWriterInterface` exposes low-level package operations:

- `format(): string`
- `extension(): string`
- `isAvailable(): bool`
- `open(string $package_path): void`
- `addFile(string $source_path, string $entry_path): void`
- `addString(string $entry_path, string $contents): void`
- `close(): void`

Add implementations:

- `ZipPackageWriter`
  - Uses `ZipArchive`.
  - Writes `.zip`.
  - Preserves current behavior and remains the preferred format.
- `TarGzPackageWriter`
  - Uses `PharData`.
  - Writes `.tar.gz`.
  - Avoids shell commands so it works when `exec()` is disabled.
- `DirectoryPackageWriter`
  - Uses normal filesystem operations.
  - Writes a package directory.
  - Acts as the last fallback when no compression support exists.

Add `PackageWriterFactory`:

- Receives available writers in priority order.
- Returns the first writer whose `isAvailable()` is true.
- Uses ZIP first, TAR.GZ second, directory last.

`ArchiveWriter` becomes a compatibility wrapper around the selected package writer or is replaced by a package-oriented writer if the change is clearer during implementation. Existing packager classes should no longer instantiate or type against `ZipArchive` directly.

## Backup Flow

`BackupArchivePackager` changes from hard-coded ZIP output to selected package output:

1. Discover database files.
2. Compute checksums for site and database files.
3. Ask `PackageWriterFactory` for the best writer.
4. Build package path from job id and selected writer extension.
5. Add manifest metadata describing package format.
6. Write metadata entries, files, and database exports through the writer.
7. Return `ArchivePackageResult` with package path, size, file counts, checksums, package format, and package extension.

`ArchivePackageResult` gains `packageFormat()` and `packageExtension()` accessors so admin, job, and diagnostics code can report the selected format without parsing file paths.

`BackupArchiveStepPackager` receives the same package writer selection. It must keep existing chunked progress behavior:

- ZIP continues to support incremental add operations across requests.
- Directory fallback writes entries incrementally with normal filesystem copy.
- TAR.GZ uses a staged package directory during chunked steps, then compresses that directory into `.tar.gz` during finalization. This avoids relying on reopening `PharData` archives across requests.

The implementation must not silently produce a package that restore cannot validate.

## Package Structure

All formats use the same internal entry layout:

```text
manifest.json
checksums.json
logs/backup.log
files/<site-relative-path>
database/<database-relative-path>
```

Directory fallback uses the same layout as real folders:

```text
<job-id>/
  manifest.json
  checksums.json
  logs/backup.log
  files/...
  database/...
```

Checksums remain keyed by package entry path:

```text
files/wp-content/uploads/image.jpg
database/tables.json
database/chunks/chunk-000001.sql
```

`manifest.json` and `checksums.json` do not checksum themselves.

## Manifest Metadata

Add package fields to the manifest:

```json
{
  "package_format": "zip",
  "package_extension": ".zip",
  "package_schema_version": 1
}
```

Allowed `package_format` values:

- `zip`
- `tar.gz`
- `directory`

`package_schema_version` starts at `1` because this is the first format-aware package schema. Existing ZIP backups without these fields stay valid; validators should treat missing package fields as legacy ZIP when the physical package is a ZIP.

## Restore Validation

Add a format-aware validator layer in shared archive code:

- `PackageValidatorInterface`
- `ZipPackageValidator`
- `TarGzPackageValidator`
- `DirectoryPackageValidator`
- `PackageValidatorFactory`

The plugin-side restore path uses the shared validator factory.

The standalone installer receives equivalent validator support under `installer/restore-engine/` because it must validate packages independently from WordPress.

Validation rules apply to every format:

- Reject empty paths.
- Reject null bytes.
- Reject absolute paths.
- Reject Windows drive-rooted paths.
- Reject `..` path segments.
- Require `manifest.json`.
- Require `checksums.json`.
- Require at least one `database/` entry.
- Require `database/tables.json`.
- Require at least one `database/chunks/*.sql`.
- Require at least one `files/` entry.
- Require manifest project value `Super Sheep Copy`.

TAR.GZ validation must inspect actual archive entries, not trust file names. Directory validation must walk the package directory and apply the same safe-path checks to relative paths.

## Restore Reading And Extraction

Validation alone is not enough. Any restore component that opens, lists, or extracts ZIP entries must route through a package reader abstraction before TAR.GZ or directory packages are enabled for creation.

Add a package reader layer if current restore code directly depends on ZIP extraction:

- `PackageReaderInterface`
- `ZipPackageReader`
- `TarGzPackageReader`
- `DirectoryPackageReader`
- `PackageReaderFactory`

Readers must expose safe entry listing and controlled extraction/copy behavior. Extraction must keep current zip-slip protections for every format.

## Admin UI And Diagnostics

Change user-facing wording from "backup ZIP" or "archive ZIP" to "backup package" where formats can vary.

Restore upload should accept:

```text
.zip,.tar,.tar.gz
```

Directory packages are not browser-uploadable as a single file. The restore page will list package directories staged by SFTP/FTP in the restore folder, using the same staged-package controls currently used for uploaded archives.

Environment diagnostics show:

- ZIP extension: Available or Missing.
- TAR/GZIP package support: Available or Missing, based on `PharData`.
- Folder package fallback: Available when backup storage is writable.

Backup warnings:

- ZIP missing and TAR.GZ available: "ZIP unavailable. Using TAR.GZ backup package."
- ZIP and TAR.GZ missing: "No archive compression available. Using folder backup package."

## Error Handling

Package selection fails only if no writer is available. Because directory fallback depends only on writable filesystem access, failure usually means backup storage is not writable.

Errors should name the missing capability and the selected fallback when possible:

- ZIP missing, TAR.GZ selected.
- ZIP and TAR.GZ missing, directory selected.
- No writable backup package target available.

Backup jobs should store selected package format in payload so failed jobs are diagnosable.

Restore validation should report format-specific open failures:

- Unable to open ZIP package.
- Unable to open TAR.GZ package.
- Unable to read package directory.

## Testing

Add unit tests for package writer selection:

- ZIP writer is selected first when available.
- TAR.GZ writer is selected when ZIP is unavailable and `PharData` is available.
- Directory writer is selected when compression writers are unavailable.
- Selection fails clearly if no writer is available.

Add writer tests:

- ZIP writer creates expected entries.
- TAR.GZ writer creates expected entries when `PharData` is available.
- Directory writer creates expected files and directories.
- Writers reject unsafe entry paths before writing.

Add packager tests:

- `BackupArchivePackager` uses selected extension.
- Manifest includes package metadata.
- Checksums stay keyed by package entry path.
- Existing ZIP package behavior remains valid.

Add step-packager tests:

- Selected package format is stored in payload.
- Progress metrics continue to update.
- Completed payload uses selected package path and extension.

Add validator tests for shared and installer validators:

- ZIP legacy packages still validate.
- TAR.GZ package validates when `PharData` is available.
- Directory package validates.
- Unsafe paths are rejected in every format.
- Missing manifest, checksums, database entries, database table manifest, database chunks, or file entries fail consistently.

Add UI tests:

- Restore page copy says backup package.
- Upload accept list includes ZIP and TAR.GZ.
- Diagnostics show ZIP, TAR/GZIP, and folder fallback availability.

Run focused PHPUnit tests, then full PHPUnit.

## Scope Exclusions

This design does not add shell `zip` or shell `tar` fallback.

This design does not redesign the admin UI.

This design does not change database export format.

This design does not remove support for existing ZIP backups.

This design does not require users to configure preferred package format manually. Automatic best-available selection is enough for this slice.

## Rollout Notes

Keep ZIP as the preferred format to preserve current download and restore behavior for most hosts.

Do not enable TAR.GZ or directory package creation until restore validation and reading for that format are available.

Keep legacy ZIP backups valid even if they lack package metadata.
