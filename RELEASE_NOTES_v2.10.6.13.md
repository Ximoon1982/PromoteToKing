# Promote to King v2.10.6.13

Baseline: v2.10.6.12 complete canonical source tree.

## Scope

v2.10.6.13 consolidates navigation/state handling and completes the authenticated Admin shell introduced in v2.10.6.12.

### Administration

- Admin cards now open detail content inside the Admin shell instead of navigating to the older public-side Administration interface.
- The six top-level Admin tabs remain unchanged: Competitions, Members, Team, Opponents, Admin & maintenance, Misc.
- Opening a card replaces that tab's card grid with the relevant existing operational tool, preserving the Admin navigation around it.
- Existing tools are reused through embedded standalone pages; business logic is not duplicated.
- Detail pages support breadcrumbs/back navigation and detail subtabs.
- Scheduled Task Control continues to expose the Green Team Points surface and accelerator.
- Lost & found continues to preserve the complete existing administrator tool catalogue.

### URL / browser state

- Public top-level views use canonical `page=dashboard|hall|insights` URLs while legacy empty Dashboard URLs remain accepted.
- Hall and Insights subtabs remain directly addressable.
- Admin categories use `view=admin&adminCategory=...`.
- Admin detail pages use `adminDetail=...`; detail subtabs use `adminDetailTab=...`.
- Embedded operational-tool subtabs propagate to the parent URL with `adminToolTab=...` where required.
- Back, Forward and Refresh reconstruct the selected public/Admin state.
- Direct Admin deep links survive the asynchronous administrator/OAuth verification phase.
- Legacy integrated Administration direct URLs now correctly accept `members` and `migration`.

### Dashboard Match Assistant corrective

- The Dashboard no longer promotes the hidden recommendation iframe into the full analyzer.
- A dedicated full Match Assistant iframe is created/reused for expansion.
- Recommendations remain visually intact while the full assistant prepares.
- The Dashboard switches to the analyzer only after the dedicated frame reports full-ready.
- Open/close actions now create browser-history states so Back/Forward restore the expanded state.

### UTC restoration

- Date/time display formatters that had begun following the browser's CET/local timezone are explicitly returned to UTC.
- The audit covers shared match history/start times, Dashboard operational telemetry, match creation/analyzer/challenge views, Task Control, Club Intelligence, Team Points admin/migration, OAuth/session dates, tournament management and related operational pages.

## Public UI regression constraint

The public Dashboard / Hall of Fame / Insights DOM and pre-v2.10.6.13 Dashboard CSS are regression-locked to v2.10.6.12. Public visual presentation is unchanged except for:

1. the Dashboard Match Assistant expansion corrective; and
2. timestamps being displayed in UTC again.

URL changes are state/navigation-only and do not alter public layout.

## Explicitly unchanged

- Database schema and data
- Blue/Green routing
- Club/player scoring
- CRON configuration
- API acquisition policy
- Artwork/images
