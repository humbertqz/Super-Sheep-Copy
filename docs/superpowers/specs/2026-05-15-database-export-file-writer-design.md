# Database Export File Writer Design

## Goal

Write database export output into the backup working directory using the existing export core:

```text
database/
  tables.json
  chunks/
    wp_posts.part001.sql
    wp_options.part001.sql
```

This slice turns formatted database export strings and metadata into files. It does not query WordPress, create ZIP archives, run backup jobs, or update admin UI.

## Scope

Included:

- `DatabaseExportWriter` under `super-sheep-copy/src/Backup/Database/`.
- Creation of `database/` and `database/chunks/` under a caller-provided working directory.
- Writing SQL chunk files from `ChunkPlan` objects and formatted SQL strings.
- Writing `database/tables.json` using `DatabaseExportManifestBuilder`.
- Path safety checks so chunk filenames cannot escape the working directory.
- Unit tests using temporary directories.

Excluded:

- `$wpdb` querying.
- Archive writing.
- Backup manager orchestration.
- Resume state.
- Admin UI.
- WP-CLI.
- Restore/import.

## Architecture

`DatabaseExportWriter` accepts a `DatabaseExportManifestBuilder` in its constructor.

It exposes one main method:

```php
public function write(string $working_directory, array $schemas, array $plans_by_table, array $sql_by_chunk): void
```

Inputs:

- `$working_directory`: backup working directory root.
- `$schemas`: list of `TableSchema` objects.
- `$plans_by_table`: map of table name to `ChunkPlan[]`.
- `$sql_by_chunk`: map of chunk file name to SQL string.

The writer creates directories when missing, writes each chunk to `database/chunks/{chunk-file}`, then writes `database/tables.json` as pretty JSON.

## Path Safety

Chunk file names must be plain relative file names. The writer rejects:

- Empty names.
- Names containing `/` or `\`.
- Names containing `..`.
- Absolute paths.
- Null bytes.
- Names not ending in `.sql`.

Unsafe paths throw `InvalidArgumentException`.

## Error Handling

The writer throws `RuntimeException` if it cannot create a directory or write a file.

The writer throws `InvalidArgumentException` if a planned chunk is missing from `$sql_by_chunk`, or if a chunk file name is unsafe.

## Testing

Unit tests cover:

- Directory creation.
- SQL chunk file writing.
- `tables.json` writing.
- Rejection of unsafe chunk names.
- Rejection of missing SQL for a planned chunk.

Tests use `sys_get_temp_dir()` and remove their temporary directories in `tearDown()`.

## Future Work

The next slice should compose `WpdbDatabaseExporter`, `ChunkPlanner`, `SqlDumpFormatter`, and `DatabaseExportWriter` into a database backup coordinator that exports selected tables end-to-end into a working directory.

