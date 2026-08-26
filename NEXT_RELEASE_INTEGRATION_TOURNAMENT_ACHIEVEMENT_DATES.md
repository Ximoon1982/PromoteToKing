# Next release integration — tournament achievement dates

Prepared on top of v2.8.3. No release/version number is assigned by this patch.

## Behaviour

- Tournament achievements use only the tournament archive `finishAt` (Chess.com `finish_time`) as an exact earned date.
- `periodSort`, tournament period, start date and archive update time are never converted into an earned date.
- Finished tournaments without `finishAt` retain the achievement and its tournament name/link, but expose `earned_at = null` with `earned_at_precision = tournament-pending`.
- UI surfaces render `Date pending tournament refresh` instead of a synthetic date.
- Finished tournaments missing `finishAt` are already part of the tournament service backfill queue.
- When an authoritative finish time becomes available, the next achievement refresh upgrades the pending record to `tournament-finish`, updates the exact date and refreshes the triggering tournament metadata.
- The achievement source watermark contains a logic-version token plus a watermark-aware refresh gate so deployment forces an immediate achievement rebuild even when the six-hour filesystem throttle is still fresh. This also clears legacy `tournament-period` synthetic dates from v2.8.3.

## Database

No schema change is needed. Existing nullable `earned_at` and `earned_at_precision` fields support this behavior.

## Files changed

- `server/team-points/src/AnalyticsBuilder.php`
- `server/team-points/src/Repository.php`
- `assets/js/pages/dashboard-v2.js`
- `TournamentAchievementBadgesDemo.html`
- `tests/test_next_release_tournament_achievement_dates.py`


## Revision 2 — propagation fix

- Fixed the refresh coordinator: a fresh filesystem marker no longer suppresses a rebuild when the persisted achievement watermark differs from the current logic/source watermark.
- Bumped the logic token to `tournament-achievement-date-v3` so this revision is guaranteed to run once after deployment.
- Before rebuilding, legacy `source_type=tournament` / `earned_at_precision=tournament-period` rows are explicitly cleared to `earned_at=NULL` and `tournament-pending`, even if an old tournament is not currently present in the archive.
- Dashboard tournament medal event dates no longer fall back to tournament start dates.
