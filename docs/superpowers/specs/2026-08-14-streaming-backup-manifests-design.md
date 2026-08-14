# Streaming Backup Manifests Design

## Goal

Allow Super Sheep Copy backups with large file inventories to finish within a
256 MB PHP memory limit.

## Scope

The change applies to backup scanning and archive packaging. Restores and
completed archive formats remain unchanged. A backup created before this change
that has not completed must be restarted instead of resumed.

## Current failure

The scanner writes `files.jsonl`, but the runner loads the entire file with
`file()` and constructs every `ScannedFile` object before packaging. The
packager then builds an in-memory entry list, reloads that complete list on
every packaging request, and serializes the complete checksum map on every
step. The memory required grows with the total number of files rather than the
configured batch size.

## Design

### Streaming input and progress

`files.jsonl` remains the scanner output. Packaging stores a byte offset and
entry count in the job payload. Each packaging step opens the manifest, seeks
to the stored offset, reads at most its effective batch size, and immediately
adds each file to the package. It then persists the new offset and count.

The database chunk files are represented by a separate, small manifest created
when packaging begins. They use the same offset-based reader after site files
are exhausted. This avoids materializing either the site or database file list.

### Checksums and final metadata

Each packaged entry is appended as one JSONL checksum record. Packaging never
reads or rewrites the whole checksum collection. On completion, checksum
records are streamed into the archive's `checksums.json` and `manifest.json`
contents. The generated archive retains its existing public format.

### Compatibility and errors

New jobs include a streaming-manifest version marker. A job in the packaging
state without that marker is treated as incompatible and fails with an explicit
message instructing the user to start a new backup. This deliberately avoids
migration code for partially written archives.

### Testing

Unit tests will cover bounded manifest reads, persisted byte-offset resume,
append-only checksum persistence, and rejection of pre-streaming incomplete
jobs. Existing archive-content tests continue to verify the public archive
format.

## Non-goals

- Raising PHP's memory limit.
- Migrating incomplete backups created by older plugin versions.
- Changing the restore archive schema.
