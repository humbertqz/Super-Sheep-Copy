# WCPDF Temporary Attachment Exclusion Design

## Goal

Prevent backups from failing when PDF Invoices & Packing Slips for WooCommerce
removes an email attachment after file scanning but before archive packaging.

## Root Cause

The incremental backup runs file scanning and archive packaging in separate
requests. Scanning records each file's absolute path in a persistent manifest.
Packaging later reads that manifest and calculates a SHA-256 checksum from the
recorded path.

PDF Invoices & Packing Slips for WooCommerce writes generated email attachments
under a randomized path shaped like:

```text
wp-content/uploads/wpo_wcpdf_<random>/attachments/<document>.pdf
```

That plugin treats `attachments` as temporary storage. If it deletes a generated
PDF after Super Sheep Copy scans it, `hash_file()` cannot open the stale path
and the backup fails with `Unable to calculate checksum`.

Persistent PDFs created by the plugin's "Keep PDF on server" feature live in a
separate `archive` directory and are not part of this failure.

## Design

Teach `FileScanner::isExcluded()` to recognize the normalized site-relative
path pattern:

```text
wp-content/uploads/wpo_wcpdf_<non-empty-random-segment>/attachments
```

Exclude both that directory and every descendant. The match must be anchored to
complete path segments so similarly named directories elsewhere are not
excluded.

The existing `scan()` and `scanStep()` methods already route paths through
`isExcluded()`. Keeping the rule there applies one consistent exclusion to
legacy full scans and resumable scans without changing archive packaging.

Do not exclude the whole randomized `wpo_wcpdf_<random>` directory. In
particular, continue including:

- `wpo_wcpdf_<random>/archive`
- `wpo_wcpdf_<random>/fonts`
- other non-attachment files below the plugin directory

## Error Handling

Checksum failures for all other files remain fatal. This change does not
silently skip arbitrary files that disappear or become unreadable, because
doing so could hide an incomplete backup.

The exclusion is handled during scanning, so excluded temporary attachments are
never written to the scanned-files manifest, archive entries manifest,
checksums, or package metadata.

## Testing

Add focused `FileScannerTest` coverage proving that:

- `scan()` excludes a PDF below a randomized WCPDF `attachments` directory.
- `scanStep()` applies the same exclusion across bounded requests.
- A PDF below the sibling WCPDF `archive` directory remains included.
- A similarly named `attachments` directory outside the randomized WCPDF path
  remains included.

Run the focused scanner tests, the complete PHPUnit suite, and PHP syntax
validation.

## Scope

This change is limited to the file scanner's built-in exclusion rules. It does
not change checksum generation, archive writers, persistent invoice handling,
general missing-file behavior, backup settings, or restore behavior.
