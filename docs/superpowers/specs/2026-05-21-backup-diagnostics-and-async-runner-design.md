# Backup Diagnostics And Async Runner Design

## Problem

Live server backup requests can return HTTP 500 before WordPress writes a useful fatal error to `wp-content/debug.log`. The current backup runs synchronously in one admin request, so a large site can exceed PHP, web server, or host request limits without leaving enough plugin-level evidence.

## Goals

1. Record precise backup progress markers before and after expensive work so the last saved job payload identifies where a failed live backup stopped.
2. Start moving backup execution away from one long admin request toward small resumable steps.
3. Keep each slice testable and safe for existing synchronous backup behavior.

## Non-Goals

- Do not fully rewrite archive packaging in this slice.
- Do not add external queues or new Composer runtime packages.
- Do not store secrets in job payloads.
- Do not expose backup paths or archive contents to users without existing capability checks.

## Slice 1: Diagnostic Markers

Add a lightweight progress reporter used by the current synchronous backup flow.

The reporter saves the existing backup job with additional payload keys:

- `phase`: current high-level phase, such as `database`, `files`, or `archive`.
- `step`: exact action, such as `table_started`, `chunk_started`, `file_scan_started`, or `archive_package_started`.
- `table`: current database table when applicable.
- `chunk`: current chunk number when applicable.
- `updated_at`: UTC ISO-8601 timestamp.
- `message`: short human-readable status safe for admin display.

Database export will report before and after each table and before each chunk query. Backup manager will report before and after directory creation, file scan, archive packaging, completion, and failure.

Backup page Jobs table will show the latest safe progress message when present.

## Slice 2: Async Job Starter

Add the first async execution path without removing the synchronous runner yet.

The backup form will create a backup job and return quickly. A secured admin AJAX endpoint will process one bounded backup step per request. The first async slice should process database export one table/chunk step at a time, reusing the same diagnostic payload fields.

Admin UI will poll job state and latest progress message. If AJAX processing fails, the job remains inspectable with the last marker.

## Data Model

Existing `Job` payload remains an associative array. New payload fields are additive. Existing tests and restore logic must continue to accept older jobs without progress fields.

## Security

All new admin endpoints must require backup capability and nonce verification. Progress messages must not include database passwords, full SQL, auth salts, tokens, or private archive URLs.

## Failure Handling

Synchronous backup will catch failures at top-level where possible, save `failed` job state with latest phase/step/message, then rethrow or redirect according to current admin flow.

Async backup will mark job failed when a bounded step throws and will preserve prior progress markers.

## Verification

- Unit tests prove progress markers are written around backup manager phases.
- Unit tests prove database coordinator reports table/chunk markers.
- Unit tests prove Backup page renders progress message.
- Existing full PHPUnit suite stays green.
