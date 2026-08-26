# Promote to King Standalone v2.8.6

v2.8.6 is a public-user experience and progressive-loading release built on v2.8.5. It preserves the v2.8.5 synchronization, cache, avatar, Analytics-generation and 0–0 void-match correctness work while moving non-visible public data off the first-render critical path.

## Dashboard: responsive first, automatic enrichment second

- The first visible Dashboard now waits only for the materialized Team database summary, plus the logged-in player's local Team Points when applicable.
- Database values are applied immediately; one slow secondary request no longer holds unrelated cards behind a `Promise.all` barrier.
- After the first paint, the second wave automatically starts current Chess.com club members/profile/matches, Live team/player facts, personal current-match activity and Match Assistant recommendations.
- Nothing in the former on-demand Dashboard category requires an extra click: it still preloads automatically, but never blocks first render.
- The Dashboard reuses a short session snapshot while revalidating current data.
- Once visible content is settled and the connection permits it, only two small same-origin responses are idle-prefetched: Achievement players page 1 and Team Insights summary.

## Progressive Insights

- Team Insights is split into cached sections: summary + cumulative activity first; progression/daily boards next; outcome/size/monthly charts after that; rolling/activity/concentration deepest.
- Members Insights loads summary + monthly active members first, rank distribution near the viewport, and the first 25 table rows only as the table approaches.
- Match Insights loads summary + monthly activity first; result, duration, dimension, highlight and first-table-page sections are viewport-triggered independently.
- Opponent Insights loads the summary + Top 15 first and defers the 25-row table until it approaches the viewport.
- Table search/sort/pagination requests retrieve table data only and do not recompute unrelated charts.
- Lower-card failures are local to that card/section instead of blanking an otherwise usable Insights page.

## Hall of Fame and profiles

- Achievement wall page 1 (12 players) renders before tournament summary, medal enrichment, missing-avatar checks or the full achievement catalogue.
- The achievement catalogue is requested only when it is opened.
- Missing avatars and medal counts are resolved only for the visible Achievement batch and do not block card rendering.
- Daily Hall initially returns rank summaries only; expanding a rank retrieves 25 detailed members at a time.
- Live Hall similarly returns summary/rank counts first and pages expanded rank members by 25.
- Rank ladders share Previous/Next pagination controls.
- Unified Hall search now uses one lightweight same-origin `hall-search.php` request rather than waiting on several independent browser datasets.
- Player Profile renders the database profile first. Tournament history and the one-player Chess.com avatar revalidation run afterward and update only their own areas.
- Profile `mode=modal` skips recent-match and top-opponent queries that the modal does not display; legacy `mode=full` remains available.

## Tournaments

- Default Podium Ranking is server-paged at 25 rows.
- The public page no longer downloads the complete tournament archive to show the first ranking page.
- Tournament rows are requested only after the Tournaments subtab is selected, also in 25-row pages.
- Player tournament history is fetched only when a player is opened.
- Search, sort, year/type filters and medal exclusions are applied server-side before the page is returned.

## Browser/cache/rendering changes

- Public materialized reads use normal browser cache revalidation and server ETags instead of forcing `no-store`.
- Admin/session/task operations remain uncached where appropriate.
- A shared progressive-loader provides viewport preloading (~600 px ahead), a two-request low-priority concurrency cap, idle scheduling, session snapshots and data-saver/2G prefetch avoidance.
- Native Insights chart code has been split out of the initial Dashboard bundle and is loaded only on first chart use.
- The main Dashboard controller is therefore smaller and avoids parsing the full chart engine before it is needed.

## Compatibility / database / CRON

- Core schema: **5** (unchanged).
- Analytics schema: **5** (unchanged).
- **No database migration or reset is required from v2.8.5.**
- CRON schedule is unchanged from v2.8.5; the release includes `reset-install-cron-v2.8.6.sh` for a full current-user crontab replacement when desired.
- Existing standalone URLs remain valid. Progressive API endpoints retain backward-compatible full responses when no `section`/mode is supplied.
