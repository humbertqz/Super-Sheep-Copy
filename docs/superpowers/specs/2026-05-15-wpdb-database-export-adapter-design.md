# WPDB Database Export Adapter Design

## Goal

Add a WordPress `$wpdb` adapter that feeds real database metadata and row chunks into the existing database export core without making the core depend on WordPress globals.

This slice bridges the pure export classes to WordPress data access. It does not write SQL files, create archives, persist resume state, or add admin UI.

## Scope

Included:

- A small `WpdbClientInterface` that exposes only the database operations the exporter needs.
- A runtime `WpdbClient` wrapper around the real `$wpdb` object.
- A `WpdbDatabaseExporter` service that:
  - Discovers table names.
  - Builds `TableSchema` objects from `SHOW CREATE TABLE`, primary key metadata, row counts, charset, and collation.
  - Builds SQL query strings from `ChunkPlan`.
  - Fetches row chunks and returns `TableRows`.
- Unit tests using a fake client. Tests must not boot WordPress or require MySQL.

Excluded:

- Writing database chunk files.
- Writing `database/tables.json` to disk.
- Backup job orchestration.
- Admin UI.
- WP-CLI.
- Import/restore.

## Architecture

Add classes under `super-sheep-copy/src/Backup/Database/`.

`WpdbClientInterface` defines a narrow data-access port:

- `getTables(): array`
- `getCreateTableSql(string $table): string`
- `getPrimaryKey(string $table): ?string`
- `getRowCount(string $table): int`
- `getTableStatus(string $table): array`
- `getRows(string $sql): array`
- `prepare(string $sql, array $args): string`

`WpdbClient` accepts a `$wpdb`-like object in its constructor. It calls WordPress database APIs at runtime, including `prepare()` for value placeholders. It must not be used in unit tests.

`WpdbDatabaseExporter` accepts `WpdbClientInterface`. It validates identifiers before constructing SQL because table and column identifiers cannot safely be passed as normal SQL value placeholders. It uses the existing `TableSelector`, `TableSchema`, `ChunkPlan`, and `TableRows` classes.

## Query Rules

Identifier validation allows only letters, numbers, and underscores for table and column identifiers. Invalid identifiers throw `InvalidArgumentException`.

Table discovery uses `SHOW TABLES`.

Schema discovery uses:

- `SHOW CREATE TABLE \`table\``
- `SHOW KEYS FROM \`table\` WHERE Key_name = 'PRIMARY'`
- `SELECT COUNT(*) FROM \`table\``
- `SHOW TABLE STATUS LIKE %s`

Chunk query building:

- Primary-key strategy:
  - First chunk: `SELECT * FROM \`table\` ORDER BY \`pk\` ASC LIMIT %d`
  - Later chunks: `SELECT * FROM \`table\` WHERE \`pk\` > %d ORDER BY \`pk\` ASC LIMIT %d`
- Offset strategy:
  - `SELECT * FROM \`table\` LIMIT %d OFFSET %d`

`fetchRows(ChunkPlan $plan, array $columns)` runs the prepared chunk query and returns `TableRows`.

## Error Handling

The adapter throws `InvalidArgumentException` for invalid identifiers or empty required inputs.

The adapter throws `RuntimeException` when required database metadata is missing, such as `SHOW CREATE TABLE` returning no SQL.

The fake client used in tests should record SQL strings and arguments, so tests verify query shape and prepared values.

## Testing

Unit tests cover:

- Table discovery and selection through `TableSelector`.
- Schema building from fake database metadata.
- Primary-key chunk query construction with and without `lastSeenId`.
- Offset chunk query construction.
- Row fetching into `TableRows`.
- Rejection of unsafe identifiers.

No WordPress bootstrap or database server is required.

## Future Work

The next slice should write exported schema and row chunks to a backup working directory and produce `database/tables.json` using `DatabaseExportManifestBuilder`.

