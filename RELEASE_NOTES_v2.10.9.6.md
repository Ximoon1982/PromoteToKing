# Promote to King v2.10.9.6

Corrective data-integrity release based on v2.10.9.5.

## Fair Play Team-Point Reconciliation

- Adds a canonical fair-play reconciliation service for Chess.com team matches.
- While a match is in progress, `fair_play_removals` is consumed from authoritative match payloads.
- Completed games against a removed opponent receive 1 effective P2K Team Point even when the raw Chess.com game result remains a draw/loss/resignation.
- Raw `result_code` is preserved; effective scoring remains in `points_x2` and a durable adjustment row records raw/effective values and the removed opponent.
- The Club maintenance lane performs a first-finished safety check before relying on the historical backfill, covering removals added shortly before match completion.
- A resumable, cursor-based historical scan checks all finished P2K matches once and records checked/removal/correction/reconciliation metrics.
- Effective player-game points are reconciled against the authoritative P2K team score; unresolved mismatches remain observable.
- Adds authenticated `server/team-points/public/fair-play-maintenance.php` status/control actions: status, start/resume, pause, restart and bounded run-step.
- Fair-play schema objects are additive and self-installing/idempotent; no existing runtime data is reset.

## Members leaderboard evolution

- Fixes the universal `NEW` position-evolution symptom introduced by using database `first_seen_at` as if it were historical membership evidence.
- Comparison rankings now prefer actual point-event activity on/before the cutoff, then authoritative `joined_at`, and use `first_seen_at` only as a fallback.
- Existing historical Team Points immediately drive 1w/1m/3m/1y rank movement; no post-deployment snapshot warm-up is required.

## Preservation

- No credentials, OAuth/session state, runtime caches, Team Points facts or unrelated CRON configuration are reset.
- Historical raw game results remain unchanged by fair-play correction.
