=== Super Sheep Copy ===
Contributors: humbertqz
Tags: backup, restore, migration, database, files
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Full-site backup plugin for WordPress. Creates complete ZIP archives of your site (files + database) and restores them with safe URL rewriting.

== Description ==

Super Sheep Copy creates complete ZIP backups of your WordPress site — files and database together — and lets you restore them with a single click, including automatic URL rewriting for domain migrations.

= Features =

**Backups**

* Full-site ZIP — packages all of wp-content/, root config files (.htaccess, wp-config.php, robots.txt), and optionally the WordPress core directories (wp-admin/ and wp-includes/).
* Database dump — exports all tables matching the configured prefix. Falls back to a pure-PHP streamed dump when mysqldump is unavailable.
* manifest.json — every ZIP includes a manifest with site URL, PHP/WP versions, table prefix, charset, file count, DB size, and a SHA-256 checksum.
* Checksum file — a companion .sha256 file is created next to each ZIP for integrity verification.
* Pre-restore snapshot — before overwriting anything during a restore, the plugin automatically creates a safety backup of the current site state.
* Smart naming — backup filenames include a timestamp and a random 8-character hex token to prevent enumeration.

**Backup list**

* Shows filename, date, size, type badge (Manual / Automatic / Scheduled), and short checksum.
* Download — served through an authenticated admin endpoint. No direct file URL is exposed.
* Restore — one-click restore from any listed backup.
* Delete — with confirmation; supports bulk deletion.
* View manifest — inline modal showing the full manifest.json content.

**Restoration**

* Validates the ZIP (magic bytes + manifest.json check + optional checksum comparison) before doing anything.
* Extracts files to a temporary directory first, then moves them atomically.
* Imports the SQL dump statement-by-statement, handling multi-line statements and DELIMITER changes.
* Automatically rewrites the old siteurl/home and all serialized option values when restoring to a different domain (serialize-safe URL rewriting — updates string lengths so WordPress-serialized data is never broken).
* Flushes rewrite rules and object cache after import.
* Force-logs out all active sessions after a restore.

**Upload methods**

Backup ZIPs can reach the server in two ways:

1. Browser upload — use the Restore page form.
2. FTP / file manager — upload the ZIP directly to wp-content/uploads/ssc-backups/ and it will appear in the backup list automatically.

**Settings**

* Include WordPress core — toggle whether wp-admin/ and wp-includes/ are packaged.
* Exclude large logs — skip .log and .tmp files to reduce archive size.
* Max upload size — configurable MB limit for browser uploads.

**Audit log**

* Every backup and restore operation is recorded in the database.
* Log entries grouped by operation (job ID) with status, duration, file name, user, and error/warning counts.

== Installation ==

1. Upload the `super-sheep-copy` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. On activation the plugin automatically:
   * Creates `wp-content/uploads/ssc-backups/` with .htaccess, web.config, and index.php protection files.
   * Creates the audit log database table.
   * Adds the `manage_ssc_backups` capability to the Administrator role.

== Frequently Asked Questions ==

= Where are backups stored? =

All backups are stored at `wp-content/uploads/ssc-backups/`. The directory is protected against direct HTTP access by .htaccess (Apache/LiteSpeed), web.config (IIS), and a silent index.php fallback.

= Can I restore to a different domain? =

Yes. The plugin automatically detects a URL change and rewrites all occurrences of the old URL — including inside serialized WordPress option values — so the site works correctly on the new domain.

= What happens if mysqldump is not available? =

The plugin falls back to a pure-PHP streamed database export that works on any host, including shared hosting without shell access.

= Is it safe to restore? =

Before overwriting anything, the plugin creates an automatic safety backup of the current site state. You can restore from it if anything goes wrong.

= Does it support multisite? =

Not at this time. Single-site installations only.

= What PHP extensions are required? =

The `ZipArchive` extension is required. It is available by default on most modern hosting environments.

== Screenshots ==

1. Main backup list with status badges, download, restore, and delete actions.

== Changelog ==

= 0.0.1 =
* Initial release.

== Upgrade Notice ==

= 0.0.1 =
Initial release.
