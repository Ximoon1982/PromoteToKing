# Promote to King v2.10.9.7

Corrective Fair Play scheduling release built from the canonical v2.10.9.6 baseline.

## Fair Play execution correction

- Fair Play current-match correctness now receives a guaranteed bounded priority slice before the long Club Green worker starts.
- The priority slice checks one newly-finished match first, then a small number of active matches. Historical traversal is excluded from this priority budget.
- The historical Fair Play scan now has a dedicated resumable CLI runner with its own MariaDB advisory lock, persisted cursor and minimum one-second interval between Chess.com match requests.
- The release installer adds a dedicated two-minute Fair Play backfill CRON entry while preserving every unrelated CRON line. The runner becomes effectively idle once the backfill reaches `complete`.
- The existing Fair Play database state from v2.10.9.6 is preserved; no restart or data reset is performed.

## Targeted repair

- Authenticated administrators can call `fair-play-maintenance.php?action=process-match` with a `match_id` to reconcile one match immediately through the same canonical Fair Play reconciliation engine.
- Targeted processing is idempotent and does not move the historical cursor. The historical runner will still verify the match normally when its cursor eventually reaches it.

## Preservation

- Raw Chess.com `result_code` values remain untouched.
- Only effective Team Points are corrected, with the v2.10.9.6 adjustment/provenance tables retained.
- Core/Analytics runtime data, credentials, generated analytics and unrelated scheduled tasks are preserved.
- No database schema reset or migration is required beyond the already-installed v2.10.9.6 Fair Play tables.
