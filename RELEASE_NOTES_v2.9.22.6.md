# Promote to King v2.9.22.6

Focused Team Points reconciliation and Task Control work-detail release.

## Task Control work-detail timeout correction

- Club/Player work-detail requests are now lane-local.
- Opening a Team Points card no longer calls the cross-lane `Repository::summary()`, synchronization coverage, full durable-queue counts, or historical task breakdown.
- Maintained job totals are reused; only active pending/running/retry/failed rows are counted through the indexed queue path.
- Historical task breakdown is deferred rather than blocking the selected card.

## Incremental Fresh Points reconciliation

Fresh acquisition remains isolated in reconstruction staging. The old normal workflow of approving an entire run is replaced in Task Control by incremental reconciliation.

### Club Points

- Each successfully fetched match is compared with Core as soon as it is staged.
- Actionable differences are limited to:
  - missing match;
  - different authoritative match result/status/endpoint board count/team scores/derived Club Points.
- Each row has **Apply local**, updating only that match under the Club worker lane lock.
- Applied rows are retained in an audit history.
- Acquisition failures remain visible and retryable but do not block unrelated corrections.
- Finalization is available when actionable differences reach zero; verified rows supersede obsolete related queue work while acquisition-error jobs remain queued.

Club scoring continues to use only the Chess.com match endpoint: root `boards`, P2K/opponent team scores, 0-0 finished matches excluded, win = 5 x boards, draw = 2 x boards, loss = 0.

### Player Points

- Current members are reconstructed independently.
- A member becomes actionable as soon as that member's local board/game set is complete, without waiting for every other member.
- Each player row compares fresh boards/games/points with Core and has **Apply local**.
- Applying a player replaces only that current member's Player Points history and supersedes only that member's obsolete player/archive/board queue work.
- Ongoing games are valid pending history and are not treated as acquisition issues.
- Archive fallback occurs only if the player's team-match endpoint fails.
- Archive scanning never starts before 2024-01 and is additionally bounded by the member's Chess.com account creation month.
- Archive candidate team matches are verified against the match endpoint as actual P2K matches containing that member before staging; unrelated team matches are ignored.
- Finalization uses the closing current roster and leaves acquisition-error work queued.

## Schema

- Core schema: **16** (was 15).
- Analytics schema: **7** unchanged.
- Core 16 adds only `p2k_tp_reconstruction_actions` for durable reconciliation audit history.
- No existing Team Points facts are reset or reseeded.

## Unchanged

OAuth/ACSR/ACDM pacing, Club endpoint scoring rules, achievement logic/artwork, tournaments, match monitoring, CRON schedules, and protected production configuration are unchanged.
