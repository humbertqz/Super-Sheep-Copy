# Installer Staged Table Swap and URL Replacement Planning Design

## Goal

Plan the first destination database table swap path inside the standalone installer, with a pre-swap URL replacement plan recorded for the staged tables before they become the destination WordPress tables.

## Context

The installer can already:

- validate the staged archive
- require token access and explicit restore confirmation
- prepare a rollback artifact
- dump the current destination database when possible
- import backup SQL chunks into isolated staging tables

The next restore-side risk is replacing destination database tables. This slice should add the guarded swap orchestration and metadata needed for the later URL replacement executor, but it should not implement serialization-safe database URL mutation yet.

## Recommended Approach

Use a guarded, installer-only swap workflow:

1. Verify restore confirmation, rollback preparation, rollback database dump, and completed staged import.
2. Build a URL replacement plan from source site/home URLs to the detected destination URL.
3. Verify every expected staging table exists.
4. Verify destination table names are safe and correspond to staged import source tables.
5. Record the URL replacement plan in installer config before swap.
6. Rename destination tables to deterministic backup names when they exist.
7. Rename staging tables into the destination table names.
8. Record swap metadata in installer config.
9. Keep installer locked after successful swap so the action cannot rerun.

This slice records what URL replacement should do next. Actual serialized database value replacement should be a dedicated follow-up slice using the shared URL replacement engine plus column scanning.

## Scope

Included:

- Validate staged import metadata before table swap.
- Verify staging tables exist through the destination database connection.
- Build a pre-swap URL replacement plan with source URL variants and destination URL.
- Store URL replacement plan metadata in installer config.
- Rename destination tables to deterministic backup names.
- Rename staging tables into destination table names.
- Record swapped table count, backup table map, swap timestamp, and installer lock.
- Bootstrap action gated by token, confirmation, rollback dump, and staged import.

Excluded:

- Executing URL replacement in database values.
- File extraction/restore.
- File URL replacement.
- Rollback execution.
- Maintenance mode implementation.
- Cache clearing.
- Installer deletion.
- Real MySQL integration tests.

## Components

### `DatabaseTableInspector`

New installer class:

`super-sheep-copy/installer/restore-engine/DatabaseTableInspector.php`

Responsibilities:

- Connect with `mysqli` using credentials from `WpConfigReader`.
- Report whether a table exists.
- Validate table existence for the staging table map.
- Keep returned values secret-free.

### `DatabaseUrlReplacementPlanBuilder`

New installer class:

`super-sheep-copy/installer/restore-engine/DatabaseUrlReplacementPlanBuilder.php`

Responsibilities:

- Accept source site URL, source home URL, destination URL, and table map.
- Normalize non-empty URLs by trimming trailing slashes.
- Build unique source variants:
  - exact source site URL
  - exact source home URL
  - `http` and `https` scheme variants when host/path match
  - `www.` and non-`www.` host variants when parseable
- Return a plan array:

```php
array(
    'status' => 'planned',
    'source_urls' => array('https://source.example', 'http://source.example'),
    'destination_url' => 'https://destination.example',
    'table_count' => 2,
    'tables' => array('wp_posts', 'wp_options'),
    'planned_at' => '2026-05-19T12:00:00+00:00',
)
```

It must not mutate database rows.

### `DatabaseTableSwapManager`

New installer class:

`super-sheep-copy/installer/restore-engine/DatabaseTableSwapManager.php`

Responsibilities:

- Enforce gates:
  - `restore_confirmed` is true
  - `rollback_prepared` is true
  - `rollback_database_dump` is non-empty
  - `database_import_staged` is true
  - `database_tables_swapped` is not already true
  - config is not locked
- Read database credentials.
- Verify database connection.
- Validate `database_import_staging_tables`.
- Build the URL replacement plan.
- Verify all staging tables exist.
- Execute `RENAME TABLE` statements to move existing destination tables to backup names and staging tables to destination names.
- Update installer config:
  - `database_url_replacement_plan`
  - `database_url_replacement_planned_at`
  - `database_tables_swapped`
  - `database_tables_swapped_at`
  - `database_swap_table_count`
  - `database_swap_backup_tables`
  - `locked`

Backup table names should be deterministic for one run:

`ssc_old_<8-char job hash>_<sanitized destination table name>`

### `Bootstrap`

Add a new installer section after Database Import:

- If restore not confirmed: show table swap requires confirmation.
- If rollback not prepared: show table swap requires rollback preparation.
- If rollback DB dump missing: show table swap requires database rollback dump.
- If staged import missing: show table swap requires staged database import.
- If swap complete: show swapped table count and URL replacement plan status.
- If ready: show POST form with `name="swap_database_tables"` and button `Swap Database Tables`.

POST handling:

- Action key: `swap_database_tables=1`.
- Reuse token hidden field.
- On success, reload config and show `Database tables swapped`.
- On failure, show safe warning message.

## Safety

- Do not run swap without a rollback database dump.
- Do not run swap without completed staged import metadata.
- Do not render or store DB password.
- Only rename tables from `database_import_staging_tables`.
- Quote identifiers and reject names with invalid characters before SQL execution.
- Refuse repeated swap when `database_tables_swapped` is true.
- Set `locked` after successful swap.
- Record URL replacement plan before swap so post-swap work knows exact source/destination mapping.

## Testing

Unit tests cover:

- URL replacement plan builds unique source variants and destination metadata.
- URL replacement plan rejects empty destination URLs.
- Table inspector reports missing staging tables using fake query objects where possible.
- Table swap manager rejects missing confirmation, missing rollback, missing DB rollback dump, missing staged import, locked config, and repeated swap.
- Table swap manager records config metadata after a fake successful swap.
- Bootstrap renders table swap gating/status and does not expose secrets.

No real MySQL integration test is required in this slice.

## Success Criteria

- Installer has a token-gated database table swap action in plan.
- URL replacement execution remains out of scope but has persisted planning metadata.
- Swap is gated by confirmation, rollback dump, and staged import.
- Destination tables are only replaced by renamed staging tables.
- Full lint and PHPUnit pass when implementation plan is executed.

## Next Slice

After table swap planning, next useful work is URL replacement execution:

- scan swapped database tables and text columns
- use shared serialization-safe value replacement
- update `siteurl` and `home` options explicitly
- report replacements per table/column
- clear caches and lock/delete installer after full restore success
