# Promote to King Standalone v2.8.4

v2.8.4 integrates the approved post-v2.8.3 staging work into one standalone baseline.

## Team Points synchronization

- Splits Team Points into independent **Club Points** and **Player Points** CRON lanes.
- Club Points lane is designed for a five-minute cadence and always starts with club match discovery.
- Player Points lane is designed for a 30-minute cadence and handles roster/rating/archive/reconciliation work.
- Separate job types, worker locks, CRON leases, task-controller cards and health cadence prevent long player backfills from blocking Club Points.
- Shared Core data, point-event model, Analytics databases, cache and Chess.com gateway are retained.
- Urgent board discoveries from registered/in-progress/finished matches are routed to the Club lane even when first discovered by Player work.
- Adds bounded current-member reconciliation in the Player lane.
- Fixes Chess.com board parsing for both `games: [...]` and `game: [...]`, including string/object player forms.
- Existing failed board items from the pre-fix parser are reopened once in v2.8.4 and routed by urgency. Failures created after that recovery remain governed by the normal retry policy.
- A drained queue with unresolved failed items is no longer described as fully synchronized.
- Adds synchronization coverage diagnostics to distinguish a drained queue from complete historical coverage.

## Opportunistic Chess.com observations

- The shared browser API client non-blockingly sends already-fetched useful Chess.com JSON to the server observation endpoint.
- Club-index/match/board observations route to the Club Points lane.
- Roster/profile/stats/archive observations route to the Player Points lane.
- Client payloads are discovery hints only; clients cannot write calculated Team Points or derived match outcomes.
- Same-origin enforcement, batch limits, rate limiting and idempotent queue/storage logic are retained.

## Achievement and Hall of Fame

- Tournament achievements never invent a period-start date such as 1 January 2024.
- Exact tournament dates use authoritative Chess.com tournament finish time; otherwise the UI states `Date pending tournament refresh` and automatically upgrades later.
- The corrective achievement rebuild bypasses the old six-hour freshness shortcut once after deployment.
- Achievement player cards default to achievement-count descending, username alphabetical on ties.
- **Achievements** is now the first/default Hall of Fame tab.

## Tournaments

- **Podium ranking** is now the first/default tournament tab.
- Gold/silver/bronze headings and player-medal detail rows use real medal Unicode: 🥇 🥈 🥉.
- The embedded medalist modal retains the visible-parent-viewport positioning fix.

## Database

No new database schema is required. Core remains schema **4** and Analytics remains schema **5**. Existing installations upgrade through code/task registration only; do not recreate either database.
