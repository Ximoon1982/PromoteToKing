# Promote to King v2.9.2 — corrective release notes

v2.9.2 is the corrective release built from the actual v2.9.1 standalone package. It audits the post-v2.9.0 requests against shipped behavior and completes or corrects the items that v2.9.1 left missing, partial, or inaccurately described.

No database reset or re-seed is required. The only database change is an additive rating-provenance field used by Opponent Insights.

## Navigation and Administration

- Hall of Fame is now before Insights in the primary public navigation.
- Administration deep links preserve the Administration group, subtab, tool and integrated context through refresh and browser Back/Forward navigation.
- Dashboard Administration shortcuts open the exact integrated Administration/Admin Tools context rather than falling through to standalone tool pages.
- Database Match Profile normalizes Chess.com API match URLs to the normal human match page.
- Health monitoring uses traffic-light dots consistently instead of mixed checkmark/exclamation/cross symbols.
- Below-minimum Dashboard warnings are restricted to matches that start within the next seven days.

## Charts and time-series behavior

- Daily Boards and Club Points descriptions explicitly say that legend names can be clicked to show or hide a series.
- Maximized charts capture and restore the exact pre-open scroll position, lock background scrolling while open, and reflow after becoming visible.
- Monthly Active Members treats the entire current calendar month as incomplete/future, beginning at day 1 rather than the current-day fraction.
- Lineup Evolution and Win-probability History compress identical runs while retaining both the first and last point of each run.

## Insights · Opponents — aggregate Balance Analyzer heatmaps

- Opponent Insights now presents **one aggregate set of two heatmaps for all included matches**. It does not create a heatmap set per opponent.
- The shared filters (Friendly/League, Classical/Chess960, rating coverage, All/Top 10/Top 100 opponents, and linear/log density) re-render the same two aggregate charts.
- Heatmap 1 relates logarithmic match size to P2K rating advantage.
- Heatmap 2 compares P2K and opponent average rating with an equality reference line.
- Opponent lists and KPIs describe the currently included aggregate population.
- P2K and opponent average ratings are now computed from **the exact same valid board positions**. Missing/unrated boards are omitted symmetrically so one side cannot be averaged over a different lineup.
- `rated_board_count` is stored with match metadata and Analytics facts; the UI exposes per-match rated-board coverage and can filter on that coverage.
- Historical rows without paired-board provenance are omitted from the strength heatmaps until an authoritative match revalidation supplies paired ratings. This prevents old mismatched averages from being presented as precise data.

## Achievements

- Achievement breadth is exactly 1 / 5 / 10 / 15 / 20 eligible achievement groups; the former all-groups tier is removed.
- The seven recovered originals that have high-confidence catalogue mappings are integrated and normalized to 640×640 masters, 64×64 miniatures and 128×128 thumbs.
- Existing resolved artwork is normalized to the same derivative contract where applicable.
- Previously generic P2K-logo fallbacks are replaced by explicit achievement-specific placeholders rather than being falsely presented as recovered artwork.
- The artwork provenance manifest records which assets are existing, approved recovered, or placeholders. v2.9.2 does **not** repeat v2.9.1's inaccurate claim that 50 recovered originals had been mapped.
- Earned achievement cards remain progress-bar free; unearned cards with non-zero related metrics retain progress.
- Achievement Challenge deep links and achievement-name search remain enabled.
- King Daily Rank, Silver King Daily Rank and Live Pawn retain their corrected framed rank artwork mappings.

## Match Creation

- Clicking a Match Creation chart bar selectively fetches missing board detail only for matches contributing to that bar.
- Already loaded/cached detail is reused.
- Targeted acquisition has its own progress state and does not trigger an archive-wide scan.

## Durable queue / Work Report

- Work Report separates durable queue total, committed/done, true remaining backlog, currently pending, claimed/running, waiting for retry and failed.
- `0 pending` therefore cannot be mistaken for `0 remaining` when items are claimed, retry-waiting or otherwise still uncommitted.

## ACAMR and diagnostics

- The post-v2.9.0 ACAMR telemetry correction remains integrated: persistent client vs browsing-session identities, authenticated actor metrics, per-work-class accounting, corrected claim/member counts, ACAMR-only observations, and starvation/fetch/delivery warnings.
- Browser fetch success remains explicitly distinct from authoritative canonical convergence.

## Schema and compatibility

- Core schema: **9** (adds nullable `rated_board_count` to match metadata).
- Analytics schema: **6** (adds nullable `rated_board_count` to match facts).
- Migrations are additive and idempotent.
- Existing data, jobs, queues, configuration and logs remain compatible.
- CRON cadence remains Club 5 minutes, Tournament 10 minutes, Player 10 minutes and Match Tracking hourly, with the existing 55-second watchdog.
