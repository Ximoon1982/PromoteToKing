# Promote to King v2.10.6.21

## Administration shell navigation visual parity

This admin-only UI release replaces the six-category Administration view menu
with the same public `dashboard-page-tabs` component used by Dashboard, Hall of
Fame, Insights and Administration. The six categories remain Competitions,
Members, Team, Opponents, Admin & maintenance, and Misc. Each category now has a
small matching stroke SVG icon.

The menu occupies the public menu slot when Admin view is active, uses six equal
desktop columns, and collapses at the same 620 px breakpoint to two columns.
The visible Administration / Administrator dashboard introductory heading and
description are removed. The OAuth/local admin badge and Refresh live cards
action now sit directly below the category menu.

No public navigation behavior, database schema, reset/reseed, CRON definition,
Blue/Green routing, scoring, heatmap logic, or artwork is changed.
