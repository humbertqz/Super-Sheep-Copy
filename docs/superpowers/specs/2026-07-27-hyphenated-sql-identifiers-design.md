# Hyphenated SQL Identifiers Design

## Goal

Allow backups to export database tables whose table names, column names, or
primary-key column names contain hyphens, such as `play-large`, without
weakening the exporter's SQL-injection defenses.

## Root Cause

`WpdbDatabaseExporter` and `WpdbClient` currently accept SQL identifiers only
when they contain ASCII letters, digits, or underscores. MySQL permits a
hyphenated identifier when it is enclosed in backticks, and the export queries
already enclose accepted identifiers in backticks. The restrictive allowlist
therefore rejects a legitimate identifier before the query can run.

The production data flow is:

1. `WpdbClient` discovers table and column metadata from MySQL.
2. `WpdbDatabaseExporter` validates the discovered identifiers.
3. The exporter builds backtick-delimited chunk queries.
4. `SqlDumpFormatter` writes backtick-delimited identifiers into the dump.

The failure occurs at step 2 for `play-large`.

## Design

Extend the existing identifier allowlist from letters, digits, and underscores
to letters, digits, underscores, and hyphens. Apply the same rule in
`WpdbDatabaseExporter` and `WpdbClient` so metadata queries and chunk queries
have one consistent security boundary.

Keep the current backtick delimiters around table and column identifiers.
Hyphens cannot terminate a backtick-delimited identifier, so admitting them
does not create a new SQL fragment or injection path.

Do not broaden support to spaces, quotes, backticks, control characters, path
separators, or arbitrary MySQL identifier characters in this change. Those
characters would require coordinated SQL escaping and backup chunk filename
rules beyond the reported failure.

## Error Handling

Empty identifiers and identifiers containing characters outside the revised
allowlist continue to throw `InvalidArgumentException`. Existing injection
examples such as `wp_posts;DROP` and identifiers containing backticks remain
rejected before a database query is issued.

## Testing

Add focused regression tests proving that:

- A hyphenated table name can be used for schema and metadata queries.
- A hyphenated primary-key column can be used in a chunk query.
- A hyphenated column returned by metadata can be exported in a row chunk.
- Existing malicious table and column identifier tests still reject their
  inputs.

Run the focused PHPUnit tests first, followed by the complete PHPUnit suite and
the repository's PHP lint command.

## Scope

This change is limited to identifier compatibility in the database backup
export path. It does not alter table selection, chunk planning, dump value
escaping, archive packaging, restore behavior, or the plugin's supported
WordPress and PHP versions.
