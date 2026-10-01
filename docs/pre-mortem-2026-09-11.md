# Super Sheep Copy — Launch Pre-Mortem

## Framing and assumptions

**Scenario:** Super Sheep Copy v0.1 launches. At **T+14 days**, users report
failed backups, failed restores, and one or more unsafe restore outcomes; the
launch is paused.

This is a draft prepared from the repository rather than a facilitated group
session. It assumes a public v0.1 launch of the backup, package validation, and
standalone restore installer. The intended participants and psychological
safety have not yet been confirmed, so the items marked **Elephant** must be
tested in a candid 4–8 person review before a release decision.

## Risk register

| # | Classification | Risk / failure story | Evidence | Urgency | Mitigation and exit criterion | Suggested owner / decision date |
|---|---|---|---|---|---|---|
| 1 | **Tiger** | A customer with a large file inventory exhausts PHP memory during package creation, leaving no usable backup. | The streaming-manifest design records that the current runner loads all entries and checksums in memory; memory grows with total file count rather than batch size. | **Launch-blocking** | Complete streaming packaging; run a representative large-inventory backup under a 256 MB memory limit and prove bounded memory across resume steps. | Backup engineer / before release candidate |
| 2 | **Tiger** | A restore fails partway through on a normal shared-host database because an SQL statement exceeds `max_allowed_packet`, or legacy zero-date SQL is rejected. | The database-compatibility design documents observed MySQL 1067 and 2006 failures. | **Launch-blocking** | Verify byte-bounded exports and connection-local zero-date handling against supported MySQL/MariaDB versions; demonstrate safe recovery from an interrupted import. | Restore engineer / before release candidate |
| 3 | **Tiger** | A restore damages the destination: files or tables are changed despite a preflight, import, or swap failure. | The installer performs a destructive restore and includes rollback, staging, and table-swap machinery—complexity that makes failure-path verification critical. | **Launch-blocking** | Execute fault-injection tests at every destructive boundary (file copy, SQL import, table swap, URL replacement) and verify the original destination is intact or that rollback is proven. Publish a supported recovery procedure. | Restore engineer + QA / before release candidate |
| 4 | **Tiger** | Backup archives containing password hashes, API keys, and private content are publicly reachable from uploads storage or retained longer than intended. | README explicitly says archives contain sensitive data and are stored under the WordPress uploads directory. | **Launch-blocking** | Test direct HTTP access on Apache, nginx, and common managed-host configurations; ship clear storage-hardening behavior or block release for hosts where archives cannot be protected. Validate retention and failed-job cleanup. | Security owner / before release candidate |
| 5 | **Tiger** | An archive validates but restores incorrectly because package writers/readers differ among ZIP, TAR/GZ, and directory fallback environments. | The plugin selects the best available writer and supports three formats, increasing compatibility paths. | **Launch-blocking** | Build a format-by-environment matrix and perform end-to-end backup → transfer → validation → restore for every advertised writer. Remove unsupported combinations from release claims. | QA owner / before release candidate |
| 6 | **Tiger** | A resumable AJAX/background job is duplicated, resumed against changed site state, or becomes stuck, producing incomplete or misleading backup output. | The product uses incremental steps, retries/continuation, job cleanup, and a cross-site/upload-directory guard; the repository includes a backup-step execution-lock design. | **Fast-follow** (promote to launch-blocking if concurrency tests fail) | Test refreshes, parallel admin tabs, timeout/retry, and upload-directory change scenarios. Make completion require manifest/checksum validation, not a progress flag alone. | Backup engineer / release candidate test window |
| 7 | **Tiger** | Users cannot complete a legitimate restore because their package is too large for upload and FTP/SFTP staging, paths, or permissions are unclear or fail silently. | README directs large-package users to an uploads restore folder and supports staged directory packages. | **Fast-follow** | Run a documented staging flow on constrained hosting; ensure actionable errors show expected path, permissions, and detected package state. | Product + QA / first maintenance release |
| 8 | **Paper Tiger** | "Every host will fail because PHP 7.4 is old." | PHP 7.4 support is a stated requirement, but no evidence here shows a specific supported host incompatibility. | — | Convert this into a compatibility matrix and supported-platform policy; do not delay launch solely on this fear. | Engineering lead / before release candidate |
| 9 | **Paper Tiger** | "Customers will never trust a plugin named Super Sheep Copy." | No user research, brand feedback, or conversion evidence is present. | — | Test the product description and onboarding with target users; keep it separate from safety/reliability launch gates. | Product owner / Track |
| 10 | **Elephant** | The team may know the installer is not ready for production restores but be treating the feature list and existing tests as sufficient evidence. | Version is 0.1.0; the product handles irreversible customer data changes; no documented public beta, support plan, or end-to-end host qualification is evident in the supplied materials. | **Launch-blocking until explicitly resolved** | Hold a blameless go/no-go review. Ask: “Would each of us restore a paying customer’s production site with this today? If not, what concrete evidence is missing?” Decide whether launch is beta-only. | Release owner / go-no-go meeting |
| 11 | **Elephant** | There may be pressure to promise recovery even though rollback has practical limits for external services, concurrent writes, object storage, or host-specific file behavior. | Rollback helpers exist, but scope and guarantees are not stated in the README. | **Launch-blocking until explicitly resolved** | State exact restore/rollback guarantees, exclusions, and required maintenance window. Obtain written agreement from engineering, support, and product. | Engineering + Support leads / go-no-go meeting |
| 12 | **Elephant** | Support ownership for a failed production restore may be undefined. | No support escalation, diagnostics intake, or incident response process is documented in the visible product material. | **Launch-blocking until explicitly resolved** | Name an on-call/escalation owner, define diagnostic bundle handling without credentials, and rehearse one restore incident before launch. | Support/release owner / go-no-go meeting |

## Launch gate

Do not launch broadly until Tigers 1–5 are closed with recorded end-to-end
evidence and all three Elephants have explicit decisions. Tiger 6 becomes
launch-blocking if concurrent/retry testing can create an invalid archive or
incorrectly report success.

## 60-minute team session to validate this draft

1. **Set the scene (5 min):** Read the T+14 failure scenario aloud; confirm the
   actual launch date, participants, and that dissent has no penalty.
2. **Silent generation (10 min):** Each participant writes failures individually;
   no discussion or solutions yet.
3. **Cluster (10 min):** Group duplicates by backup, restore safety, host
   compatibility, security, and operations.
4. **Classify (15 min):** Mark each item Tiger (evidence), Paper Tiger (fear
   without evidence), or Elephant (important but avoided).
5. **Mitigate (15 min):** For every Tiger, set a single accountable owner,
   decision date, and observable exit criterion.
6. **Address Elephants (5 min):** Capture the decision, not a vague promise; a
   launch-blocking Elephant remains open until a named decision maker closes it.
