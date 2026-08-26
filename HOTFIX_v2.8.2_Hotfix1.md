# Promote to King v2.8.2 — Hotfix 1

This hotfix fixes Team Insights and Opponents Insights failing with:

`SQLSTATE[21S01]: Insert value list does not match column list: 1136 Column count doesn't match value count at row 1`

## Minimal deployment

Upload this corrected file over the existing v2.8.2 file:

`server/team-points/src/AnalyticsBuilder.php`

No database reset, initializer, seed import or manual SQL is required. The next Team Insights or Opponents Insights request will retry the Analytics refresh from authoritative Core data.

## Cause

The v2.8.2 match-facts Analytics insert listed 25 columns and bound 25 values, but its SQL `VALUES(...)` clause contained only 23 placeholders. Hotfix 1 restores the missing two placeholders for `max_rating` and `first_discovered_at`.
