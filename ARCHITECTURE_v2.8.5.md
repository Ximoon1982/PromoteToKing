# Promote to King v2.8.5 architecture

## Storage and authority

v2.8.5 keeps the compact split architecture:

- **Core schema 5** — canonical club/member/match/board/game/point facts, durable queues, worker state, safe player profile/avatar snapshots, and the Core generation/freshness state.
- **Analytics schema 5** — rebuildable materialized player, match, opponent, Hall/achievement and Insights projections.
- **Filesystem cache/runtime** — shared Chess.com HTTP cache, bounded public response cache, locks and operational logs.

Browser observations are never authoritative. They may identify relevant Chess.com resources and prioritize work, but canonical roster membership, ratings, match facts and point events are written only from server-side Chess.com verification through the shared gateway. This avoids trusting JSON that a browser user could fabricate.

## Background workers

Two independent Team Points lanes share the same gateway and databases:

- **Club lane — every 5 minutes.** Every invocation injects a new 5-minute club-index discovery bucket. Fresh discovery, changed/recent matches and urgent boards are processed before Analytics maintenance.
- **Player lane — every 30 minutes.** Every 30-minute bucket injects a lightweight authoritative roster refresh independently of historical player work. Player archives, reconciliation, ratings and achievement refresh remain lower priority.

Tournament maintenance is recommended every 10 minutes and league match monitoring hourly, staggered from the two Team Points lanes.

## Analytics freshness

Core schema 5 maintains `p2k_tp_state.core_generation`. Canonical writes that affect public statistics advance that generation. Analytics records the generation it has built, so normal freshness checks compare small state values rather than repeatedly scanning Core tables with `COUNT/MAX` watermarks.

Public GET endpoints read materialized data only. They do not perform schema migration or initiate Analytics/achievement rebuilds. Background workers own those operations.

## Public response caching

Materialized Profile, Achievement and Insights responses use short generation-keyed filesystem caches with ETags. Cache entries support stale fallback, bounded cleanup and per-key build locks to avoid stampedes. Operational/admin/session APIs remain uncached/no-store where freshness is required.

Tournament archive GETs support ETag/Last-Modified and short browser caching.

## Member and avatar freshness

The authoritative club-member roster is refreshed about every 30 minutes even during long Player jobs. A valid opportunistic `/club/promote-to-king/members` response can trigger the authoritative roster verification earlier; the browser-supplied roster itself is not written to canonical membership.

Avatar/profile metadata is persistent and demand-driven rather than bulk crawled:

- Achievement wall resolves only missing avatars in the currently visible 12-player batch.
- Login force-refreshes the logged-in Chess.com profile and feeds the validated response back to Core.
- Profile opening displays the stored avatar immediately and revalidates that one profile in the background.
- A stale known avatar remains usable; freshness determines revalidation, not whether it may be displayed.

## 0–0 void matches

Authoritative finished 0–0 matches remain in canonical match metadata with `is_void=1` for traceability. They contribute zero to all public Analytics, Insights, graphs, outcomes, averages, opponent aggregates, player participation metrics and achievement reconstruction.

## Compatibility and maintenance

v2.8.5 upgrades Core 4 to Core 5 additively; Analytics remains schema 5. No database reset is required. The v2.8.4 split-worker, board-parser compatibility, failed-board recovery and tournament-achievement date Revision 2 behavior are retained.
