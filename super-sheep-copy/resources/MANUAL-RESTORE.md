# Restore without Super Sheep Copy

This package contains ordinary files and SQL. Neither WordPress nor the plugin
needs to run to recover it. Extract the ZIP/TAR (or copy the directory package)
to a private folder on your computer or server, outside the public web root.

## Check the backup first

Read `manifest.json`: source URLs, table prefix, server versions, `warnings`,
`exclusions`, and `skipped_files`. Large files may have been omitted (the default
limit is 250 MB); symlink targets are not included. Cache, backup storage, VCS,
node_modules, OS metadata, and temporary WCPDF attachments are excluded. A
successful checksum check confirms packaged bytes, not a complete site snapshot.

The backup reads a live site over multiple requests. For a consistent recovery
point, pause site writes, orders, uploads, cron workers, and deployments during
backup creation. Files outside the WordPress root (including a parent-folder
wp-config.php, external wp-content/uploads, symlinks, or external storage) need
separate backups. Custom database triggers, routines, and events are not dumped;
views are unsupported. Only the selected tables are included. Check
`database/tables.json`, especially for multisite or non-prefixed custom tables.

## Build and import the database

With PHP 7.4+ installed, run this from the extracted package folder:

```sh
php build-database.php
```

The standalone script verifies every entry in `checksums.json`, reads chunk
order from `database/tables.json`, and writes `database.sql` in this folder.
It does not connect to a database. It refuses to overwrite an existing output
and exits with an error if content is missing or corrupt. Keep all package files
unchanged while it runs. Do not pipe a failed or partial build into MySQL.

Create a new, empty database with your hosting tools, then import `database.sql`
using phpMyAdmin's Import tab or the MySQL/MariaDB command-line client:

```sh
mysql --host=localhost --user=YOUR_USER --password YOUR_NEW_DATABASE < database.sql
```

Use the actual host, port, database, and user for your destination. The SQL drops
and recreates backed-up tables, so importing into an existing database replaces
those tables. Stop on import errors. Use a compatible database version,
collations, and a max_allowed_packet large enough for the INSERT statements
(up to 8 MB by default). The dump sets the exported connection charset, disables
foreign-key checks during import, allows legacy zero dates, and preserves zero
AUTO_INCREMENT values. These session settings are restored at the end.

Without PHP, assemble the chunks yourself in the exact table/chunk array order
in `database/tables.json`. Do not sort chunk filenames alphabetically: part1000
sorts before part101. Run the chunks in one import session with the correct
connection charset, SQL_MODE='NO_AUTO_VALUE_ON_ZERO', and FOREIGN_KEY_CHECKS=0;
restore the session settings afterward. Each table's first chunk contains its
DROP/CREATE statements; following chunks contain its remaining rows.

## Restore the files

Copy the **contents of `files/`** to the destination WordPress root. Include hidden
files such as `.htaccess`; do not upload the package's database, logs, or manifests
to the public website. Set ownership/permissions appropriate to your host.

Edit the restored `wp-config.php` with the new DB_NAME, DB_USER, DB_PASSWORD,
DB_HOST, and the original `$table_prefix` from `manifest.json`. If wp-config.php
was outside the source root, recover it separately or create one with the correct
prefix and any custom settings. Check hard-coded paths, WP_HOME/WP_SITEURL,
multisite constants, and server-specific cache/drop-in configuration.

For a changed domain/path, use a serialization-aware tool such as WP-CLI after
the files/database are restored. Preview changes before applying them:

```sh
wp search-replace 'https://OLD.example' 'https://NEW.example' --all-tables-with-prefix --skip-columns=guid --dry-run
wp search-replace 'https://OLD.example' 'https://NEW.example' --all-tables-with-prefix --skip-columns=guid
```

Handle separate home/site URLs and multisite domains as needed. Plain text
replacement inside SQL can corrupt serialized values. Server configuration,
DNS, certificates, cron, and remote object storage must be restored separately.

Finally check login, pages, media, plugins, and (when relevant) orders and network
sites. Compare table/row counts on an isolated destination while source writes
are paused, clear caches, and refresh permalinks. Keep the original backup until
recovery is verified. Store the package and generated SQL privately; both contain
credentials and personal site data.
