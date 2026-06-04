# Installer Preflight and Confirmation Design

## Goal

Add a non-destructive installer preflight and restore-confirmation gate. After token verification and archive validation, the standalone installer will detect the destination URL, inspect destination environment readiness, detect whether database credentials can be read from `wp-config.php`, show a source-to-destination restore preview, and require explicit confirmation before any future destructive restore step can run.

This slice does not import databases, extract files, replace files, replace URLs, create rollback backups, enter maintenance mode, or lock/delete the installer.

## Current Context

The project already has:

- Plugin-side restore archive staging.
- Plugin-side standalone installer deployment to `ABSPATH/installer.php` and `ABSPATH/ssc-restore-engine/`.
- Installer `config.php` containing the staged archive path, source URLs, token hash, and lock flag.
- Installer token verification in `Security`.
- Installer-local archive validation in `ArchiveValidator`.
- `Bootstrap`, which renders a token-gated page with environment checks and archive metadata.

The missing safety gate is a structured preflight and explicit confirmation step before rollback and destructive restore work are added.

## Chosen Approach

Implement read-only preflight and confirmation inside the standalone installer.

New installer-local classes:

- `DestinationDetector`
- `WpConfigReader`
- `PreflightChecker`
- `ConfirmationStore`

`Bootstrap` will coordinate these classes after token verification. It will render:

1. Prepared archive metadata.
2. Source and destination URL preview.
3. Preflight check table.
4. Explicit warning that the destination site will eventually be replaced.
5. Confirmation form requiring both a checkbox and typed phrase.

The confirmation state will be stored in the installer engine `config.php` by updating safe installer-only keys. This keeps the state available to the next slice without requiring WordPress, a database, sessions, or cookies.

## Destination URL Detection

Add `DestinationDetector` under `super-sheep-copy/installer/restore-engine/`.

It will expose:

```php
detect(array $server): string
```

The detector will:

1. Prefer HTTPS when `HTTPS=on` or `HTTP_X_FORWARDED_PROTO=https`.
2. Fall back to HTTP otherwise.
3. Use `HTTP_HOST` if present.
4. Fall back to `SERVER_NAME`.
5. Use the current script directory from `SCRIPT_NAME`, removing `/installer.php`.
6. Return a URL without a trailing slash except for the root path.

Examples:

- `HTTP_HOST=example.com`, `SCRIPT_NAME=/installer.php` returns `http://example.com`.
- `HTTPS=on`, `HTTP_HOST=example.com`, `SCRIPT_NAME=/subsite/installer.php` returns `https://example.com/subsite`.
- missing host returns an empty string and creates a preflight warning.

## wp-config Credential Detection

Add `WpConfigReader` under `super-sheep-copy/installer/restore-engine/`.

It will expose:

```php
readDatabaseConfig(string $wordpress_root): array
```

The reader will:

1. Look for `wp-config.php` in the WordPress root.
2. Return `readable=false` if missing or unreadable.
3. Parse only simple `define('DB_NAME', '...')`, `define('DB_USER', '...')`, `define('DB_PASSWORD', '...')`, `define('DB_HOST', '...')`, and `$table_prefix = '...'` values.
4. Return booleans indicating which credential values are present.
5. Never render or store database passwords.

This slice will not connect to the database. It only reports whether credentials appear available for a future connection test/import slice.

## Preflight Checks

Add `PreflightChecker` under `super-sheep-copy/installer/restore-engine/`.

It will receive:

- `EnvironmentChecker`
- `DestinationDetector`
- `WpConfigReader`
- `ArchiveValidator`

It exposes:

```php
run(array $config, array $server, string $engine_dir): array
```

The returned list contains check arrays with:

- `key`
- `label`
- `status` as `ok`, `warning`, or `error`
- `value`
- `message`

Checks in this slice:

- PHP version.
- ZIP extension.
- Disk free space.
- staged archive readable.
- staged archive valid.
- destination URL detected.
- WordPress root detected.
- WordPress root writable.
- `wp-config.php` readable.
- database credential constants present.

Error checks block confirmation. Warning checks do not block confirmation but remain visible. Blocking errors include invalid archive and missing staged archive. Missing or unreadable `wp-config.php` is a warning in this slice because manual database credentials will be added later.

## Confirmation State

Add `ConfirmationStore` under `super-sheep-copy/installer/restore-engine/`.

It will expose:

```php
isConfirmed(array $config): bool
confirm(string $engine_dir, array $config, string $typed_phrase, bool $checkbox_checked, bool $has_blocking_errors): bool
```

The required typed phrase is:

```text
RESTORE
```

Confirmation succeeds only when:

- checkbox is checked
- typed phrase exactly equals `RESTORE`
- there are no blocking preflight errors

When confirmation succeeds, `ConfirmationStore` rewrites `config.php` with:

- `restore_confirmed` set to `true`
- `restore_confirmed_at` as an ISO-like GMT timestamp

It must preserve existing config keys including token hash, staged archive path, source URLs, and lock flag. It must not write secrets beyond what the installer config already contains.

## Bootstrap Flow

After token verification:

1. Load config.
2. Run preflight checks.
3. Validate the staged archive as part of preflight.
4. Read source URLs from validated manifest first, then config fallback.
5. Detect destination URL.
6. If the request is a confirmation POST:
   - require token still valid
   - require no blocking preflight errors
   - require checkbox and typed phrase
   - pass the blocking-error state to `ConfirmationStore`
   - update config with confirmation state on success
7. Render the page.

The page will show:

- Prepared archive section.
- Source URL and destination URL preview.
- Preflight checks.
- Confirmation status.
- Confirmation form if not confirmed.
- Clear note that restore execution is not implemented yet and no destructive action is run.

The token must continue through the confirmation POST as a hidden field or query-safe form action value. Direct request access remains limited to installer boundary files.

## Security

This slice enforces:

- token verification before all archive details, preflight details, and confirmation UI
- no database password rendering
- no destructive restore action
- no file extraction
- no database connection or import
- exact typed confirmation phrase
- blocking preflight errors prevent confirmation
- config updates limited to installer config state keys

## Error Handling

Installer-side failures render generic, non-sensitive messages:

- missing/invalid token shows only token form
- invalid archive shows a blocking preflight error
- unreadable `wp-config.php` shows a warning without paths beyond safe labels
- config write failure shows confirmation failure and leaves the page unconfirmed

The installer should avoid throwing fatal errors during ordinary preflight conditions.

## Testing

Add focused unit tests:

- `DestinationDetectorTest`
  - detects root HTTP URL
  - detects HTTPS subdirectory URL
  - returns empty string when host is missing
- `WpConfigReaderTest`
  - parses database constants and table prefix from a readable config
  - reports missing config as unreadable
  - does not expose raw password in display fields
- `PreflightCheckerTest`
  - reports ok archive and destination checks for valid config/server
  - reports blocking error for unreadable staged archive
  - reports warning for unreadable `wp-config.php`
- `ConfirmationStoreTest`
  - rejects missing checkbox
  - rejects wrong typed phrase
  - writes confirmation fields while preserving config
- `InstallerBootstrapTest`
  - valid token page shows source-to-destination preview and preflight checks
  - confirmation POST with checkbox and typed phrase shows confirmed status
  - confirmation POST without phrase remains unconfirmed
  - invalid token still hides archive and preflight details

Final verification:

- focused tests for new installer classes and bootstrap behavior
- full PHPUnit suite
- composer lint
- request-global scan confirming request access is limited to admin, nonce, and installer boundary files
- git status clean

## Scope Exclusions

This slice does not implement:

- database connection testing
- database import
- archive extraction
- file restore
- URL replacement execution
- rollback backup creation
- maintenance mode
- installer lock or delete
- cache clearing
- health checks
- manual database credential form

## Next Slice

After this slice, the next useful work is rollback preparation inside the installer: create a rollback backup plan and a minimal rollback archive of destination database/files before any destructive restore operation is allowed.
