# Restore Database Compatibility Design

## Goal

Make database restores resilient to legacy zero-date schemas and ordinary MySQL packet limits without changing global server configuration or modifying restored data.

## Scope

This design covers two failures observed during local MAMP restores:

1. MySQL 1067 when a legacy schema, such as Action Scheduler, declares a `0000-00-00 00:00:00` datetime default while the destination session rejects zero dates.
2. MySQL 2006 when a generated multi-row `INSERT` statement exceeds the destination connection's packet limit.

It does not change table staging, table swapping, global MySQL configuration, or generic schema transformation.

## Architecture

The exporter remains responsible for creating portable SQL. It will keep the existing chunk-file format but will bound every individual `INSERT` statement by a configured byte limit. A chunk can therefore contain several `INSERT` statements. The formatter will fail clearly when one encoded row cannot fit inside the limit; it must never emit an oversized statement.

The standalone installer remains responsible for destination compatibility. Before executing import SQL on every newly opened importer connection, it will inspect the archive SQL supplied for the current import and determine whether it contains a zero-date datetime default. If necessary, it will remove only `NO_ZERO_DATE` and `NO_ZERO_IN_DATE` from that connection's `sql_mode`. It will not change the global server mode, restart the server, or rewrite schemas or row values.

## Export Statement Sizing

`SqlDumpFormatter` will use an 8 MiB maximum INSERT statement size by default. `BackupManagerFactory` and `Plugin::backupStepRunner()` will construct the formatter with that default. The formatter will produce one or more complete `INSERT INTO ... VALUES ...;` statements.

For each row, the formatter will first encode the row exactly as it does today. It will append the row to the current statement only when the completed statement remains within the configured limit. When it would exceed the limit, the formatter will close the current statement and begin another statement using the same table and column list.

If the encoded single-row statement exceeds the configured limit, the formatter will throw a specific exception that identifies the table and explains that a larger backup statement limit is required. No partial SQL is written for that row.

An 8 MiB statement limit is conservative relative to common MySQL packet defaults and leaves protocol overhead headroom. Existing backup chunk row-count planning remains in place; byte-bounded statements add an independent safety limit for large post content, serialized options, and other variable-length values.

## Import Session Compatibility

A focused detector will scan `CREATE TABLE` SQL outside quoted string literals for a zero-date `DEFAULT` clause. It returns a boolean and does not alter the SQL.

When the detector finds a legacy zero-date default, the importer will query its current session `sql_mode`, remove only `NO_ZERO_DATE` and `NO_ZERO_IN_DATE`, and issue `SET SESSION sql_mode = ...` before executing backup statements. This happens after connecting and before the first import statement. Since the resumable importer opens a connection on every request, setup runs on every step.

If mode inspection or the session update fails, import stops before schema execution with a concise warning that explains the backup needs legacy zero-date compatibility. The warning must not include credentials. The installer will never issue `SET GLOBAL`.

## Errors and Recovery

The existing detailed failed-statement warning remains the source diagnostic. It will add a targeted explanation for:

- MySQL 1067 with a zero-date default: the archive uses a legacy datetime default and the importer could not enable connection-local compatibility.
- MySQL 2006: the server closed the connection while handling a statement; the message states the statement byte length and recommends checking `max_allowed_packet` or the MySQL error log if the connection cannot be reopened.

Staging-table isolation and cursor persistence are unchanged. A failed import continues to leave destination tables untouched; a subsequent request can resume from the saved statement cursor when the server permits it.

## Tests

Unit coverage will prove:

- SQL modes are adjusted only through a `SET SESSION` statement when a chunk contains a zero-date default.
- A normal schema produces no SQL-mode query or change.
- The importer repeats connection-local compatibility setup on resumed imports.
- Generated multi-row inserts are split below the configured byte limit while preserving rows and escaping.
- An individually oversized row raises a clear exception rather than generating an oversized statement.
- Error 2006 warnings include the failed statement size and exclude credentials.

All existing importer safety, encoding-normalization, and resumability tests must continue to pass.
