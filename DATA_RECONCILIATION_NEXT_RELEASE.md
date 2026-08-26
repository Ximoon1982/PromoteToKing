# P2K Data Reconciliation — next-release preparation

## Purpose
Admin-only staged CSV/TXT reconciliation for historical/current P2K data. The service validates uploads locally and against production before any mutation. CSV input is evidence for discovery and acceleration; it is not a second source of truth for conflicting canonical match fields.

## UI
Administration → Administration tools → Data Reconciliation.
Deep-link: `?page=administration&adminTool=reconciliation` (through the dashboard navigation state).

## Accepted/recognized families
Recognized by headers/content rather than filename:
- current roster: `PlayerName,Joined`
- parsed member/board rows: `Player,Match,Board,Start1,...`
- parsed match data: `MatchUrl,MatchName,StartTime,...`
- DailyData: `Date,Games started,...,Cumulated points`
- finished match export
- active/registration match export
- findings TXT (one Chess.com match API URL per line)

## Safety contract
- protected Admin endpoint + existing same-origin/CSRF checks
- private runtime staging; server-generated/sanitized names; SHA-256 for every input
- CSV/TXT only; 32 MiB per-file limit
- check/report phase makes no canonical writes
- score/result/Club Points conflicts are NEVER copied from CSV; they queue authoritative `sync_match`
- absence from an almost-current member CSV NEVER marks a member former
- positive member presence may be seeded, followed by authoritative roster refresh
- non-conflicting player↔board evidence may be seeded; incomplete board times remain queued for authoritative board completion
- bounded/idempotent apply steps with exact `APPLY <batch-id>` confirmation
- existing durable `sync_match`, `sync_board`, `sync_members` queues are reused

## DailyData checkpoint rule
DailyData is treated as a dated prefix checksum, not necessarily the terminal production total.
The service sorts non-void finished matches chronologically and finds the prefix where BOTH:
- cumulative non-void finished count equals DailyData
- cumulative Club Points equals DailyData

That match's authoritative end timestamp becomes the checkpoint boundary. Matches finishing later, including later on the same UTC day, are allowed to exist in production without making the checkpoint fail.

## Current 2026-08-10 batch
Local analysis of the supplied batch found:
- 4,474 current-member rows
- 16,048 finished match rows
- 1,094 active/registration rows (1,007 in progress, 87 registration)
- 17,143 findings, one beyond active+finished (`1982490`)
- 105,600 parsed member/match rows
- 1,510 finished 0–0/void rows
- DailyData checkpoint: 14,538 non-void finished / 373,025 Club Points / 15,545 started / 1,007 in progress
- DailyData reconciles to the match exports on all complete days, and the chronological count+points prefix resolves exactly.

Targeted revalidation candidates include `1796256`, `1844231`, `1708709`, `1821730`, `1844727`; `1794360` has a formatted-date mismatch and must use Unix time; `1982490` should be discovered by targeted sync.

See `P2K_CSV_RECONCILIATION_AUDIT_2026-08-10.md` and the machine-readable issue/summary files packaged with this preparation.
