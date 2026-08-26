## 2.10.6.22
GABCRF deadlock-safe retry/resume; discovered-match modal stack cleanup; Maintenance admin label.

## v2.10.6.21 — 2026-08-23
Prepared from canonical v2.10.6.20. Admin-shell visual/navigation-only corrective; no schema, CRON, Green runtime, routing, scoring, heatmap or artwork change.

## v2.9.16
Prepared from canonical v2.9.15 with the post-release resilience/coverage/fallback/artwork integration set; no schema or CRON cadence change.

## v2.9.15

- Incremental source: exact canonical v2.9.14 standalone (`bc9aca3b18f14b594b6bcbe9266088f2c6c2e07f03ebba6d663f74c361df64ab`).
- No database schema migration: Core 13 / Analytics 6 unchanged.
- Corrects ACSR authoritative pulse viability, archive acquisition backpressure, Continuous Refresh/Task Control ISRE batching/render/log behavior, and Member CRON wall-clock margin.
- OAuth tuning namespaces and external CRON cadence are unchanged; versioned runtime/installer names advance to v2.9.15.

## v2.9.14 — 2026-08-12

- Incremental source: exact verified v2.9.13 standalone.
- No database schema migration: Core 13 / Analytics 6 unchanged.
- Introduces ACSR and P0 Interactive Survival, resets fresh OAuth startup seed to 30/s · cap 3 while preserving adaptive learning, and changes updater rollback storage from recursive directory copies to compact verified archives.
- Incremental removal support safely removes rejected achievement artwork with rollback coverage; protected OAuth/configuration files and unrelated CRON remain preserved.

## v2.9.13

- Incremental source: verified v2.9.12.
- Additive Core 13 migration with canonical active-queue compaction and protected-config preservation.

## v2.9.12 — 2026-08-12

Corrective upgrade from v2.9.11. No schema migration. Replaces requester-local rate authority with one server-shared OAuth launch clock; fixes queue-timeout/retry collapse, feeder bubbles, background cache-refresh interference and large Match Creation feeder/DOM overhead.


## v2.9.11 — adaptive authenticated rate convergence

Authenticated traffic is now paced by a learned safe calls-per-second controller with safe/unsafe boundary convergence and endpoint-class latency baselines. Match Creation no longer mistakes inherently slow detail responses for transport congestion. Core remains 12 / Analytics 6; no schema or CRON cadence change.

## v2.9.10
- Upgrade path: v2.9.9 → v2.9.10. Core 11 → 12; Analytics 6 unchanged; four-task CRON cadence unchanged.

- **v2.9.9** — removed real-OAuth caller-side throughput ceilings site-wide while keeping public/fake OAuth serial; no schema or CRON cadence change.
- **v2.9.8** — integrated post-v2.9.7 observation/ACAMR staging and real Chess.com OAuth; added Insights/Hall fixes and OAuth transport telemetry.

# v2.9.7 — 2026-08-11

Focused corrective release from verified v2.9.5: Player worker fairness/freshness correctness, truthful Task Control diagnostics, OAuth POC protected configuration, Live Rook presentation, and achievement group progress.

# Promote to King update history

## v2.9.2 — 2026-08-10

Corrective release from v2.9.1. Completes the audited post-v2.9.0 backlog, makes Opponent Insights heatmaps aggregate across all included matches, introduces paired-board rating provenance and coverage, corrects achievement breadth/artwork claims, finishes Administration/history/chart behaviors, and separates durable queue backlog states. Additive Core 9 / Analytics 6 migration only.

## v2.8.8

- Added Club Intelligence, adaptive ACAMR telemetry/allocation, member activity/availability/contribution, snapshots, anomaly/action queue and explainable forecasts.
- Unified player profile extended across Hall, Insights, Recruitment, Tournaments and Club Intelligence; Hall defaults to Achievements; nested modal restoration fixed.
- Achievement source links normalize to Chess.com web URLs; Top Opponents adds cached Chess.com club logos.
- Core schema 6 / Analytics schema 5; CRON cadence unchanged.

# v2.8.7 — 2026-08-08

- Added ACAMR (Authenticated Client-Assisted Member Refresh), a low-pulse authenticated-browser accelerator for Club Points, Member Points, member archives, relevant board discovery, ratings and roster freshness.
- Real OAuth activates ACAMR regardless of the simulated-OAuth flag; simulated authentication activates ACAMR only when that flag is enabled.
- Explicitly excluded tournaments and match-registration/recruitment monitoring from ACAMR.
- Distributed current-member work across active clients with protected filesystem claims and one-leader-per-browser scheduling; browser data remains non-authoritative.
- ACAMR archive observations can route known P2K participations directly to authoritative board verification instead of forcing a complete server archive refetch merely for discovery.
- Integrated the post-v2.8.6 audit: canonical Dashboard historical totals, Core/Analytics freshness fixes, cheaper club resolution, shared Insights range/base calculations, SQL-paged Hall summaries, materialized Tournament browse index, smoother automatic Dashboard second wave and lazy Hall/Insights modules.
- Retained the authoritative finished 0–0 `is_void=1` exclusion, demand-driven avatars, split Club/Player workers, failed-board recovery and tournament achievement-date Revision 2.
- Core schema 5 / Analytics schema 5 unchanged; no database migration and no server CRON cadence change.

# v2.8.5 — 2026-08-08

- Guaranteed time-bucketed Club match-index discovery every five-minute Club CRON invocation, even inside long jobs.
- Added independent 30-minute lightweight roster freshness and server-verified opportunistic roster/profile/rating refresh triggering.
- Moved urgent Club work ahead of Analytics and removed read-time Analytics/achievement rebuilds from public pages.
- Added Core generation state, persistent avatar/profile snapshots, SQL-paginated materialized Profile/Achievement/Members reads, response-cache locking/cleanup and cheaper gateway cache status.
- Staggered Tournament CRON to 10 minutes and added public tournament ETag/Last-Modified caching.
- Excluded authoritative finished 0–0 void matches from all public analytics/graphs and participation achievements while retaining raw records.
- Core schema 5 / Analytics schema 5; additive migration only.

# v2.8.1 Hotfix 2 — 2026-08-07

- Reconciled v2.8.1 Hotfix1 with RecoveryFix7 instead of replacing either branch.
- Advanced the combined Core/Analytics schema to revision 3 with idempotent convergence from schema 1 or either historical schema-2 branch.
- Added opportunistic Daily/Chess960 ratings, split last-game dates, RM-2 stored-rating recruitment and the RecoveryFix7 Team Insights projections/visual accents.
- Retained v2.8.1 tracked-lineup, Administration, opponent-intelligence, profile and achievement-history improvements.
- Recovered 18 shipped achievement artwork mappings.
- Unified mobile width/spacing across Insights, Hall of Fame and Administration while leaving desktop dimensions unchanged; wide tables now scroll locally.

# v2.8.1 — 2026-08-07

- Added live-ended lineup/win-probability history with real UTC timescale, zoom, hover, keyboard timeslot navigation and win-probability deltas.
- Integrated Live-ranks computation, Open Match Analyzer and Storage & capacity into Administration with natural-height embedded pages.
- Made the public Promote to King club API the primary administrator list, with local branding allow-list as outage fallback only.
- Restored tournament medal styling and improved Team/Member/Match/Opponent Insights layouts.
- Replaced the opponent treemap with Top 25 + Others and added zoomable monthly W/D/L, time-control win rates and rating-bracket win rates.
- Improved unified player rank/progression display and persisted achievement unlock timestamps/member counts in Analytics.
- Added automatic in-place Core/Analytics schema version 2 migration; no initializer or seed import is required.

# v2.8.0 — 2026-08-07

- Split Team Points storage into fresh compact Core and rebuildable Analytics MariaDB databases.
- Moved shared Chess.com API response caching to protected bounded gzip filesystem storage.
- Removed SQL HTTP-cache and SQL seed-staging architecture.
- Added fresh dual-database initializer using the checksum-verified 2026-08-06 snapshot.
- Added storage/capacity administration with 80% alerts, monthly history and 80%/100% projections.
- Retained v2.7.3 database-independent administrator page admission.

# Promote to King update history

## v2.7.2 — 2026-08-06

- Rebuilt Team Insights from authoritative database event timestamps and schema-13 daily facts.
- Corrected cumulative activity, calendar rolling windows, boards/Club Points and valid-duration analytics.
- Added hover legends and drag-to-zoom charts, result pies/distributions, match type/time-control/duration distributions, active-member/rank charts and an opponent treemap.
- Added compact desktop opponent tables and an administrator-only plain-text result-copy action.
- Upgraded the embedded Team Points Seed Importer to v1.2.0 with retained rules, time controls and league classification.

# Promote to King v2.7.1 — 2026-08-06

- Added a checksum-verified standalone seed utility with the six updated source files embedded; no CSV selection is required at runtime.
- Validated 4,473 members, 17,091 discovered matches, 104,761 member/board rows and a 371,168-point club total.
- Preserved findings-only match 1983076 as unknown and queued its authoritative metadata repair.
- Added HMAC staging and atomic Team Points replacement while clearing obsolete Team Points scheduler history.
- Added seeded incremental roster/match/monthly-archive synchronization.
- Removed routine all-member history and raw global-ID scans; retained both as explicit repairs.
- Added schema 12, seed/algorithm administration status and five-minute Team Points/tournament scheduler ticks.
- Added complete CRON pause/delete/install and rollback instructions.

# Promote to King v2.6.2 — 2026-08-06

Adaptive match-monitoring release. The legacy endpoint is now scheduled hourly, but individual matches are fetched only when due: hourly for the first 24 hours, 12-hourly normally, 6-hourly inside 96 hours of start and hourly inside 48 hours. Consecutive archive records with identical players and ratings are omitted from graphs without deleting stored samples.

# Promote to King v2.6.1 — 2026-08-06

Backward-compatible server-maintenance release introducing one shared Chess.com gateway, a unified task registry/control page, server-owned Team Points execution, host-safe checkpointed CRON segments, automatic administrator database initialization, centralized health/logging, and dashboard maintenance deep links. Expected cadences are five minutes for Team Points, six hours for league match tracking, and one day for tournaments.

# Promote to King v2.6.0 — 2026-08-05

- Added database provenance and direct DB endpoints for Team, Members, Matches and Opponents Insights.
- Added Members Insights and unified player profiles.
- Added Team period comparison, rolling activity, unique-player and concentration analytics.
- Added Match drill-down, result/size, duration, category and notable-match analytics.
- Added opponent profiles and history.
- Added administrator recruitment demand, league/season and Insights health tools.
- Replaced the fictive achievement demo with a database/tournament-backed Hall section.
- Added win probability to high-priority administrator match actions.
- Enforced integer Club Points throughout Insights.
- No schema or CRON endpoint change.

# Promote to King update history

## v2.5.1 — 2026-08-05

Standalone release of the database/API-backed Insights integration, dashboard terminology refinements, Hall of Fame player-focused rank navigation, and the restored in-place Match Assistant opening behavior.

## v2.5.0 — 2026-08-05

Major UI v2 dashboard, Live arena analytics, database repair and tournament-management release.

# Update history

## v2.4.23 — 2026-08-05

### Shared Daily/Live rank ladder
- Added a reusable rank-ladder renderer shared by Daily and Live ranks.
- Unified Live-rank cards, expansion, framed/unframed art, standings tables, and URL selection with Daily ranks.
- Added Current members, Ranked members, Team leader, and Your position to Live ranks.
- Restricted the public Live ladder to current P2K members while preserving former-account data in Administration.
- Switched Live-rank display to 192 px and 640 px WebP thumbnails with PNG fallbacks.


## v2.4.19 — 2026-08-05

### UI v2 stabilization
- Updated the finished-match explanation to require authoritative Chess.com `status=finished` before local validation.
- Preserved page, subtab, administration category, Hall query/rank, Team Insights range, Match Assistant state, and native table state in URL history.
- Restricted the global progress bar to essential team/database loading and kept optional work section-local.
- Replaced the hard-coded Administration tool count with the actual registry size.

### Native Insights and shared tables
- Activated the Matches subtab with database-backed statistics, monthly activity and detailed searchable/sortable rows.
- Migrated Opponents into the UI v2 document while retaining the standalone page.
- Added one shared accessible table implementation for search, filters, sorting, paging, empty states and URL state.

### Hall of Fame and demos
- Added unified player search across Daily ranks, Live ranks and tournament medals.
- Added a tournament-achievement badge proposal demo.
- Added a fully standalone fake-data representative UI v2 demo.

## v2.4.18 — 2026-08-05

### Navigation
- Consolidated UI v2 public navigation into Dashboard, Insights and Hall of Fame.
- Added the requested disabled placeholder subtabs.
- Moved Tournaments under Hall of Fame.
- Persisted page and Hall subtab selection in URL history.

### Team Points
- Prevented premature finalization when a former member is still playing by requiring authoritative match status `finished`.
- Added fast former-member and complete database consistency repair pages, protected by the existing administrator token.
- Added transactional repair backups, rollback and cached-total rebuilding.

### Tournaments
- Added a confirmed full reinitialization with timestamped archive backup.
- Resets tournaments, podiums, exclusions, cursors and caches, then launches the resumable exact-title browser reconstruction with podium/account validation enabled.

## v2.4.14 — 2026-08-04

### Dashboard
- Replaced coarse section-complete loading jumps with weighted incremental progress.
- Added progress messages from the Match Assistant setup stages and per-match processing count.
- Added the authenticated member's P2K join date from the club member API response.
- Renamed the recommendation area to Recommended matches and simplified its status text.
- Unified the title size of the member, team and recommendation cards.
- Increased the statistic-card supporting text size.
- Added shared blue information popovers to metrics whose calculation is not self-evident.

### Hall of Fame
- Replaced the Hall of Fame tab icon with a star.
- Replaced the summary's club-points field with the number of members holding a rank.
- Removed redundant default rank-count status text and search help copy.
- Renamed Show all ranks beside the search field to Clear.
- Removed the second Show all ranks control inside expanded rank cards.
- Scrolls a newly opened rank card to the top of the visible page.

### Navigation
- Removed the public Classic UI link while retaining the established `?ui=v1` compatibility route.

## v2.4.13 — 2026-08-04

The public UI v2 tabs now include compact accessible icons. Rank artwork uses optimized WebP miniatures sized for the actual dashboard and Hall of Fame placements: 192 px for ladder cards, 320 px for the player vignette and 640 px for expanded framed ranks. Original PNG files remain available and are used automatically if a thumbnail cannot be loaded. Public tab switching no longer forces the page to scroll to the top and uses an opacity-only transition to reduce layout movement.

## v2.4.12 — 2026-08-04

### Rank and information-tip consistency

- Corrected Pawn minimum from 0 to 10 Team Points in both the public dashboard and server Hall of Fame classification.
- Added an unranked state for members below 10 Team Points.
- Reused the shared blue UI v1 information button/popover for dashboard match-recommendation justification.

### Secure administrator interface

- Removed the Public/Admin control and administrator panel markup from the initial HTML.
- The controls are created only after the same-origin Team Points session endpoint verifies the authenticated username against the live Chess.com club administrator list.
- Failed, missing or non-administrator verification removes any previously created administrator controls and returns the dashboard to Public view.
- Client-side DOM injection cannot authorize protected operations: administrator APIs continue to require the server-issued HttpOnly session and CSRF token.

### Public navigation and Hall of Fame

- Moved public navigation to the top of UI v2 and replaced Suggested additions with full-page tabs.
- Dashboard remains the default page and retains its previous layout.
- Added placeholder tabs for Team insights and Tournaments.
- Hall of Fame now loads as a full dashboard page.
- Opening Hall of Fame from the player rank vignette searches the authenticated player automatically, opens only their rank group, highlights their row and scrolls it into view.

### Immutable Team Points match cache

- Added schema version 5 with `p2k_tp_match_summaries` and `p2k_tp_club_totals`.
- A finished match is finalized once only after every known board has two stored games.
- Match summaries are immutable because finished Chess.com team matches cannot reopen.
- Existing point-event results and earned points are preserved on duplicate discoveries and cannot be reduced by a later API response.
- Match scoring remains: win `5 × boards`, draw `2 × boards`, loss `0`.
- Club totals are incremented in the same transaction that inserts the unique match summary, making rediscovery and repeated workers idempotent.
- Dashboard reads are constant-time and no longer aggregate all participations and point events.
- Historical matches are finalized in bounded worker batches; dashboard and secure-login requests never perform the historical scan.

### Compatibility

- Existing jobs, queue rows, board states, point events, CRON leases and `config.local.php` remain compatible.
- No manual installer run or configuration edit is required.

## v2.4.11 — 2026-08-04

### Public UI v2

- Restored a clear 14 px separation between the equal-height player/team row and the recommendations panel.
- Corrected Diamond King fallback wording so the highest rank has no artificial maximum boundary and long descriptions wrap safely.
- The Public/Admin switch is now hidden by default and appears only after the current Chess.com club profile endpoint confirms the authenticated username as an administrator or super administrator. URL flags and cached local markers no longer grant the toggle.

### Detailed match analysis

- Added a parent-window detailed-analysis host to both classic UI v1 and optional UI v2.
- Embedded Match Assistant instances now ask the visible parent shell to open the analysis, preventing the dialog from being centered outside the visible area of a tall iframe.
- Direct standalone Match Assistant use retains its local modal.
- Removed the administrator-page guard from `AnalyzeMatchModal.html`, because it is a public drill-down of the public Match Assistant.
- Updated the Match Assistant controller cache key to v2.4.11 so browsers and CDNs cannot retain the older modal implementation.

### Team Points public dashboard data

- Player points and W/D/L totals are now read directly from point events and do not depend on a currently active member row.
- Finished match, board, game and club-point totals are reconstructed from the original participation and point-event tables, so databases created before board-state classification remain usable.
- Club points retain the requested match formula: win `5 × boards`, draw `2 × boards`, loss `0`.
- Added a safe legacy single-club slug resolver: the configured slug is always preferred, but an older database containing exactly one different stored club slug is reused instead of silently reporting zero.

### Compatibility

- No database schema migration is introduced.
- Existing jobs, queue items, board states, CRON state and `config.local.php` remain unchanged.
- UI v1 remains the default and its application layout is unchanged apart from the additive visible-parent analysis host.

## v2.4.10 — 2026-08-04

### Public UI v2

- Matched the player and team dashboard panels to the same height when displayed side by side.
- Moved the Hall of Fame link above the Team Points win/draw/loss counters.
- Removed the redundant text summary from the bottom of the team panel.
- Moved the Public/Admin switch into the title header, directly left of the authenticated player avatar.
- Removed the secondary dashboard identity band and the requested introductory labels and descriptions.
- Hid the dashboard loading indicator as soon as all data sources finish.
- Corrected member-count precedence so the club profile's authoritative live total is preferred over potentially truncated member-array data.
- Enlarged and centered expanded framed Hall of Fame artwork, added a border, and placed larger description, player-count and leader details below it.
- Reused the already-loaded Match Assistant recommendation iframe when Find more matches is selected; the embedded assistant is full-width, borderless and headerless like UI v1.

### Team Points and database data

- Finished-match, board and game totals now come from Team Points database board states.
- Corrected club competition points to match-level scoring: a won match awards `5 × board count`, a drawn match awards `2 × board count`, and a lost match awards zero.
- Only fully stored finished matches participate in the competition-points calculation.

### Priority discovery

- Added **Prioritize fresh discovery** to Team Points administration.
- The action inserts a unique team-member discovery task at the front of the active database worker queue.
- That task inserts uniquely keyed member-match discovery items at the same priority, so previously completed legacy items cannot suppress a fresh pass.
- Existing job rows, item types, queue schema and old payloads are unchanged and remain executable.

### Compatibility

- UI v1 remains the default and is unchanged outside version/cache references.
- No database schema migration is introduced by v2.4.11.
- Existing Team Points data, CRON state, jobs and queued work remain compatible.


---

## Archived update report v2.4.9

```text
Promote to King v2.4.9 update report

Scope
- Fixed Hall of Fame/player-games/team-list modal backdrops being visible despite `hidden`.
- Added finite 35-second secure reconnect and 30-second Team Points request timeouts.
- Added a 5-second default MariaDB connection timeout and bounded lock waits.
- Removed the blocking historical board-state aggregation from schema migration.
- Added bounded historical board-state backfill during normal member synchronization.

Compatibility
- No configuration edit is required when the existing config.local.php is valid.
- No manual install.php run is normally required; schema preparation remains automatic and idempotent.
- Existing jobs, queue items, point events, participations and CRON state are preserved.
```


---

## Archived update report v2.4.8

```text
Promote to King v2.4.8 update report

Scope: UI v2 dashboard only.

Implemented:
- Player card now shows current team games and matches, match registrations, and historical DB games/matches.
- Explore games opens registered, ongoing and recent-finished player team-match lists in place.
- Rank image enlarged; the redundant inner player-card frame removed.
- Team statistic cards compacted for landscape layouts.
- Gauge numeric percentages removed; qualitative state labels retained while fill/color remain data-driven.
- Gauge explanatory text reduced.

Unchanged:
- UI v1, Match Assistant recommendation logic, Hall of Fame, Team Points backend/schema/queue, CRON, administration and all other tools.
```


---

## Archived update report v2.4.7

```text
Promote to King v2.4.8 focused update

Changed only UI v2 dashboard presentation:
- bordered player rank image
- smaller Hall of Fame link
- three club-stat cards per row on phone widths
- two readiness/call cards per row on phone widths

No application logic, APIs, Team Points processing, Hall of Fame behavior, recommendations, CRON behavior, or UI v1 markup was changed.
```


---

## Archived update report v2.4.6

```text
Promote to King v2.4.6 update report
=======================================

Scope
-----
Only the optional Public UI v2 dashboard was changed. No Team Points schema, queue, CRON, API, Hall of Fame, recommendation scoring, administrator tool or classic UI behavior was modified.

Implemented
-----------
- Removed Daily and Chess960 rating tiles.
- Placed Team Points first and added W/D/L counters.
- Used the signed-in nickname as the personal-card heading.
- Removed the extra personal-card text and Find a suitable match action.
- Removed the Overall team statistics heading.
- Added square/mosaic team statistic tiles.
- Added Registered, Ongoing and Finished match cards with aggregate board and game totals.
- Added small View controls opening status-specific in-page match lists.

Compatibility
-------------
- UI v1 remains unchanged and default.
- UI v2 still requires ?ui=v2.
- Existing data sources and recommendation behavior are unchanged.
- No database migration is required.
```


---

## Archived update report v2.4.5

```text
PROMOTE TO KING v2.4.5 - UPDATE REPORT

1. Added a database-backed public Hall of Fame to UI v2, ordered from Diamond King to Pawn.
2. Clicking a rank category expands it, switches to the larger framed rank image, and displays members ordered by Team Points.
3. Member standings include category rank, overall team rank, points, matches, games, wins, draws and losses.
4. Added a Hall member search between the summary statistics and rank ladder. A found member collapses all other categories and is highlighted; Show all ranks restores the ladder.
5. The player Team Points vignette now displays the player's overall rank within the current team.
6. Find more matches keeps the first five recommendations as the default and replaces them in place with the Match Assistant only after it is clicked.
7. The Hall endpoint is read-only and uses the current Team Points tables; no schema migration, job rewrite or queue payload change is required.
8. Retained all earlier 2.4.x behavior: automatic secured DB access, diagnostics alignment, continuous CRON, board rediscovery, live dashboard statistics, readiness gauges and strict ?ui=v2 gating.

Known data limitation: the current Team Points schema does not store an authoritative team-match completion flag. Finished matches therefore display an explicit placeholder rather than a fabricated value.
```

## v2.6.3

Achievement catalogue, first ten achievement icons, paginated avatar-backed player cards, Live-rank unified profiles, viewport-safe profile navigation and sequential one-concurrent-game Team Points handling.

## v2.10.6.23 — 2026-08-25

Administration data-quality update: member rename chronology consolidation, known tournament status table, unknown metric-state unification, and full member lookup. No schema/CRON/routing/scoring change.
