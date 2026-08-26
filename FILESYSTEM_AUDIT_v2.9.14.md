# Promote to King v2.9.14 — Filesystem / inode hardening audit

# v2.9.14 Filesystem-Count Hardening Development Note

Status: **development branch only**. The tree intentionally remains `VERSION=2.9.13` and is not a numbered release.

## Goal

Keep steady-state P2K filesystem-object growth bounded by configuration instead of by elapsed time, API request count, observation count, or repeated updater runs. This work is motivated by hosting environments with contractual file/inode limits around 200,000 objects.

## Implemented in this branch

### Compact future updater backups

- `tools/release/p2k-state-backup.sh` creates one compressed state archive plus one SHA-256 sidecar rather than recursively copying mutable trees into `_backup`.
- Default retention is three compact backup archives.
- Reconstructible/ephemeral runtime trees are excluded, including API caches, browser-observation limiter state, ACAMR ledgers, telemetry, fresh-init transient state, CRON shell state, and tournament cache/locks.
- Durable match history, protected configuration, Live Ranks sources, tournament durable state, reconciliation/intelligence state, and logs remain restorable.
- `tools/release/p2k-state-restore.sh` validates and restores compact archives.
- `tools/release/p2k-consolidate-legacy-backups.sh` is dry-run by default and can convert legacy directory-style `_backup` entries into verified archives before removing the source directory.

These helpers are **not wired into any numbered updater yet**.

### Browser-observation limiter

- `server/shared/BoundedRateLimiter.php` replaces one-file-per-IP-per-minute limiter buckets with one locked state file.
- Subject count and idle lifetime are bounded.
- Concurrent-process tests verify the 300/window limit remains atomic.
- Housekeeping removes legacy `rate-*.txt` limiter files in bounded batches.

### ACAMR and Client continuous refresh

- `AcamrClaimStore` is the single claim/lease implementation for both ACAMR and Task Control's `Client continuous refresh` planner.
- New claims use at most 256 fixed claim-ledger shards.
- New member leases use at most 256 fixed member-ledger shards.
- New traffic therefore cannot create one file per claim or one file per member.
- Fixed shard files are retained rather than unlink/recreated when empty, avoiding concurrent unlink/recreate races.
- Legacy `claim-*.json` and `member-*.json` files remain readable long enough to expire and are cleaned opportunistically and by housekeeping.
- Member-lease acquisition is process-safe; contention tests confirm one winner for the same member.

### Chess.com filesystem cache

- Independent entry ceiling in addition to byte ceiling; default 30,000 entries.
- New writes default to one shard level (up to 256 first-level directories) instead of the old two-level directory fan-out.
- Old two-level entries remain readable and are removed when rewritten through the new path.
- Housekeeping may remove up to 50,000 cache files per pass so an already oversized production cache converges quickly toward its ceiling.

### Public response cache

- Independent entry ceiling (default 2,000) plus byte ceiling (default 128 MiB).
- Old lock files are cleaned and ordinary lock files are removed after use.

### Match-tracking history

- Tiered snapshot retention preserves dense recent history and progressively thins old history.
- First snapshot, latest snapshot, and detected match-status transitions are preserved.
- Default hard cap is 1,200 snapshots per match.

### Other runtime histories

Housekeeping now bounds:

- runtime telemetry: default 30 days / 35 files;
- reconciliation batches: default 365 days / 250 batches;
- Club Intelligence daily snapshots: default 730 days / 730 files;
- top-level scheduled-task JSONL logs: default 30 days / 35 files;
- traffic aggregate history: default 400 days / 400 files (the UI can report at most 366 days);
- fresh-init nonce receipts: default 2 days / 500 files;
- fresh-init coverage runs: default 90 days / 20 runs;
- deliberate tournament archive backups: default 10 years / 100 files.

### Durable Live Ranks uploads

- Existing filenames remain replaceable as before.
- New distinct source filenames are refused after the configurable ceiling (default 5,000) instead of silently deleting durable source data.

### File-count observability

`StorageMetricsService` and Team Points Administration's **Storage & capacity** section now expose:

- hosting files;
- directories;
- total filesystem objects;
- configured hosting file contract (default 200,000);
- file-contract percentage/status;
- Chess.com cache entry count and entry ceiling.

The whole-site object scan is cached for 15 minutes to avoid turning UI polling into filesystem pressure.

### Package hygiene

`tests/validate_package.py` now rejects `.pytest_cache`, `__pycache__`, and `.pyc` artifacts so development test caches cannot be shipped accidentally in a future standalone package.

## Development validation

At branch freeze:

- Python regression suite: **382/382 PASS**.
- v2.9.14 filesystem-specific suite includes limiter concurrency, fixed ACAMR shard bounds, cache migration/entry eviction, tiered match retention, runtime-history retention, compact backup/restore, legacy-backup consolidation, Client continuous refresh integration, Live Ranks cap, storage metrics, and package-hygiene checks.
- Chromium startup/admin gate: PASS, 0 page errors.
- Chromium transport/cache stress gate: PASS; 700 Match Creation details, 150 finished-match cache hits, 50 network misses, six active gateway posts, 0 page errors.
- Changed PHP files lint clean.
- Team Points Admin JavaScript syntax check PASS.
- Future backup/restore/consolidation shell scripts pass `bash -n`.

## Deliberately not done yet

This branch is not a release. Before promotion to a future numbered version:

1. Decide final configuration defaults after inspecting production file counts.
2. Wire the compact state-backup helper into the future incremental updater and remove recursive mutable-tree backup behavior there.
3. Decide whether legacy `_backup` consolidation should remain a separate administrator action or become an explicitly opt-in updater step. It must never silently destroy legacy backups before verified archive conversion.
4. Add release/version/cache-bust metadata only during promotion.
5. Regenerate `site-manifest.json` from the clean release tree.
6. Run the complete numbered-release validation matrix, exact baseline delta build, protected-config preservation replay, CRON preservation replay, and final archive checksum freeze.

No v2.9.14 files, updater, ZIP, migration notes, or release notes are generated by this development work.
