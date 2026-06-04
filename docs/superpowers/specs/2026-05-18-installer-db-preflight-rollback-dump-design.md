# Installer DB Preflight and Rollback Dump Design

## Goal

Add the next non-destructive installer restore milestone: verify destination database connectivity and create a destination database rollback SQL dump before any future database import.

## Context

The standalone installer already validates the staged archive, detects the destination URL, records explicit restore confirmation, and prepares a rollback artifact containing `wp-config.php` and a rollback manifest.

The next restore-side risk is database replacement. Before import exists, the installer needs two safe building blocks:

- a database credential reader/connection test for preflight confidence
- a rollback SQL dump of the current destination database

This slice does not import the backup database and does not modify destination tables.

## Recommended Approach

Use a small installer-only database layer:

1. Extend `WpConfigReader` so it can return parsed database credentials to trusted installer services, while keeping existing secret-free summary output for UI/preflight checks.
2. Add a `DatabaseConnectionTester` that accepts credential arrays and tests a `mysqli` connection without exposing the password in returned output.
3. Add a `RollbackDatabaseDumper` that writes a rollback SQL file under the current rollback artifact directory.
4. Extend `RollbackPreparationManager` so rollback preparation can include a destination database dump when credentials are readable and connection succeeds.
5. Extend Bootstrap preflight/UI to show database connectivity and rollback database dump status.

This keeps all destructive restore work out of scope while creating rollback safety needed before import.

## Scope

Included:

- Parse `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`, and `$table_prefix` from simple literal `wp-config.php` definitions.
- Split `DB_HOST` into host, port, and socket where possible.
- Test destination database connectivity with `mysqli` when the extension exists.
- Return safe status objects that never include passwords.
- Dump existing destination tables matching the detected table prefix.
- Write dump to `rollback/<rollback-id>/database/destination.sql`.
- Add dump metadata to rollback manifest/config.
- Show installer UI status for database connection and rollback database dump.

Excluded:

- Manual database credential entry.
- Database import.
- Dropping, truncating, renaming, or replacing tables.
- Temporary table staging.
- URL replacement during import.
- Rollback execution.
- Non-literal `wp-config.php` parsing such as environment variables or function calls.

## Components

### `WpConfigReader`

Current `readDatabaseConfig()` returns only booleans and table prefix. Keep that method secret-free.

Add `readDatabaseCredentials(string $wordpress_root): array` for trusted installer internals. It returns:

- `readable`
- `complete`
- `name`
- `user`
- `password`
- `host`
- `port`
- `socket`
- `table_prefix`

The parser supports simple WordPress-style lines:

```php
define('DB_NAME', 'wordpress');
define('DB_USER', 'dbuser');
define('DB_PASSWORD', 'secret');
define('DB_HOST', 'localhost:3306');
$table_prefix = 'wp_';
```

The secret-bearing method must not be used directly in rendered output.

### `DatabaseConnectionTester`

Add `super-sheep-copy/installer/restore-engine/DatabaseConnectionTester.php`.

It accepts credentials from `WpConfigReader` and returns:

- `connected`
- `status`
- `message`
- `database`
- `host`

If `mysqli` is unavailable, return warning status. If credentials are incomplete, return warning status. If connection fails, return error status with a generic message and no password.

### `RollbackDatabaseDumper`

Add `super-sheep-copy/installer/restore-engine/RollbackDatabaseDumper.php`.

It connects with `mysqli`, discovers tables matching the table prefix, and writes SQL into `database/destination.sql` inside the rollback artifact.

Dump behavior:

- Include a generated header with UTC timestamp and database name.
- Include `CREATE TABLE` statements.
- Include `INSERT` statements for table rows.
- Quote identifiers with backticks after escaping backticks.
- Escape values through the active `mysqli` connection.
- Represent `NULL` as `NULL`.
- Dump only prefixed tables. If prefix is empty, return warning and do not dump.
- If there are no matching tables, write a valid dump header and return zero table count.

### `RollbackPreparationManager`

Extend the manager to accept optional database dependencies:

- `WpConfigReader`
- `DatabaseConnectionTester`
- `RollbackDatabaseDumper`

During preparation:

1. Keep current confirmation/locked checks.
2. Create rollback directory.
3. Collect file snapshot.
4. Read database credentials.
5. If credentials are incomplete or `mysqli` is missing, continue with a warning and no DB dump.
6. If connection fails, continue with a warning and no DB dump.
7. If connection succeeds, dump destination DB to `database/destination.sql`.
8. Add database rollback fields to manifest/config.

Rollback preparation remains non-destructive.

### Bootstrap UI

Extend installer output:

- Preflight checks show database connection status.
- Rollback section shows whether a DB dump was included.
- Existing confirmed restore gate remains required before rollback preparation.
- Existing token protection remains required.

## Data Fields

Config additions after rollback preparation:

- `rollback_database_dump`: relative path such as `rollback/<id>/database/destination.sql`, empty when skipped
- `rollback_database_table_count`: integer
- `rollback_database_dumped_at`: ISO-8601 UTC timestamp, only when dumped

Manifest additions:

```json
{
  "database": {
    "included": true,
    "dump_path": "database/destination.sql",
    "table_count": 12,
    "warnings": []
  }
}
```

When skipped:

```json
{
  "database": {
    "included": false,
    "dump_path": "",
    "table_count": 0,
    "warnings": ["Database credentials are incomplete."]
  }
}
```

## Security

- Never render or log `DB_PASSWORD`.
- Never store parsed DB password in rollback manifest.
- Do not dump unprefixed tables.
- Do not run destructive SQL in this slice.
- Keep installer access protected by existing restore token.
- Keep direct request globals limited to Bootstrap/Security boundary.

## Testing

Unit tests cover:

- `WpConfigReader` parses secret credentials but `readDatabaseConfig()` remains secret-free.
- DB host parsing handles `localhost`, `localhost:3307`, and socket-like values.
- `DatabaseConnectionTester` reports incomplete credentials and missing `mysqli` safely.
- `RollbackDatabaseDumper` SQL formatting for values, nulls, identifiers, and empty table lists using small fake result objects where possible.
- `RollbackPreparationManager` records DB dump metadata when dumper succeeds and warning metadata when DB dump is skipped.
- Bootstrap renders DB connection and rollback DB dump statuses without exposing secrets.

Integration with a real MySQL server is out of scope for PHPUnit in this slice.

## Success Criteria

- Installer can report whether destination DB credentials are complete and testable.
- Rollback preparation can include a destination DB rollback dump when available.
- No restore/import/destructive database operations exist.
- Full lint and PHPUnit pass.
- Git worktree is clean after commits.

## Next Slice

After this slice, the next useful work is database import planning:

- read backup archive `database/tables.json`
- stream SQL chunks from backup archive
- import into temporary/staged tables
- apply URL replacement safely
- swap destination tables only after rollback dump exists
