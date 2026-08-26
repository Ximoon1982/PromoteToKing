# Promote to King v2.10.6

v2.10.6 continues directly from the canonical v2.10.5.5 source tree. It is an additive release: no source path is deleted, no database is reset, and no Green reseed is required.

## MCA Results Auto-Sync & Date Backfill

- Administration → MCA source data gains **Grab missing Results CSV** and resumable source-sync progress.
- The source scan reads the Promote to King MCA past-events index, identifies arena IDs, acquires missing result CSV files, and backfills authoritative event start dates from arena pages.
- All discovered arenas are checked for genuinely missing CSV files. Existing CSV files are not mass-refetched: only the recent 40-event overlap and files associated with `possible_renamed` identities are forcibly refreshed.
- Source requests are serialized and server-enforced at a minimum one-second spacing between request starts.
- Sync state and work queue are durable in Analytics schema 9, so browser and CRON execution can resume the same work.
- Existing manual MCA CSV upload and processing remain available as a fallback; previously stored source data is retained.
- A dedicated CRON runner and safe installer schedule the source sync twice daily. The sync itself also records a 12-hour next-scan gate.

## Dashboard new-match immediate refresh

- Clicking a match from the administrator **New matches · 24 h** list now performs an authoritative network-first Chess.com match fetch before opening the detail.
- Blue uses the normal Team Points match ingestion path; Green stores the authoritative Green match and immediately refreshes the compatibility projection.
- Other match-detail entry points remain database-first.

## Opponent player intelligence

- Compact board facts now retain the paired opponent username in addition to the paired ratings.
- Opponent Insights now exposes opponent-player metrics including unique observed players, recent players, appearances, matches, observed ratings, and Promote to King W/D/L/game/point history against those players.
- The feature uses already-ingested match/board facts; it does not require a profile request for every opponent player.

## GABCRF — Green Analytics Bootstrap Compatibility Reconciliation Fix

- GAB gains a resumable **compatibility reconciliation** lane between compatibility projection and analytics build.
- The lane repairs missing/stale compatibility match projections and board-count drift caused when authoritative Green facts change behind the normal ascending bootstrap cursor.
- At end-of-pass it rechecks from ID zero; if newer Green writes landed behind the cursor, it resets the reconciliation cursor and converges another pass.
- If final read parity detects projection drift, GAB reopens reconciliation + analytics + parity rather than terminally failing on a repairable projection mismatch.
- Compatibility batch projection refreshes members once per batch instead of once per match.

## Database / scheduling

- Core schema: **17** — adds `opponent_username` to compact board facts.
- Analytics schema: **9** — adds MCA source provenance, durable auto-sync state and queue.
- Migrations are additive. No reset, reseed, or historical MCA deletion is performed.
- New CRON: MCA source sync twice daily via `cron-mca-results-v2.10.6.sh` / `reset-install-mca-cron-v2.10.6.sh`.

## Preserved v2.10.5.5 behavior

Canonical compatibility board projection, canonical identity game projection, durable `quick_complete` handling, finite-cycle fairness, and the approved Zoned Density Heatmap rendering remain intact.
