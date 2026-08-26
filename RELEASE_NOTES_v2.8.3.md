# Promote to King v2.8.3

v2.8.3 is an in-place release built directly on **v2.8.2 Hotfix 2**. The Hotfix 1 Analytics insert correction and Hotfix 2 non-blocking/split Analytics refresh design are part of this baseline.

## Achievements

- Achievement player cards show the number of achievements earned instead of Live rank.
- Earned achievement details show the stored achievement date.
- When the source can be reconstructed, achievement details also show the triggering event name and a direct link.
- Match/game milestones retain the triggering team match as provenance.
- Tournament medal milestones retain the triggering tournament as provenance.
- Analytics schema 5 adds `source_type`, `source_name`, and `source_url` to persistent achievement unlocks.

## Tournament medal dates

- Tournament archive rows now preserve Chess.com's authoritative tournament `finish_time` as `finishAt`.
- Tournament medal achievements use the tournament finish date, not the month/period bucket.
- Existing derived tournament achievement rows are rebuilt automatically by the Analytics 4→5 migration.
- Older archived tournaments without `finishAt` are queued for normal tournament refresh so their finish dates can be backfilled incrementally.

## Tournament medalist modal

- In the integrated dashboard, clicking a medalist now positions the modal inside the currently visible parent viewport instead of centering it in the full natural-height iframe document.
- Standalone tournament-page modal behavior is unchanged.

## Dashboard interaction

- Dashboard metric cards that navigate to another view now use the same hover/focus treatment as Priority Calls.

## Club Points projection

- The current-year projection is now anchored to actual Club Points earned over the latest 90 calendar days.
- The model explicitly uses three consecutive 30-day blocks.
- The medium forecast is a near-linear continuation of that recent production rate.
- A small trend bend is derived from the first-versus-third 30-day block and capped at ±8% at year end.
- Low and High paths form ±10% bands around the medium trajectory.

## Insights charts

- Removed the legacy fixed 230px chart-container height that caused native charts to spill outside their cards.
- Monthly match activity now stays contained in its card on desktop and mobile.
- Most played opponents is redesigned as a dedicated Top-15 horizontal chart with wider labels and contained dimensions.
- Added **Average boards per match**, showing monthly evolution for finished matches.
- Monthly active members now extends its time axis through the remaining months of the current year and marks **Today · future →**; future months are not plotted as zero activity.
- The standalone Team Insights monthly active-player graph uses the same future boundary convention.

## Database migration

- Core remains schema **4**.
- Analytics upgrades additively from schema **4 to 5**.
- No database recreation, initializer, or seed reload is required.
