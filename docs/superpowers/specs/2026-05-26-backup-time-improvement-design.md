# Backup Time Improvement Design

## Problem

Large WordPress sites in the 2-10 GB range can take too long to back up. The current backup runner is resumable, but it uses mostly fixed limits: database chunks default to 5000 rows, file scanning processes a fixed number of entries per step, and archive packaging has a fixed batch and time budget. When a capable VPS can safely do more work per request, the plugin leaves throughput on the table. When a site is slow for an unknown reason, the admin UI does not clearly identify whether the bottleneck is database export, file scan, ZIP packaging, or validation.

## Goals

1. Make backup duration easier to diagnose with per-phase timing and throughput metrics.
2. Improve backup speed on moderate VPS/dedicated hosting without removing resumability.
3. Reduce request overhead by allowing bounded backup steps to adapt upward when prior steps are fast.
4. Keep backup behavior safe on shared hosting by using conservative defaults and caps.

## Non-Goals

- Do not replace the ZIP format in this slice.
- Do not shell out to `zip`, `mysqldump`, `tar`, or other native binaries.
- Do not make one long-running backup request.
- Do not change restore archive format compatibility.
- Do not add new Composer runtime packages.

## Recommended Approach

Use incremental profiling plus adaptive batch sizing.

The backup system will keep the existing AJAX step architecture. Each step will record elapsed time and throughput. Later steps can use those metrics to increase work per request up to configured caps. This should improve speed on VPS-like hosts while preserving failure recovery after every step.

## Metrics

Backup jobs will add these payload keys where applicable:

- `database_last_step_seconds`
- `database_last_step_rows`
- `database_rows_per_second`
- `database_adaptive_chunk_size`
- `file_scan_last_step_seconds`
- `file_scan_last_step_entries`
- `file_scan_entries_per_second`
- `file_scan_adaptive_batch_size`
- `archive_last_step_seconds`
- `archive_last_step_entries`
- `archive_last_step_bytes`
- `archive_entries_per_second`
- `archive_mb_per_second`
- `archive_eta_seconds`
- `backup_bottleneck`

Existing keys remain valid. Older jobs without these metrics must continue to render and resume.

## Adaptive Limits

Database export starts with the current `database_chunk_size` of 5000 rows. If recent export steps finish well below the time budget, the runner can increase the effective chunk size for future chunks. Initial caps:

- Minimum: 5000 rows
- Moderate cap: 20000 rows
- Maximum cap: 50000 rows
- Step target: keep each database chunk under roughly 15 seconds

File scan starts with the current `BackupStepRunner` batch size. If scan steps finish quickly, the batch can grow. Initial caps:

- Minimum: 1000 entries
- Moderate cap: 5000 entries
- Step target: keep each scan step under roughly 10 seconds

Archive packaging keeps its existing resumable ZIP path. The time budget can grow on capable hosts:

- Default budget: 20 seconds
- Moderate cap: 45 seconds
- Step target: package as many entries as possible without exceeding cap

If a step approaches or exceeds the target, the next step should hold or reduce the adaptive limit rather than continuing to grow.

## Admin UI

The backup page should show concise throughput data in existing progress messages. Examples:

- `Exported chunk 3 for wp_posts. 18,420 rows/min.`
- `Scanned 12,000 files. 4,900 entries/min.`
- `Packaged 8,240 of 14,100 archive entries. 84 MB/min. ETA 6m.`

The Jobs table can also show a short bottleneck label when enough data exists:

- `Bottleneck: database`
- `Bottleneck: file scan`
- `Bottleneck: archive`

The UI must not expose private absolute paths beyond existing job error messages.

## Data Flow

1. Backup creation stores conservative starting limits in job payload.
2. `BackupStepRunner` measures database and file scan step duration.
3. `BackupArchiveStepPackager` measures archive entry count and byte throughput.
4. Each completed step stores metrics in job payload before the next AJAX request.
5. Adaptive limit helpers read recent metrics and compute the next effective chunk size, scan batch size, or archive time budget.
6. Admin UI reads payload metrics and displays current speed and ETA.

## Error Handling

If adaptive sizing creates a failed step, existing retry behavior should resume from the failed state. Failed jobs should preserve the last metrics and adaptive values for debugging. Retry should not immediately increase limits; it should reuse the last safe lower value or reset to defaults for the failed phase.

## Testing

Unit tests should cover:

1. Database step metrics are recorded after chunk export.
2. Database chunk size increases when prior steps are fast and remains capped.
3. Database chunk size does not increase after slow or failed steps.
4. File scan metrics are recorded and adaptive batch size increases within cap.
5. Archive packaging records bytes/sec and MB/min in payload.
6. Archive time budget grows within cap and does not grow after slow steps.
7. Admin progress messages include throughput and ETA when metrics exist.
8. Existing full PHPUnit suite stays green.

## Rollout

Implement in small slices:

1. Add metrics without changing limits.
2. Add admin display of metrics.
3. Add adaptive database chunk sizing.
4. Add adaptive file scan batch sizing.
5. Add adaptive archive time budget and byte throughput.

Each slice must be independently testable and commit-ready.
