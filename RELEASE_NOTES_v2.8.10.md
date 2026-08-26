# Promote to King Standalone v2.8.10

v2.8.10 is a correctness, recovery and completeness release built on the production-confirmed working v2.8.9 startup/authentication baseline. It deliberately does **not** redesign Dashboard initialization. The release repairs data semantics, restores safe v2.8.7 correctness improvements that were lost during the Dashboard rollback, fixes Tournament/Match Tracking/CRON regressions, and hardens packaging so upgrades no longer overwrite mutable production state.

## Canonical match outcomes

- Core schema moves additively from **6 to 7**; Analytics remains **5**.
- Finished non-void match result is derived from authoritative stored `p2k_score` vs `opponent_score`, not from a previously stored result label.
- Finished authoritative 0–0 matches remain `is_void=1`, retained for traceability and excluded from all analytics.
- Existing finished rows are repaired in-place by the Core 7 migration; competition points are recalculated from score-derived outcomes.
- `p2k_tp_match_summaries` now derives result and competition points from scores, so Opponent/Club/Match analytics consume an actually independent canonical outcome expression.
- Analytics logic watermark changes to force a normal rebuild after the Core repair.
- Semantic tests seed win/draw/loss/void cases and execute the PHP outcome function rather than only inspecting SQL text.

## Dashboard source-of-truth protection

The proven v2.8.9/v2.8.6 startup and authentication order is unchanged. Only the automatic post-paint data merge is corrected:

- Core/database current-member count remains authoritative and is not overwritten by Chess.com club-members/profile responses.
- The normal Dashboard no longer makes redundant club-members and club-profile calls.
- Chess.com `/club/.../matches` still refreshes current registration/in-progress counts automatically.
- Chess.com's bounded finished list can no longer overwrite all-history Finished Match/Board KPIs from the canonical database.
- The bounded Chess.com finished list remains available only as a detail fallback if the local archive/detail request fails.

## Tournament and Match Tracking recovery

- Unified Task Control now sends every required `X-Club-Tools-Request` action header, fixing the reported `Missing X-Club-Tools-Request header` error for tournament refresh and match-tracking mutations.
- Match Tracking Task Control correctly maps the registry task key `match-tracking` to its management panel; the old `match-monitoring` query remains a compatibility alias.
- Tournament archive reads can recover from a non-empty `.bak`, timestamped backup, or materialized BrowseIndex cache when an accidental empty packaged archive replaced the primary file. BrowseIndex signatures include recovery-candidate metadata so recovered data invalidates stale public caches correctly.
- Match Tracking can recover a newer/non-empty registry backup or existing snapshot history and persists the recovered registry correctly.
- Intentional tournament reinitialization remains respected and is not silently undone by recovery.

## Non-destructive release packaging

The standalone release no longer ships mutable production files that previously could overwrite live state during an in-place extraction:

- `data/server-config.json` is **not** shipped; `data/server-config.example.json` is provided instead.
- `data/tournaments/archive.json` is **not** shipped.
- `data/match-tracking/index.json` is **not** shipped.

Protected production configuration/runtime data must be preserved on upgrade. Missing runtime files are created by the application when genuinely needed.

## CRON repair and diagnostics

- New argument-less `reset-install-cron-v2.8.10.sh` performs an atomic complete crontab replacement without deleting the old crontab first.
- If the shared token file was overwritten/lost by an older release, the installer can recover the shared token from the existing legacy Tournament/Match Tracking cron URLs and restore protected `data/server-config.json`.
- New `cron-dispatch-v2.8.10.sh` reads the **current** protected tokens on every invocation; secrets are no longer embedded in crontab lines. If no legacy shared token can be recovered, the zero-argument installer generates a new protected 256-bit token automatically.
- Dispatcher HTTP authentication uses `X-P2K-Cron-Token`. Existing `?token=` CRON URLs remain accepted for backward compatibility.
- A protected shell heartbeat is written **before** the HTTP call and updated with HTTP/curl result. Task Control can distinguish “cron daemon never invoked the job” from “job was invoked but the endpoint returned/auth/network failed”.
- Tournament expected cadence is consistently 10 minutes in registry/schema/control metadata, and unfinished-tournament status maintenance now runs on every 10-minute invocation while discovery/podium work remains staged extras.
- Standard schedules remain: Club 5m, Tournament 10m, Player 30m, Match Tracking hourly.

## Club Intelligence completeness/correctness

- ACAMR tab is correctly labelled **ACAMR effectiveness** and is exposed in the Administration tool collection.
- ACAMR effectiveness adds active/distinct session and distinct claimed-member telemetry; no fictitious “time saved” number is produced because there is no reliable CRON-only counterfactual.
- Freshness uses the operational 5-minute Club and 30-minute roster targets; Tournament warnings use its 10-minute target.
- Finished-board verification is separated from legitimate registered/in-progress backlog.
- Freshness now reports oldest rating, oldest unresolved finished board, queue age, Core→Analytics lag, Tournament maintenance age and actual Tournament status-check age; structured `lastStatusRefresh` metadata is interpreted correctly.
- Member activity distinguishes overall, Standard/Daily and Chess960 activity.
- Contribution adds points/board, league points, Standard/Chess960 points, transparent rating-strength adjustment and six-month consistency.
- Opponent profiles add average boards, average opponent rating, league/friendly split, 90-day frequency and average score margin in addition to corrected W/D/L.
- Daily snapshots include W/D/L, Standard/Chess960 activity and finished-board verification; previous/~7d/~30d comparisons are displayed.
- Personalized authenticated home adds 7-day W/D/L, previous-week points comparison and current registered/in-progress match counts.
- Endpoint telemetry adds response-size and 304/revalidation metrics. Direct per-endpoint PDO query time remains explicitly uninstrumented rather than estimated.

## Redundancy and admin-path cleanup

- Personalized-home/profile initialization coalesces concurrent requests and performs a keyed single-member intelligence lookup instead of constructing/scanning the full current-member table.
- Bulk member intelligence rows are cached within a request for Team Depth/Activity reuse.
- The outer site router now recognizes configured/local administrators before any remote Chess.com verification, matching the Dashboard/admin-page contract.
- Browser validation now executes Tournament Management refresh/display and Match Tracking display/mutations in addition to Dashboard/admin/ACAMR startup.

## Retained v2.8.8/v2.8.9 functionality

All production-confirmed v2.8.9 startup/admin corrections remain, including DOM-owned integrated-frame discovery, immediate configured/local administrator recognition, iframe/lazy retry resilience and ACAMR startup isolation. v2.8.8 Hall/Profile/mobile/nested-modal fixes, opponent logos, Team Insights six-month projection, Recruitment confidence, challenges and the broader Club Intelligence tool set remain integrated.

## Honest historical-data limitations

Three requested concepts cannot be reconstructed retroactively from data that was never stored:

- Historical MCA/arena achievement crossings do not contain the exact source arena URL in the imported aggregate files; future source formats would need to preserve it.
- Historical recruitment conversion/join probability cannot be calculated because recruitment recommendations/outcomes were not historically stored as labelled observations. Current confidence therefore uses observable rating freshness, lineup coverage, availability and projected board coverage only.
- Daily Intelligence snapshots support aggregate time comparisons from the date snapshots began. Detailed historical member lineups/ranks from before capture began are not inferred.

## Database and operations

- Core schema: **7** (additive from 6).
- Analytics schema: **5**.
- No database reset or reseed.
- CRON cadence unchanged.
- Run the supplied v2.8.10 CRON installer after deployment, especially if v2.8.9 may have overwritten `data/server-config.json`.
