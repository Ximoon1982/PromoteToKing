# Promote to King Standalone v2.8.5

v2.8.5 is a synchronization, caching and read-performance release built on v2.8.4.

## Fresh Club/Player synchronization

- Every Club CRON invocation injects a time-bucketed club-index refresh even while an older Club job is still active.
- Fresh Club discovery and urgent match/board completion run before Analytics maintenance.
- Player history remains lower priority and cannot suppress Club discovery.
- A new lightweight `sync_roster` refresh guarantees an authoritative club-member refresh about every 30 minutes independently of long Player-history work.
- Team Points Administration reports last club-index observation, last roster observation and Core generation.
- Tournament CRON is staggered to every 10 minutes to avoid deliberate shared-gateway collisions.

## Opportunistic Chess.com observations

- Club-member observations validate the payload shape and trigger a high-priority authoritative `sync_roster`; the browser payload itself never changes canonical membership.
- Player-profile observations for known members trigger a high-priority server-side profile/avatar verification; only the server-fetched Chess.com response is persisted.
- Player-stats observations for known members trigger server-side rating verification before Daily/Chess960 snapshots are stored.
- P2K match observations prioritize canonical server-side match verification without directly writing browser-supplied match facts.
- Player-match/archive hints are relevance-checked and use freshness-aware queue keys.
- Monthly archive observations for current members are compacted to raw match-linked game hints, then trigger server-side archive verification and known-P2K match follow-up; browser-supplied games never write canonical point events directly.
- Browser observation deduplication is signature/TTL based rather than permanent URL-only suppression.
- Client-derived Team Points, match forecasts, probabilities and other derived truth remain rejected as authoritative data.

## Profile, Achievements and avatar performance

- Public Profile/Achievement reads no longer rebuild Analytics synchronously.
- Achievement player lists use a dedicated materialized, SQL-sorted/paginated endpoint.
- Profile totals/monthly progression use materialized Analytics and indexed team-position queries.
- Short response caches, ETags and stale-while-revalidate are used selectively on public materialized reads.
- Achievement cards render before missing avatars are resolved.
- Only the currently displayed Achievement batch (12 players) is considered for missing-avatar lookup.
- Known avatar/profile metadata is persisted in Core and remains usable while stale; metadata uses long client/server cache windows.
- Login forces a fresh browser profile fetch for immediate UI freshness and triggers a server-side verification task that updates the persistent snapshot.
- Opening Profile displays the stored avatar immediately, refreshes that one profile in the browser, and triggers server-side verification in the background.
- No bulk avatar crawler is introduced.

## Analytics and cache efficiency

- Core schema 5 adds a cheap `core_generation`; Analytics freshness checks use it instead of repeatedly scanning Core with COUNT/MAX.
- Members Insights uses SQL-side materialized filtering/sorting/pagination for normal/all-time views.
- Team/Match/Member/Opponent Insights public responses use short generation-keyed response caches.
- Top-opponent queries are bounded to the visual requirement.
- The shared filesystem HTTP-cache status path no longer decompresses every cached response during ordinary status reads.
- Player-card responses no longer attach expensive gateway-wide status data.
- Response caching gains per-key stampede protection, stale fallback and bounded cleanup.
- Tournament archive GET responses support ETag/Last-Modified and short public caching.
- Endpoint-specific Chess.com freshness policies allow historical immutable data to reuse cache more aggressively.

## 0–0 void-match correctness

Authoritative finished matches with a 0–0 score remain stored as `is_void=1` for traceability, but now contribute **zero** to public analytics. They are excluded consistently from started/finished activity, board totals and averages, outcomes, match-size/category/rules/time-control/duration distributions, rolling/monthly/cumulative graphs, opponent aggregates, player participation/achievement milestones and related comparisons.

## Robustness/security

- Public GET endpoints no longer perform schema migrations/DDL.
- Same-origin enforcement now honors `rejectMissingOrigin=true` even in host-fallback mode.
- Existing v2.8.4 board-parser compatibility, failed-board recovery, split-worker health semantics and tournament-achievement date Revision 2 are retained.

## Database

- Core schema: **5**
- Analytics schema: **5**
- Upgrade is additive and in place; no database reset is required.

## Deferred structural refactor

The large dashboard JavaScript remains a single deferred script in v2.8.5. Splitting its shared closure/state into independently lazy-loaded Hall/Insights modules is a future structural optimization, not a correctness or server-latency fix; it was deliberately deferred to avoid destabilizing this release after the higher-impact backend/read-path optimizations.
