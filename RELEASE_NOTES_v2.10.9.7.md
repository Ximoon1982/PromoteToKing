# Promote to King v2.10.9.7

Corrective Fair Play scheduling release built from the canonical v2.10.9.6 baseline.

## Fair Play execution correction

- Fair Play current-match correctness now receives a guaranteed bounded priority slice before the long Club Green worker starts.
- The priority slice checks one newly-finished match first, then a small number of active matches. Historical traversal is excluded from this priority budget.
- The historical Fair Play scan now has a dedicated resumable CLI runner with its own MariaDB advisory lock, persisted cursor and minimum one-second interval between Chess.com match requests.
- The release installer adds a dedicated two-minute Fair Play backfill CRON entry while preserving every unrelated CRON line. The runner becomes effectively idle once the backfill reaches `complete`.
- IONOS Web Hosting CLI execution uses a versioned PHP CLI binary (`/usr/bin/php8.5-cli` on older contracts or `/usr/bin/php8.5` on newer contracts), never the legacy bare `php` command; the worker rejects non-CLI SAPIs defensively.
- The existing Fair Play database state from v2.10.9.6 is preserved; no restart or data reset is performed.

## Targeted repair

- Authenticated administrators can call `fair-play-maintenance.php?action=process-match` with a `match_id` to reconcile one match immediately through the same canonical Fair Play reconciliation engine.
- Targeted processing is idempotent and does not move the historical cursor. The historical runner will still verify the match normally when its cursor eventually reaches it.

## Preservation

- Raw Chess.com `result_code` values remain untouched.
- Only effective Team Points are corrected, with the v2.10.9.6 adjustment/provenance tables retained.
- Core/Analytics runtime data, credentials, generated analytics and unrelated scheduled tasks are preserved.
- No database schema reset or migration is required beyond the already-installed v2.10.9.6 Fair Play tables.

## R3 Analytics convergence correction

- Fair Play corrections increment the authoritative Core generation immediately, but all-time Members/Hall/Profile totals are served from the materialized `p2k_an_player_totals` Analytics projection.
- The previous Club maintenance allocation was internally impossible: Analytics received at most 7 seconds while `AnalyticsBuilder::refreshIfDue()` requires at least 8 seconds remaining before a rebuild may start.
- The Club fallback slice is corrected to a 10-second maximum with a 9-second minimum start budget.
- Generation convergence now also has a dedicated CLI runner and one-minute CRON. It uses a separate MariaDB advisory lock, the existing generation/source-watermark logic, a 35-second bounded request window and verified versioned IONOS PHP CLI.
- The task is cheap when Core has not changed; when Fair Play or another authoritative write increments Core generation, the next eligible invocation rebuilds Analytics after the existing short generation-coalescing window.
- No Core/Analytics data is reset and the existing Fair Play backfill CRON is left untouched.
