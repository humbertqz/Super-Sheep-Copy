# Database Export Core Design

## Goal

Build a WordPress-free database export core for Super Sheep Copy that can decide which tables to export, plan chunked reads, format SQL dump chunks, and produce database metadata for the backup archive.

This slice does not connect to MySQL and does not call `$wpdb`. It creates deterministic, unit-tested building blocks that a later WordPress adapter can feed with real schema and row data.

## Scope

Included:

- Table selection modes:
  - `prefixed`: all tables using the active WordPress table prefix.
  - `core`: known WordPress core tables using the active prefix.
  - `all`: every discovered table.
- Table schema and row value objects.
- Chunk planning:
  - Use primary-key pagination when a primary key is known.
  - Use offset pagination when no primary key is available.
  - Produce deterministic chunk file names like `wp_posts.part001.sql`.
- SQL dump formatting:
  - Include schema SQL.
  - Format row inserts.
  - Escape strings, nulls, booleans, integers, floats, and binary-looking values defensively enough for dump chunks.
- Database export manifest metadata for `database/tables.json`.

Excluded for this slice:

- `$wpdb` queries.
- Live database export.
- Writing chunk files to disk.
- Resume state persistence.
- Import/restore.
- Admin UI controls for database export.

## Architecture

Add focused classes under `super-sheep-copy/src/Backup/Database/`.

`TableSelector` receives discovered table names, the WordPress prefix, and a selection mode. It returns ordered table names. It has no database dependency.

`TableSchema` represents the schema for one table: table name, create-table SQL, primary key column if known, row count, and collation/charset metadata if provided.

`TableRows` represents one chunk of rows for a table. It stores the table name, ordered column names, and rows as associative arrays.

`ChunkPlanner` receives a `TableSchema`, chunk size, and current chunk number. It returns a `ChunkPlan` describing the chunk file name, pagination strategy, and query bounds. Primary-key tables use `WHERE pk > last_seen ORDER BY pk ASC LIMIT n`; tables without a primary key use `LIMIT n OFFSET x`.

`SqlDumpFormatter` receives `TableSchema` and `TableRows` and returns SQL text suitable for a chunk file. It will format schema and insert statements separately so future archive code can place schema in the first chunk or a schema file.

`DatabaseExportManifestBuilder` receives table schemas and chunk plans and returns an array matching the future `database/tables.json` structure.

## Data Flow

1. A future `$wpdb` adapter discovers tables and schema details.
2. `TableSelector` filters the discovered table names.
3. For each selected table, `ChunkPlanner` creates chunk plans.
4. The future adapter fetches rows for each plan.
5. `SqlDumpFormatter` formats each row chunk.
6. `DatabaseExportManifestBuilder` records selected tables, row counts, chunk files, and pagination strategy.

In this slice, tests simulate steps 1 and 4 with arrays.

## Error Handling

Core classes should reject invalid input with `InvalidArgumentException`:

- Empty table names.
- Empty prefixes for `prefixed` or `core` selection.
- Chunk size less than 1.
- Chunk number less than 1.
- Rows missing expected columns.

SQL formatting should not silently drop row fields. A row with missing columns should throw, because an incomplete dump chunk is worse than a loud export failure.

## Testing

Unit tests will cover:

- `prefixed`, `core`, and `all` table selection.
- Primary-key and offset chunk plan generation.
- Chunk file naming with zero-padded indexes.
- SQL formatting for schema and inserts.
- Escaping of strings with quotes, backslashes, nulls, booleans, integers, and floats.
- Manifest metadata structure for selected tables and chunks.

No WordPress bootstrap or database server is required for these tests.

## Future Work

The next implementation slice should add a `$wpdb` adapter that:

- Discovers table names for the selected mode.
- Reads `SHOW CREATE TABLE`.
- Detects primary keys.
- Counts rows.
- Fetches chunks using `ChunkPlan`.
- Writes SQL chunks and `database/tables.json` into the backup working directory.

