# Installer Database URL Replacement Execution Design

## Goal

Execute serialization-safe URL replacement across the swapped destination database tables inside the standalone installer.

After staged tables are swapped into destination table names, the installer must replace source URLs with the detected destination URL in database values without corrupting PHP serialized values or JSON values. This slice uses the URL replacement plan recorded by the table swap step and applies it to all planned tables in one installer action.

## Context

The installer can already:

- validate the prepared archive
- require token access and explicit restore confirmation
- prepare rollback files and a rollback database dump
- import backup SQL chunks into isolated staging tables
- swap staged tables into destination table names
- persist `database_url_replacement_plan` before table swap

The shared library already provides:

- `SuperSheepCopy\Shared\Urls\UrlReplacementEngine`
- `SuperSheepCopy\Shared\Urls\StructuredValueReplacer`
- `SuperSheepCopy\Shared\Serialization\SerializationWalker`

Those shared classes should be loaded by the installer and reused rather than duplicating replacement logic.

## Scope

Included:

- Add a token-gated installer action after database table swap.
- Require confirmed restore, rollback preparation, rollback database dump, completed table swap, and a recorded URL replacement plan.
- Inspect all tables listed in `database_url_replacement_plan['tables']`.
- Replace URLs in text-like columns: `char`, `varchar`, `tinytext`, `text`, `mediumtext`, `longtext`, and `json`.
- Skip binary/blob columns and non-string scalar columns.
- Use serialization-safe replacement for plain strings, JSON arrays, and PHP serialized values.
- Update only changed cell values.
- Record replacement counts, scanned row counts, changed row counts, table count, start time, completion time, and safe warnings in installer config.
- Show URL replacement status and action in `Bootstrap`.

Excluded:

- Resumable batches.
- File URL replacement.
- Cache clearing.
- Rollback execution.
- Maintenance mode.
- Installer deletion.
- Real MySQL integration tests.

## Recommended Approach

Use one guarded installer action that performs URL replacement for all planned tables in one request.

This is simpler than resumable batching and matches the selected slice size. It is acceptable for this milestone because the existing installer workflow already performs import and swap as single actions. A later slice can make the executor resumable if large databases make request timeouts likely.

## Components

### `DatabaseTextColumnInspector`

New installer class:

`super-sheep-copy/installer/restore-engine/DatabaseTextColumnInspector.php`

Responsibilities:

- Connect to the destination database with credentials from `WpConfigReader`.
- Inspect table columns with `SHOW COLUMNS FROM <table>`.
- Return text-like column names for each planned table.
- Return the primary key column when a table has one.
- Reject unsafe table and column identifiers.
- Return safe warnings without exposing credentials.

The class should treat these column types as replaceable:

- `char`
- `varchar`
- `tinytext`
- `text`
- `mediumtext`
- `longtext`
- `json`

The class should not include:

- `binary`
- `varbinary`
- `blob`
- `tinyblob`
- `mediumblob`
- `longblob`
- numeric/date columns

### `DatabaseUrlReplacementExecutor`

New installer class:

`super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementExecutor.php`

Responsibilities:

- Connect with `mysqli`.
- For each planned table and text column, select row values.
- Prefer primary-key addressing when a primary key exists.
- Fall back to all-column selection for tables without a primary key, but only update when a deterministic row predicate can be built safely.
- Apply `StructuredValueReplacer` for each source URL from the plan to each string cell.
- Update changed values using escaped SQL values.
- Return aggregate counts and warnings.

The executor should quote identifiers with backticks after validating them against `/^[A-Za-z0-9_]+$/`.

The executor should count:

- scanned rows
- changed rows
- scanned cells
- changed cells
- total replacements

If a table has no text-like columns, it should be counted as scanned with zero changes.

### `DatabaseUrlReplacementManager`

New installer class:

`super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementManager.php`

Responsibilities:

- Enforce gates:
  - `restore_confirmed` is true
  - `rollback_prepared` is true
  - `rollback_database_dump` is non-empty
  - `database_tables_swapped` is true
  - `database_url_replacement_plan` is present and valid
  - `database_url_replacement_completed` is not true
- Read database credentials.
- Verify database connection with `DatabaseConnectionTester`.
- Ask `DatabaseTextColumnInspector` for table/column metadata.
- Run `DatabaseUrlReplacementExecutor`.
- Persist metadata in installer config.

Config keys written:

- `database_url_replacement_started_at`
- `database_url_replacement_completed`
- `database_url_replacement_completed_at`
- `database_url_replacement_table_count`
- `database_url_replacement_scanned_rows`
- `database_url_replacement_changed_rows`
- `database_url_replacement_scanned_cells`
- `database_url_replacement_changed_cells`
- `database_url_replacement_count`
- `database_url_replacement_warnings`

The manager should keep `locked` true. The installer remains locked because destructive restore work has already occurred and later restore phases are still pending.

### `Bootstrap`

Modify:

`super-sheep-copy/installer/restore-engine/Bootstrap.php`

Add required `require_once` lines for shared serialization and URL classes, plus the new installer classes.

Add a new section after `Database Table Swap`:

- If restore is not confirmed, show URL replacement requires restore confirmation.
- If rollback is not prepared, show URL replacement requires rollback preparation.
- If rollback database dump is missing, show URL replacement requires database rollback dump.
- If database tables are not swapped, show URL replacement requires swapped database tables.
- If URL replacement plan is missing, show URL replacement requires a recorded URL replacement plan.
- If URL replacement is complete, show changed row/cell/replacement counts.
- If ready, show POST form with `name="replace_database_urls"` and button text `Replace Database URLs`.

POST handling:

- Action key: `replace_database_urls=1`.
- Reuse token hidden field.
- On success, reload config and show `Database URLs replaced`.
- On failure, show the first safe warning or `Database URL replacement failed.`

## Data Flow

1. Table swap records `database_url_replacement_plan`.
2. User submits `replace_database_urls`.
3. `Bootstrap` verifies token and calls `DatabaseUrlReplacementManager`.
4. Manager validates config gates.
5. Manager reads DB credentials and checks connectivity.
6. Inspector discovers text columns for planned tables.
7. Executor reads string values, applies structured replacement, and updates changed values.
8. Manager writes completion metadata to `config.php`.
9. `Bootstrap` reloads config and renders status.

## Error Handling

Failures should return safe warning messages and should not expose:

- database password
- full SQL values
- auth keys or salts
- absolute server paths unless already shown by existing installer behavior

Expected warning cases:

- restore not confirmed
- rollback not prepared
- rollback database dump missing
- database tables not swapped
- URL replacement plan missing or malformed
- database credentials incomplete
- database connection failed
- table or column inspection failed
- update query failed

If replacement fails partway through, the manager should not mark `database_url_replacement_completed`. Any counts returned from the executor may be persisted only when they are clearly marked incomplete; this slice should prefer not writing partial success metadata beyond warnings.

## Testing

Unit tests should cover:

- Column inspector includes text-like columns and excludes binary/blob/non-text columns.
- Column inspector detects a primary key.
- URL replacement manager rejects missing confirmation, rollback, rollback database dump, table swap, URL plan, and repeated completion.
- URL replacement manager records completion metadata after a fake successful executor run.
- URL replacement executor updates plain values, JSON values, and PHP serialized values with the shared replacer.
- URL replacement executor skips unchanged values.
- Bootstrap renders gating/status/action for URL replacement and does not expose secrets.

Real MySQL integration tests are out of scope for this slice. Use fake connection/query objects where practical, following existing installer tests.

## Success Criteria

- Installer exposes a token-gated `Replace Database URLs` action after table swap.
- Replacement uses shared serialization-safe URL replacement classes.
- All planned tables and text-like columns are scanned.
- Changed cells are updated, unchanged cells are skipped.
- Completion metadata is persisted in installer config.
- Full PHPUnit suite passes.
