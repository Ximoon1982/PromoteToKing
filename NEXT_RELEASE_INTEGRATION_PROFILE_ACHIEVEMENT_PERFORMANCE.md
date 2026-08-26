# Next release integration — Profile & Achievement performance

Baseline: Promote to King Standalone v2.8.4.
Status: staged for the next numbered release. No release number has been assigned by this patch.
Database schema: unchanged (Core 4 / Analytics 5).

## Goals

- Make Hall of Fame Achievements and unified Player Profile fast, predictable public reads.
- Never rebuild Analytics or achievement projections in a user's GET request.
- Reuse already-materialized Analytics data and short-lived response/browser caches.
- Keep operational/admin endpoints no-store.

## Staged changes

### 1. Background-owned materialization

`cron-club.php` owns general Analytics refresh with a 5-minute minimum interval.
`cron-player.php` owns achievement projection refresh with a 30-minute minimum interval.

CRON maintenance is performed before queue work. The remaining endpoint time budget is passed to the worker, so a due Analytics rebuild cannot blindly stack on top of a full worker segment and breach the hosting execution window.

Profile and achievement GET methods no longer call `refreshAnalyticsForRead()` or `refreshAchievementsForRead()`.

Expected freshness after deployment:
- General Profile/Team Points projection: normally <= 5 minutes behind Core.
- Achievement unlock projection: normally <= 30 minutes behind its sources.

### 2. Dedicated Achievement-wall endpoint

New endpoint:

`server/team-points/public/achievement-players.php`

It reads directly from materialized Analytics tables, performs filtering/sorting/pagination in MariaDB, and returns only the fields required by the 12-player achievement cards.

The achievement wall no longer invokes `members-insights.php`, which previously built all-member Core aggregates, monthly activity, rank distribution and then paginated in PHP.

Default ordering remains:
1. achievement count descending;
2. username ascending.

### 3. Faster Profile query path

Unified Profile now prefers `p2k_an_player_totals` for totals and uses indexed SQL for team/category positions instead of loading/sorting the complete current-member leaderboard.

Monthly progression comes from `p2k_an_player_monthly` instead of re-aggregating Core point events on every profile open.

Achievement ownership counts are restricted to the player's earned achievement keys instead of rebuilding the count map for every achievement type.

The broad system `summary()` call has been removed from Profile/Achievement reads; current-member count is obtained with a small materialized count query.

Recent matches and top opponents remain Core-backed because those are detail lists rather than projection-wide aggregates.

### 4. Generation-based public cache keys

Public read cache generation tokens use the small `p2k_an_refresh_state` rows plus Live processing state. Public GETs do not call the expensive Core source-watermark COUNT/MAX scan.

New class:

`server/team-points/src/ResponseCache.php`

Cache storage:

`data/runtime-v280/public-response-cache/`

This cache is separate from the Chess.com HTTP cache.

### 5. Public HTTP caching

New `Http::jsonCacheable()` adds ETag and public cache controls only to selected public read endpoints.

Staged TTLs:
- Player Profile server response: 120 s; browser max-age 60 s; stale-while-revalidate 300 s.
- Achievement player pages: 120 s; browser max-age 60 s; stale-while-revalidate 300 s.
- Achievement catalogue: 300 s; browser max-age 300 s; stale-while-revalidate 900 s.
- Player-card/avatar public responses: browser max-age 1 hour.

Admin, session, queue, logs and write endpoints continue to use `Cache-Control: no-store`.

### 6. Browser/session reuse

The integrated dashboard has a small in-memory cache for Profile/Achievement public payloads.

- Profile: 2 minutes.
- Achievement catalogue: 5 minutes.
- Tournament archive in Profile: 5 minutes.
- Avatar/player card: 1 hour.

The standalone Achievement page has the same short request cache.

### 7. Non-blocking avatars on Achievement wall

Achievement cards render as soon as the database rows are available. Avatar requests run afterward and progressively enhance the already-visible cards.

A cold Chess.com avatar cache can therefore no longer hold the entire Achievement wall in its loading state.

## No database migration

No schema changes are needed. Do not reset or recreate either MariaDB database.

The next numbered release only needs to deploy these code changes and keep the v2.8.4 Club/Player CRON schedules active.

## Validation

- Focused performance acceptance tests: 6/6 PASS.
- Full Python regression suite: 73/73 PASS.
- Static UI feature suite: PASS.
- PHP lint: PASS for all PHP files in the staged tree.
- JavaScript syntax checks: PASS.

## Files added

- `server/team-points/public/achievement-players.php`
- `server/team-points/src/ResponseCache.php`
- `tests/test_next_release_profile_achievement_performance.py`

## Files modified

- `TournamentAchievementBadgesDemo.html`
- `assets/js/pages/dashboard-v2.js`
- `server/team-points/public/achievements.php`
- `server/team-points/public/cron.php`
- `server/team-points/public/player-cards.php`
- `server/team-points/public/player-profile.php`
- `server/team-points/src/CronLoop.php`
- `server/team-points/src/Http.php`
- `server/team-points/src/Repository.php`

## Release integration checklist

When this is folded into the next numbered standalone release:

1. Keep Core schema 4 / Analytics schema 5 unless another feature independently requires a migration.
2. Add the new endpoint/classes to the release manifest.
3. Update runtime/static asset version strings to the new release number.
4. Retain the existing split CRON setup (`cron-club.php` every 5 min, `cron-player.php` every 30 min).
5. Run the full regression/syntax/package validator after manifest regeneration.
6. Verify production timing for one cold Profile, one warm Profile, cold Achievement wall, and warm Achievement wall.
7. Verify public ETag/Cache-Control headers while admin/session endpoints still return no-store.
