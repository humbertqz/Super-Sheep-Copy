# Database Backup Coordinator Design

## Goal

Compose the existing database export pieces into an end-to-end database backup coordinator that exports selected tables into a backup working directory.

The coordinator should produce:

```text
database/
  tables.json
  chunks/
    wp_posts.part001.sql
    wp_posts.part002.sql
    wp_options.part001.sql
```

## Scope

Included:

- `DatabaseBackupCoordinator` under `super-sheep-copy/src/Backup/Database/`.
- Coordination of:
  - `WpdbDatabaseExporter`
  - `ChunkPlanner`
  - `SqlDumpFormatter`
  - `DatabaseExportWriter`
- Table selection by prefix and mode.
- Chunk planning by table row count and configured chunk size.
- Schema SQL included in the first chunk for each table.
- Row SQL included in each chunk.
- Primary-key and offset pagination support.
- Unit tests with fake exporter data and temporary directories.

Excluded:

- Resume state.
- Admin UI.
- Job runner integration.
- ZIP archive integration.
- Progress display.
- Restore/import.
- Live MySQL integration tests.

## Architecture

`DatabaseBackupCoordinator` receives these dependencies:

- `WpdbDatabaseExporter $exporter`
- `ChunkPlanner $chunk_planner`
- `SqlDumpFormatter $formatter`
- `DatabaseExportWriter $writer`

It exposes:

```php
public function export(string $working_directory, string $table_prefix, string $selection_mode, int $chunk_size): void
```

The coordinator does not query `$wpdb` directly. It only uses `WpdbDatabaseExporter`.

## Data Flow

1. Select tables with `$exporter->selectTables($table_prefix, $selection_mode)`.
2. Build a `TableSchema` for each selected table.
3. For each table, calculate the number of chunks as `ceil(rowCount / chunkSize)`, with a minimum of one chunk so empty tables still write schema.
4. Create `ChunkPlan` objects with `ChunkPlanner`.
5. Fetch rows with `$exporter->fetchRows($plan, $columns)`.
6. Format SQL:
   - First chunk: `formatSchema($schema) . formatRows($rows)`.
   - Later chunks: `formatRows($rows)`.
7. Pass schemas, plans, and SQL strings to `DatabaseExportWriter`.

## Column Discovery

The existing adapter can fetch rows only when the caller provides ordered columns. This slice should add a small method to `WpdbDatabaseExporter`:

```php
public function getColumns(string $table): array
```

It delegates to a new `WpdbClientInterface::getColumns(string $table): array` method. The runtime `WpdbClient` should implement it using `SHOW COLUMNS FROM \`table\`` and return column names in database order.

Tests use fake clients; no WordPress bootstrap is required.

## Primary-Key Last-Seen Handling

For primary-key pagination, the coordinator tracks the last fetched primary key value from each returned row set:

- First primary-key chunk uses `lastSeenId = null`.
- Later primary-key chunks use the last primary key value from the previous non-empty row set.
- If a chunk returns no rows, later chunks for that table are not needed because the planned chunk count comes from `rowCount()`.

This assumes integer-like primary keys for the first implementation, matching current `ChunkPlan::lastSeenId(): ?int`.

## Error Handling

The coordinator throws `InvalidArgumentException` when `chunk_size < 1`.

If the selected table list is empty, the coordinator still calls `DatabaseExportWriter` with empty schemas, empty plans, and empty SQL. This produces an empty `tables.json` manifest.

Missing columns or unsafe identifiers are handled by `WpdbDatabaseExporter` and `TableRows`.

## Testing

Unit tests cover:

- Exporting a primary-key table into multiple chunks.
- Exporting an empty table with schema-only first chunk.
- Exporting an offset-paginated table.
- Empty table selection producing an empty manifest.
- Rejecting invalid chunk size.

Tests should assert written chunk file contents and `database/tables.json` structure.

## Future Work

The next slice should connect this coordinator to a backup manager/job flow that creates a working directory, invokes database export, scans files, and passes everything to the archive writer.

