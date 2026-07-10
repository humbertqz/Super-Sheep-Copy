# Package Checksum Verification Design

## Problem

Backup packages write SHA-256 hashes for files and database chunks, but current validation only requires that `checksums.json` exists. Corrupted or altered archive content can therefore pass validation and reach restore preparation.

## Goals

1. Verify every backed-up site file and database entry against its SHA-256 checksum before a package is accepted.
2. Apply identical validation rules in WordPress and standalone installer paths.
3. Reject malformed, incomplete, unexpected, or mismatched checksum manifests with actionable errors.
4. Preserve existing package formats: ZIP, TAR, TAR.GZ, and directory packages.

## Non-Goals

- Do not add encryption, signing, remote storage, or immutable retention.
- Do not add a compatibility bypass for legacy packages with empty or incomplete checksums.
- Do not checksum generated metadata entries: `manifest.json`, `checksums.json`, or `logs/backup.log`.
- Do not alter backup archive layout.

## Decision

Use strict content verification. A package is valid only when:

1. `checksums.json` is valid JSON object mapping archive paths to lowercase SHA-256 hex digests.
2. Every regular `files/` and `database/` entry has exactly one manifest hash.
3. Every manifest hash references a regular `files/` or `database/` entry.
4. Reading each entry and hashing its bytes produces its declared digest.

Packages that previously carried `{}` as `checksums.json` will be rejected. Administrators must recreate them from source data.

## Architecture

Create one shared checksum verifier beside `PackageValidator`. It receives the package reader and discovered regular content entries, decodes `checksums.json`, then streams each content entry through `hash_init('sha256')`/`hash_update()` to avoid loading large files into memory. It returns validation errors; `PackageValidator` owns the final `ArchiveValidationResult`.

The standalone installer keeps its local validator because it must run without WordPress plugin autoloading. Mirror same algorithm there. Keep error messages stable across both implementations where practical.

## Validation Rules

- Ignore directory entries and metadata entries.
- Reject `checksums.json` when unreadable, non-JSON, non-object, empty, or values are not 64-character lowercase hexadecimal SHA-256 digests.
- Reject a content entry with no corresponding checksum.
- Reject a checksum path that does not identify a content entry.
- Reject checksum paths outside `files/` or `database/`.
- Reject content whose streamed digest differs from declared value.
- Preserve existing safe-path, manifest, database-manifest, SQL-chunk, and file-entry checks.

## Error Handling

Validation collects all discoverable manifest-path errors before content hashing. It may continue hashing remaining entries after a mismatch, so callers receive complete diagnostics. A reader failure while reading a content entry creates a validation error for that entry; it does not cause an uncaught exception.

## Testing

Shared and installer validator tests must cover:

1. Valid archive with matching checksums.
2. Content modified after checksum creation.
3. Missing checksum for a content entry.
4. Extra checksum path.
5. Checksum path outside content roots.
6. Invalid digest format.
7. Empty or malformed checksum manifest.
8. Existing structural validation behavior remains intact.

## Rollout

Ship as a format-v1 validation hardening. Completed packages created by current plugin versions already contain populated hashes. Reject legacy/incomplete packages explicitly instead of restoring unknown content.
