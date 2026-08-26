## v2.10.6.22 — GABCRF deadlock resilience + Admin modal/label corrective
- Treats MariaDB 40001/1213 GABCRF deadlocks as transient bounded retries/yields while preserving convergence state.
- Auto-recovers an already-stored GABCRF deadlock; Start/resume no longer resets its cursor/counters.
- Fixes the New matches · 24 h transient loading modal reappearing after closing the real match profile.
- Renames Admin & maintenance to Maintenance.

# v2.10.6.21

- Reuses the public `dashboard-page-tabs` component for the six Admin view categories, including matching SVG icons and the same 620 px two-column mobile collapse.
- Removes the Administration / Administrator dashboard introductory heading block.
- Moves OAuth/local admin and Refresh live cards directly below the Admin category menu.
- Admin-only UI/cache-generation release; no runtime, DB, CRON, routing, scoring, heatmap, or artwork change.

# v2.10.6.19

- Corrected historical heatmap provenance to use stored/board game ratings rather than ratingless historical match details.
- Restored bounded background OAuth acceleration and admin/P0 responsiveness; removed accelerator JSONP fan-out.
- Corrected paired-board rating invariant and GAB/heatmap telemetry separation.


## v2.10.6.11 — Green live freshness corrective
- Dashboard Team Points and match-state lists use live Green Core when Green is selected; Chess.com match-list overwrite removed.
- Team Points browser-memory/public fetch caching no longer masks newer DB generations.
- Migration Green headline totals/top players are live Core; analytics snapshot retained separately.
- Green derived analytics minimum rebuild cadence reduced from 300s to 30s.
- Match Creation Analyzer and Match Assistant embedded pages now use v2.10.6.11 cache generation.
- Match Assistant shell-ready and full-ready states are separated to eliminate the stale loading layer race.
## v2.10.6.10 — Registration/tracking/assistant corrective

- Registration 15-day chart now uses the same canonical registered-match records/date keys as the registered-by-start-date table; localized table text is no longer reparsed.
- Continuous tracked-match monitoring now stops at match start + 24 hours for automatic, manual and legacy sources, while preserving history and allowing one-off recording.
- Dashboard Match Assistant loading/preparation visibility is normalized so filtered assistants cannot open with a stale loader behind them.
- No DB schema/reset/reseed, CRON-definition, Green routing, scoring or image changes.

## v2.10.6.9 — Green authoritative live reads

- Makes native Green Club Points and signed-in player Team Points authoritative immediately after Green cutover.
- Projects Green worker/browser observations into public compatibility contracts regardless of GAB status and fixes browser observation identity propagation.
- Rebuilds public compatibility analytics during GAB convergence so Opponent Insights/heatmaps and other analytics-backed pages use current Green data.
- Replaces Blue Team Points production-health warnings with Green runtime/analytics health when public reads are Green.
- No schema, reset, reseed, CRON or image change.

## v2.10.6.8 — Green operational cutover

- Replaces bootstrap-era queue-empty cutover gates with technical-only Green routing prerequisites; continuous queue/GAB/GFFL conditions are advisory.
- Adds one-click Switch reads to Green, Make Green primary and Rollback reads to Blue actions in Scheduled Task Control.
- Keeps both Blue and Green maintenance active after the first Green-read switch and exposes compact cutover health metrics.
- Removes the public read router requirement for `gab_status=ready` while retaining fail-closed behavior for real Green connection/schema failures.
- No schema, reset, reseed, CRON or image change.

## v2.9.16 — post-v2.9.15 resilience, opponent coverage, PMAF and league artwork

- Integrated DRR, MLP, FFSD, UDR, OICR and PMAF.
- Normalized Opponent Intelligence club links to human Chess.com URLs.
- Integrated approved artwork for all 35 league-specific achievements.
- Removed progress bars from the general achievement catalogue while retaining player progress.
- Core 13 / Analytics 6 / four-task CRON cadence unchanged.

## v2.9.15 — telemetry corrective release

- Fixes the structurally impossible 6-second ACSR authoritative worker pulse with a viable 34-second worker budget and 45-second control envelope while retaining the existing outbound safety guard.
- Caps automatic archive repair behind one shared ACAMR/Continuous Refresh 12-per-10-minute fallback budget and removes speculative previous-month planner fetches.
- Batches Continuous Refresh persistence/UI/log telemetry by cycle, throttles empty-plan logs and makes high-frequency Task Control rendering null-safe.
- Caps the Member endpoint at 38 seconds and recomputes optional maintenance budgets to retain margin beneath the existing 55-second dispatcher curl ceiling.
- Core 13 / Analytics 6 / catalogue 162 / four-task CRON cadence unchanged.

## v2.9.14 — active convergence, interactive survival and filesystem hardening

- Promotes the Active Convergence & Self-Refresh (ACSR) Pack with Leader/Standby coordination, due-now/scheduled-later diagnostics, operational/canonical convergence and bounded browser-assisted worker pulses.
- Adds P0 Interactive Survival: automatic acquisition is background traffic, one OAuth gateway POST lane is reserved for foreground work, background admission yields to queued foreground work, and Task Control exposes foreground wait/suppression diagnostics.
- Resets fresh real-OAuth transport startup to 30 paced requests/s with connection cap 3, then continues adaptive learning of both rate and cap through the shared server rate authority.
- Hardens filesystem usage with compact updater state backups, bounded caches/runtime ledgers/retention and hosting file-count metrics.
- Corrects Achievements/Hall/Dashboard/Team Insights layout, family folding, progressive bars, challenge criterion hover, action placement, and rejected match/team-point artwork placeholders.
- Core schema remains 13; Analytics remains 6; achievement catalogue remains 162; four-task CRON cadence is unchanged.

## v2.9.13

- Added Core 13 canonical outstanding-work deduplication, merge/promotion and one-generation continuation coalescing.
- Added legacy active backlog compaction and housekeeping recovery.
- Preserved hourly authoritative roster/club-index freshness priority and v2.9.12 transport behavior.

## v2.9.12 — transport/cache corrective release

- Shared one-clock OAuth rate coordinator across pages/frames, hybrid PI/D + hard 429 boundary, endpoint-class latency baselines.
- Removes queued-request timeout/retry collapse, gateway wave bubbles and permanent-4xx false congestion.
- Adds foreground/background fairness, bulk cache reads, batched cache writes and long-lived finished-match cache reuse.
- Removes Match Creation O(N²) feeder scans and large-run progress rescans.
- Validated against a hard 50 req/s fake upstream and a 700-detail Match Creation browser workload.


## v2.9.11 — adaptive authenticated rate convergence

Authenticated traffic is now paced by a learned safe calls-per-second controller with safe/unsafe boundary convergence and endpoint-class latency baselines. Match Creation no longer mistakes inherently slow detail responses for transport congestion. Core remains 12 / Analytics 6; no schema or CRON cadence change.

## v2.9.10
- Site-wide persistent real OAuth readiness, universal opportunistic ingest with provenance separation, hourly authoritative roster/index CRON deadlines, and restored Challenge Assistant active-team context.

- **v2.9.9** — end-to-end real-OAuth throughput audit: exact `oauth=2` propagation, startup auth readiness, deep adaptive batching, and removal of feature-level serial/4/8/12-worker bottlenecks across analyzers, CRON Control, ACAMR, recruitment, challenges, tournaments, Live Ranks and opponent maintenance.
- **v2.9.8** — production `?oauth=2`, server-side adaptive Bearer gateway/throughput telemetry, Core-11 claim-bound ACAMR observations, Insights/Hall/heatmap corrections.

# v2.9.7

- Corrects Member Points Player Matches starvation with bounded fair scheduling.
- Makes authoritative player-match freshness commit mandatory before queue completion.
- Fixes lane-specific Task Control queue diagnostics and adds per-type activity timestamps.
- Hardens Runtime Diagnostics timeout isolation and CRON transport margin.
- Adds persistent protected OAuth POC configuration/installer.
- Restores full Live Rook rendering and adds player-catalogue group progress bars.

# v2.9.5

- Preserves all 133 v2.9.4 achievement keys and adds 29 new achievements across collection depth, rivalries, global opposition, Chess960 specialization, activity continuity, large matches, rating upsets, single-match performance, tournament versatility and rivalry turnaround.
- Freezes the historical v2.9.0 breadth universe to its original 21 categories; later families cannot redefine old breadth unlocks.
- Adds Core schema 10 for opponent country and paired board-rating provenance; Analytics remains schema 6.
- Retains the isolated real Chess.com OAuth POC on `?oauth=2`, plus v2.9.4 Hall and Opponents heatmap corrections.

# v2.9.4 — 2026-08-10

- Corrected achievement lineage preservation: all 128 v2.9.1 catalogue keys remain in their original order, including the shipped legacy `groups-5` / `groups-10` / `groups-15` / `groups-all` entries.
- Added the requested 1/5/10/15/20 distinct-group breadth ladder under five new `breadth-groups-*` keys, producing 133 total achievements without deleting or repurposing a historical key.
- Fixed Hall of Fame unified player search on desktop: the result now spans the full content width, displays Daily/Live/Tournaments/Achievements as four cards on one row, and contains long content without horizontal spill.
- Optimized Insights · Opponents all-match heatmaps with parallel loading, session snapshot reuse, stable 15-minute server caching, compact tuple/dictionary transport, no chronological filesort, and lighter client aggregation.
- Retained the isolated Chess.com OAuth POC1 behind `?oauth=2`, including the local HTTPS/download correction.
- No schema change: Core 9 / Analytics 6.

# v2.9.3 — 2026-08-10

- Moved the single aggregate Opponent Balance heatmap pair into Insights · Opponents, where it was originally requested, and removed the duplicate/wrong Club Intelligence Balance tab.
- Heatmap source population now means all stored non-void matches with boards; paired-board rating coverage explicitly reports which rows are plottable.
- Made achievement breadth strictly additive in the player read path: persisted unlocks are unioned with profile-derived earned achievements instead of suppressing them.
- Added regression invariants for 124 pre-breadth achievements + 5 breadth meta-achievements = 129 catalogue entries.
- No schema change: Core 9 / Analytics 6.

# v2.9.2 — 2026-08-10

- Corrective release audited against the actual v2.9.1 package rather than its overstated release notes.
- Hall of Fame now precedes Insights; Administration deep-link/history state and integrated Admin Tool routing are completed.
- Opponent Balance Analyzer is one aggregate two-heatmap set across all included matches, with shared filters and paired-board rating provenance/coverage.
- Achievement breadth is 1/5/10/15/20; seven high-confidence recovered originals are normalized and mapped; unresolved art uses explicit per-achievement placeholders.
- Monthly-current-month shading, exact maximized-chart scroll restoration, seven-day underfilled warnings, human match URLs, traffic-light health markers and identical-run endpoint retention are corrected.
- Match Creation chart drill-down selectively acquires only missing contributing match detail.
- Durable Work Report separates total, committed, remaining backlog, pending, claimed/running, retry-waiting and failed states.
- Core schema 9 / Analytics schema 6 add nullable `rated_board_count`; no reset or re-seed.
- Full Python and browser regression gates pass without page errors.

# v2.8.11

- Reworked Member Points convergence into a sustainable due-aware service: 10-minute Player lane, 7-day bulk player-match freshness, 3-day stats freshness, successful-check timestamps for unrated members, migration reuse of existing `rating_updated_at` evidence for stats freshness, and a bounded 75-item Player ceiling under the existing execution-time budget.
- Fixed ACAMR roster starvation by rotating across the full member-id space, issuing multiple distinct due-aware claims per pulse, and requesting only the work each member actually needs.
- Hardened opportunistic browser observations: accepted/queued server work is measured separately from failed delivery; transient sends retry once; browser observations remain non-authoritative.
- Task Control now reports Member Points convergence from authoritative match/stats freshness instead of lifetime recurring queue ratios.
- Match Insights lazy sections render independently so later partial responses cannot erase already-rendered charts.
- Unified graph containment/touch behavior: mobile tap values, pinch zoom on zoomable native/Team charts, today treated as future/incomplete, and hide/show series for Daily Boards and Club Points.
- Improved Opponent Insights readability, name-then-logo layout and persistent canonical-alias icon repair for legacy/non-ASCII opponent names.
- Removed Member Intelligence presentation from Dashboard/Profile while retaining its backend data for Recruitment/Admin/internal consumers; Achievement Challenges remain.
- Player achievement catalogue adds non-zero metric progress, removes ownership counts from player cards, and Profile achievements are newest-first.
- Repaired the physically truncated Live Rook source artwork and regenerated its derivatives; full-decode asset regression added.
- Hall overall search is full-width with four desktop cards; redundant search Clear controls are suppressed and Search stays inline on mobile.
- Dashboard Admin Analyze opens P2K-scoped One Match Analyzer; standalone analyzer no longer shows an Open standalone button.
- Core schema 8 / Analytics schema 5. Player CRON cadence changes from 30 minutes to 10 minutes; Club 5 minutes, Tournament 10 minutes and Match Tracking hourly remain.

# v2.8.10

- Canonical score-derived match outcomes and real Opponent Intelligence W/D/L repair, with semantic win/draw/loss/void fixtures.
- Dashboard Core/all-history source-of-truth protection without changing the stable v2.8.9 startup/auth graph.
- Tournament/Match Tracking action-header, task-key, display/state-recovery and Task Control fixes.
- Tournament status maintenance on every 10-minute invocation, structured freshness parsing and recovery-aware BrowseIndex signatures.
- Non-destructive runtime packaging; no shipped live secrets/tournament archive/tracking registry.
- Secretless runtime-token CRON dispatcher, token recovery/automatic secure generation, atomic installer and shell/HTTP diagnostics.
- Expanded Club Intelligence freshness/activity/contribution/opponent/snapshot/telemetry surfaces.
- Removed duplicate personalized-home requests/full-roster single-player lookup and aligned outer-router admin recognition with Dashboard behavior.
- Core schema 7 / Analytics schema 5; no reset/reseed; standard CRON schedules unchanged.

# v2.8.9

- Fixed the production-confirmed v2.8.7 Dashboard startup regression: startup no longer references the lazy-module-owned `integratedFrameIds`; integrated frames are discovered from the DOM.
- Restored the missing `clubMembersAPI` dependency required by the v2.8.6 Dashboard second-wave loader.
- Retained immediate configured/local admin recognition and embedded Administration race/retry hardening.
- Re-enabled ACAMR after browser reproduction proved the startup failure occurs before ACAMR participates.
- Retained the complete v2.8.8 feature/fix set, Core 6 / Analytics 5 and unchanged CRON cadence.
- Added a browser startup/ACAMR execution gate to release validation.

## v2.8.8.3 — 2026-08-08

- Confirmed Dashboard data regression boundary at v2.8.7.
- Restored v2.8.6 Dashboard data loading and public Dashboard repository contract.
- Restored pre-ACAMR normal API-client observation behavior.
- Disabled ACAMR client loading/planning and rejected stale ACAMR observations.
- Retained independent v2.8.8.x correctness/UI/Intelligence fixes.

## v2.8.8.2 — 2026-08-08
- Fixed the Dashboard startup regression first introduced in v2.8.7: public Dashboard/read-metadata GETs are read-only again and no longer create Core state rows.
- Restored the first Dashboard historical KPI read to the materialized Analytics club-total projection while retaining authoritative Core roster freshness.
- Detached the initial Team Points database request from the automatic current/Live second wave so a delayed PHP/DB request cannot freeze Dashboard startup.
- Retains all v2.8.8.1 admin, modal, rank-image, Opponent Intelligence and Team Insights fixes.


## v2.8.8.1 — 2026-08-08
- Startup/Admin loading reliability hotfix with bounded DB/lazy-frame retries and immediate configured-admin recognition.
- Unified Profile nested-modal async isolation and rank-image clipping fix.
- Opponent Intelligence W/D/L corrected from canonical match summaries with one forced Analytics logic rebuild.
- Team Insights adds all-history Club Points score progression through today + six-month Low/Medium/High projection.

## v2.8.8 — Club Intelligence, Hall/Profile fixes and opponent logos

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

# v2.8.6 — 2026-08-08

- Made the public Dashboard first-screen path materialized/local and moved current Chess.com, Live and Match Assistant work to an automatic post-paint second wave.
- Added progressive/viewport loading for Team, Member, Match and Opponent Insights, including table-only pagination requests.
- Server-paged Tournament ranking/list views at 25 rows; player tournament history is fetched only when opened.
- Achievement wall now renders the first 12 players before catalogue/tournament/avatar/medal enrichment; catalogue is click-loaded.
- Daily and Live Hall rank summaries no longer transmit every detailed player initially; expanded ranks page 25 members at a time.
- Added one-request unified Hall search and progressive Profile tournament/avatar enrichment; modal Profile mode skips hidden detail queries.
- Public materialized reads honor browser/ETag caching; shared progressive loader adds viewport scheduling, two-request low-priority concurrency and small idle prefetches.
- Split the native Insights chart engine from the initial Dashboard bundle and load it on first chart use.
- Core schema 5 / Analytics schema 5 unchanged; no DB migration and no CRON cadence change.

# v2.8.5 — 2026-08-08

- Guaranteed time-bucketed Club match-index discovery every five-minute Club CRON invocation, even inside long jobs.
- Added independent 30-minute lightweight roster freshness and server-verified opportunistic roster/profile/rating refresh triggering.
- Moved urgent Club work ahead of Analytics and removed read-time Analytics/achievement rebuilds from public pages.
- Added Core generation state, persistent avatar/profile snapshots, SQL-paginated materialized Profile/Achievement/Members reads, response-cache locking/cleanup and cheaper gateway cache status.
- Staggered Tournament CRON to 10 minutes and added public tournament ETag/Last-Modified caching.
- Excluded authoritative finished 0–0 void matches from all public analytics/graphs and participation achievements while retaining raw records.
- Core schema 5 / Analytics schema 5; additive migration only.

# v2.8.4 — 2026-08-07

- Split Team Points into fast Club Points (5-minute) and lower-priority Player Points (30-minute) CRON lanes with independent jobs/locks/health.
- Fixed board parsing for both Chess.com `games` and `game` forms and string/object player payloads.
- Added one-time routing/recovery of pre-fix failed board tasks and stricter synchronization completion/coverage reporting.
- Added opportunistic browser-to-server Chess.com observations, routed by Club/Player urgency without trusting client-derived points.
- Integrated tournament-achievement date Revision 2: no synthetic period-start dates; exact finish date or pending refresh.
- Achievement cards sort by achievement count; Achievements is the first Hall of Fame tab.
- Podium ranking is the first Tournament tab and medal displays use 🥇 🥈 🥉.
- Core schema 4 / Analytics schema 5 unchanged.

# v2.8.3 — 2026-08-07

- v2.8.2 Hotfix 1 + Hotfix 2 are included in the baseline.
- Achievement counts replace Live rank on achievement player cards; earned details add dates and event provenance.
- Tournament medal dates use authoritative tournament finish time and the integrated medalist modal is viewport-aware.
- Linked dashboard cards share the Priority Calls hover/focus treatment.
- Club Points forecast now uses the latest three 30-day actual point blocks with a near-linear capped trend.
- Reworked Most played opponents and Monthly match activity containment.
- Added monthly average boards per match and future boundary on monthly active members.
- Core schema 4 unchanged; Analytics schema 5 adds achievement provenance.

# v2.8.2 — 2026-08-07

- Completed the 24-point dashboard, administration, monitoring, tournament, Insights, profile, Hall-of-Fame and artwork acceptance checklist.
- Added durable match first-discovery time and match maximum-rating dimensions; Core/Analytics schema revision is 4.
- Restored the tracked-match explorer inside CRON-compatible Match monitoring.
- Unified mobile widths/gutters across integrated tabs while preserving desktop layouts.
- Replaced 56 original 1254×1254 rank/achievement PNGs in place with 640×640 versions and resolved orphan/missing artwork mappings.
- Added a dedicated v2.8.2 regression suite covering all 24 requested points.

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

# Changelog

## 2.7.2 — 2026-08-06

- Rebuilt Team Insights on authoritative match/game timestamps and durable daily facts.
- Corrected 30-calendar-day rolling calculations and duration analytics.
- Added hover, legends and range zoom to time-series Insights charts.
- Added match-size, league/friendly, duration, rules and time-control visualizations.
- Added active-member and Daily-rank charts.
- Added opponent treemap, compact desktop table and admin plain-text result copy.
- Added schema 13 and Seed Importer v1.2.0 with stored match dimensions.
- CRON endpoints and cadence unchanged.

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

- Changed match monitoring to an hourly scheduler tick with per-match adaptive due times.
- Samples matches hourly during their first 24 hours, every 12 hours normally, every 6 hours inside 96 hours of start, and hourly inside 48 hours.
- Added due/deferred and sampling-phase reporting to unified maintenance.
- Omitted consecutive graph points for identical registered players and ratings while preserving every archive record.
- Added rating-change rows to lineup-evolution details.
- Preserved the existing match-tracking endpoint, token, archive and follow-state compatibility.

# Promote to King v2.6.1 — 2026-08-06

- Added a shared MariaDB-backed Chess.com API gateway used by Team Points, tournament maintenance and league match tracking.
- Added a unified server-side task registry, per-task execution locks, health states, runs and logs.
- Added `TaskControl.html` with Start/Resume, safe Pause, Refresh, gateway health, work reports and unified logs.
- Preserved the existing Team Points, tournament and match-tracking CRON URLs and legacy administration actions through compatibility adapters.
- Moved Team Points execution ownership to the server; the browser can request one bounded compatibility segment but no longer owns an endless processing loop.
- Corrected the hosting timeout contract to a 48-second endpoint target and 35-second Worker segment.
- Added deadline-aware resumable checkpoints for historical match-ID discovery, league-match tracking and tournament batches.
- Added automatic MariaDB/schema/gateway/task-registry initialization during secured administrator login, including a bounded verified-cache fallback during temporary Chess.com outages.
- Added centralized false-404 protection, global request serialization, shared caching, ETag/Last-Modified revalidation and health probing.
- Set health expectations to Team Points every 5 minutes, league match tracking every 6 hours and tournaments every 24 hours.
- Made dashboard System Health rows link directly to the relevant task or gateway maintenance section.
- Upgraded the additive database schema to version 11.

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

# Promote to King changelog

## 2.5.1 — 2026-08-05

- Released the database/API-backed Insights work as standalone version 2.5.1.
- Connected Team, Matches and Opponents Insights to public Team Points database endpoints, including server-side filtering, sorting and pagination where relevant.
- Renamed dashboard rank actions and Live-team metrics, and aligned both rank buttons with Hall of Fame player focusing.
- Standardized public terminology on MCA and retained CSV wording only for administrative MCA source-data operations.
- Updated runtime identifiers, cache-busting parameters, manifests, deployment guides and validation tests from 2.5.0 to 2.5.1.
- Fixed Find more matches by promoting the hidden recommendation-engine iframe to the visible Match Assistant class before opening it.

## 2.5.0 — 2026-08-05

- Connected Team, Matches and Opponents Insights to the public database API; opponent search, filters, sorting and pagination now execute server-side.
- Updated Daily/Live rank navigation so both buttons open Hall of Fame on the correct page and focus the logged-in player.
- Revised public MCA wording: Arenas played, Unique players and Total arena points; removed total participations from the team card.
- Corrected complete dashboard Team data loading.
- Added unified Daily/Live player and team card modes with arena placement and team metrics.
- Added schema 10 Live placement/per-arena aggregates and fixed schema-9-to-10 detection.
- Added persistent resumable issue-only database audits and arithmetic repair batches.
- Added manual tournament entry, 50-row pagination and Hall tournament/podium deep links.
- Standardized English UI formatting with browser-local timezone.

# Promote to King v2.4.23

## 2.4.23 — 2026-08-05

- Removed the dashboard-wide progress bar and retained section-level loading/error states.
- Replaced the separate recommendation/full Match Assistant frames with one reusable frame and a final-layout readiness handshake.
- Added SQL filtering, sorting and pagination to Match Insights.
- Compacted the public Live-ranks payload and added a focused public player endpoint.
- Added a Daily/Live toggle to the player card and schema-9 optional arena metrics.
- Corrected CSV parsing so “Most wins” is treated as a per-arena maximum rather than a total-wins column.
- Added the server-validated red administrator priority/action card without a second match scan.
- Added an off-by-default simulated-profile toggle for sequential versus adaptive concurrent Chess.com API access.
- HTTP 429 now halves adaptive concurrency down to one, honors Retry-After and recovers gradually after successful requests.

# Promote to King v2.4.23

- Extracted one shared rank-ladder component used by both Daily ranks and Live ranks.
- Live ranks now use exactly the same summary layout, collapsed cards, expanded framed panel, member table, keyboard/button behavior, and URL-backed selection as Daily ranks.
- Replaced the Live-rank summary with Current members, Ranked members, Team leader, and Your position.
- Public Live ranks list current Promote to King members only; former, closed, and possible-renamed accounts remain available in Administration for correction.
- Live-rank collapsed icons use the existing 192 px frameless WebP thumbnails and expanded panels use the 640 px framed WebP thumbnails, with full PNG files only as error fallbacks.
- Updated the standalone Live ranks page to use the same shared component and styles as UI v2.
- No database schema or CRON endpoint changes.

# v2.4.21

- Audited and repaired tournament podium resolution. Player-tournament URLs now accept API and www forms, round-level players are included, and final-group standings provide a safe fallback.
- Replaced quota-heavy persistent tournament response caching with bounded in-memory caching and compact cursor-only repair checkpoints.
- Added a podium-only repair action for already discovered finished tournaments.
- Live ranks now use the same collapsed/expanded interaction as Daily ranks: frameless piece icon while closed, framed rank panel and full player table when opened.
- Live-rank CSV usernames are matched case-insensitively against the lower-case Chess.com roster while retaining a stable display name.
- Made final-group points and tie-break standings the primary podium source and preserved multiple medalists sharing the same score/tie-break.
- Added explicit unresolved-podium reporting and cache-busted the embedded Tournament Management page.

# Promote to King v2.4.21

- Consolidated v2.4.19.1 and v2.4.19.2 Team Points routing fixes into the main baseline.
- Added v2.4.19.3 large-pack Live-rank CSV uploads as sequential ten-file batches with retry and omission detection.
- Updated package/cache identifiers and deployment guides to v2.4.21.
- No database schema or CRON endpoint changes.

> **v2.4.19.2 hotfix:** fixes same-origin Team Points API routing in UI v2. Matches, Opponents, Live ranks and Hall-wide searches now use authenticated same-origin fetch requests; the restricted PubAPI client remains reserved for Chess.com.

> **v2.4.19.1 hotfix:** consolidates Matches, Opponents and Live ranks through the established Team Points public endpoint; Live ranks now render natively in UI v2.

# Promote to King v2.4.19

## UI v2
- Corrected authoritative finished-match help text.
- Persisted public/admin navigation, subtabs, searches, filters, sorting, pagination, date ranges, rank selection, and Match Assistant state in URL history.
- Limited the global loader to essential team/database data.
- Calculated Administration tool counts dynamically.

## Native Insights
- Activated the database-backed Matches subtab.
- Migrated Opponents from iframe integration to a native UI v2 panel.
- Added a reusable accessible shared table component for both pages.

## Hall of Fame
- Added one search across Daily ranks, Live ranks, and tournament podiums.
- Added a separate tournament-achievement badge proposal demo.

## Demos
- Added a standalone representative UI v2 demo using fake static data.

# Promote to King v2.4.18

## Opponent insights
- Activated the Opponents Insights subtab.
- Added database-backed summary cards and searchable/sortable opponent statistics.
- Added canonical opponent/alias metadata, serial name refresh, rename/disabled detection, bulk apply, and CSV export.

## Live ranks
- Activated Live ranks in Hall of Fame with six thresholds and matching framed artwork/miniatures.
- Added CSV pack selection/drop, protected storage, filename-based replacement, checksums, and full-pack aggregation.
- Added current-member and account-state classification without blocking on possible renamed usernames.

## Team Points discovery
- Added the club match endpoint as a first discovery source.
- Added resumable bounded raw numeric match-ID scans for forward gaps and historical backfill.
- Added authoritative match-detail ingestion for opponent metadata and former-member boards.
- Preserved mandatory authoritative finished status and the two DB audit/correction tools.

## Retained platform work
- Preserved URL page/subtab state, full tournament/podium reinitialization, Match Assistant state fixes, database graphs, and RetryableException autoloading.

# Promote to King v2.4.16

## Tournament archive
- Restored exact title-derived discovery from 2024 onward for monthly Top 10/32, quarterly rank and Christmas tournaments.
- Moved full manual discovery and medalist validation to the browser.
- Added normalized `client-import` persistence and removed the legacy long-running PHP manual mode.
- Kept CRON discovery server-side in bounded candidate and status batches; full podium work remains in the browser manual scan.
- Protected discovery cursors from cancelled or partial browser scans.

## Match Assistant
- Prevented iframe reparenting/reload when opening the full assistant.
- Removed the intermediate recommendation-engine appearance.
- Restored recommendations from the cached DOM without rescanning or restarting dashboard progress.

## Team Insights
- Generates chart-ready date series from the Team Points database endpoint.
- Uses a separate right-hand scale for in-progress matches.
- Uses actual database dates in yearly-comparison hover information and no longer exposes a synthetic year 2000.

## Reliability
- Includes the `RetryableException.php` Team Points autoload fix.

# Promote to King 2.4.15

- Integrated the Team Insights Team subtab with database-backed activity, outcome, board-size and competition-point charts.
- Added the public tournament archive and podium ranking with sortable columns and medalist detail modal.
- Added Tournament management to Administration, incremental discovery, status refresh CRON and manual fair-play/abuse medalist validation.
- Unified match, Team Points database and tournament runs in the Scheduled Tasks log with task-type filtering.
- Removed the Match Assistant view jump and restored recommendations from cached DOM state without rescanning.
- Preserved the Team Points `RetryableException.php` autoload hotfix.

# Changelog

## 2.4.14 — 2026-08-04

- Smoothed the dashboard loading indicator with weighted endpoint progress and live Match Assistant analysis counts.
- Added the P2K club join date below the authenticated member name.
- Refined Hall of Fame navigation, labels, rank expansion scrolling and ranked-member summary.
- Standardized the three dashboard panel titles and increased data-vignette subtext readability.
- Added shared blue hover/click information controls to Team Points, club points, finished-match totals and readiness gauges.
- Renamed the recommendation panel and simplified its explanatory copy.
- Replaced the Hall of Fame tab trophy with a star and removed the public Classic UI link.

## 2.4.13 — 2026-08-04

- Added icons to the Dashboard, Team insights, Hall of Fame and Tournaments tabs.
- Added purpose-sized WebP rank-image derivatives for card, ladder and expanded Hall of Fame usage.
- Retained all original 1254×1254 PNG rank assets for archival and full-resolution fallback.
- Removed the forced smooth scroll and vertical translate during public-tab changes to reduce visible layout jumps.

## 2.4.12 — 2026-08-04

- Corrected Pawn to begin at 10 Team Points; members below 10 points remain unranked.
- Replaced the dashboard recommendation orange information control with the shared blue UI v1 information popover.

- Removed administrator controls from initial UI v2 markup and create them only after server-verified club-admin authentication.
- Kept all protected administrator operations behind the HttpOnly session and CSRF checks.
- Replaced Suggested additions with top-level full-page Dashboard, Team insights, Hall of Fame and Tournaments tabs.
- Made Hall of Fame opening from the player vignette focus and highlight the authenticated player automatically.
- Added schema-v5 immutable match summaries and constant-time club totals.
- Made duplicate point-event discovery preserve previously stored result codes and points.
- Added bounded worker-driven historical summary backfill and an optional supporting event index.
- Preserved all existing jobs, queue payloads, CRON state and configuration.

## 2.4.11 — 2026-08-04

- Restored spacing below the player/team dashboard row.
- Fixed Diamond King range fallback and long-description overflow.
- Moved embedded Match Assistant detailed analysis to a visible parent-shell modal in both UI variants.
- Made the detailed-analysis page accessible as a public Match Assistant drill-down.
- Reworked public Team Points reads to use legacy-compatible participation and event data.
- Added a safe single-club stored-slug fallback to prevent false zero dashboards.
- Restricted the UI v2 Admin toggle to live Chess.com club-profile administrator verification.

## 2.4.10 — 2026-08-04

- Aligned the Public UI v2 player and team panels and simplified the dashboard header/copy.
- Moved the Public/Admin switch beside the avatar and hid the completed loading indicator.
- Preferred the authoritative club profile member total over potentially truncated members arrays.
- Expanded Hall of Fame framed artwork and rank details.
- Reused the loaded Match Assistant results in a full-width, borderless, headerless embedded view.
- Added DB-backed finished match/board/game totals.
- Corrected club competition points to 5 × boards for team wins and 2 × boards for team draws.
- Added a backwards-compatible front-of-queue team-member and member-match discovery action.

## 2.4.5 — 2026-08-03

- Added a database-backed UI v2 Hall of Fame with all 16 Team Points ranks ordered from Diamond King to Pawn.
- Rank categories expand in place, switch from frameless to larger framed artwork and show members ordered by points with category/team positions and match/game/W-D-L statistics.
- Added member search that collapses the rank ladder to the matching category and highlights the member.
- Added the signed-in player's overall team position to the Team Points vignette.
- Embedded the Match Assistant in the recommendation panel only after Find more matches is clicked; the original first five recommendations remain the default.
- Retained automatic secured Team Points access, diagnostics alignment, live dashboard statistics, continuous CRON, board rediscovery and legacy queue/schema compatibility.

## 2.4.3 — 2026-08-03

- Changed Team Points CRON from a six-hour/fixed external cadence to a lease-protected self-scheduling chain.
- Each CRON execution runs serial bounded worker segments for up to about 285 seconds, then invokes itself 60 seconds after completion.
- Added additive schema v4 `p2k_tp_cron_state` to prevent duplicate continuous chains without changing existing jobs or queue payloads.
- Preserved manual/CRON advisory locking, per-item commits, safe pause, retries and board rediscovery.


## 2.4.2 — 2026-08-03

- Added persistent board-state classification to Team Points: complete/immutable, potentially incomplete, failed/malformed, recent/in-progress, and newly discovered.
- Skips Chess.com board requests permanently once two finished game events are stored for that member and board.
- Rechecks one-game boards, failed/malformed boards, and recent/in-progress boards according to configurable schedules.
- Added database-backed rediscovery so due boards are retried even after they disappear from Chess.com's rolling player match lists or the member leaves the current roster.
- Added an additive schema-v3 migration and automatic in-place upgrade for existing installations.
- Preserved existing jobs, queue rows, queue item types, item keys, and legacy `sync_board` payloads.
- Uses the board-state row as the fast lookup path and avoids repeated board-wide event searches.

## 2.4.1 — 2026-08-03

- Corrected the release version after the dashboard and Team Points maintenance build.
- Dashboard recommendations use the Match Assistant's exact default pipeline and show its first five ranked results for the authenticated player.
- Restored the v1-style login/avatar and Log off controls in Public UI v2.
- Compacted dashboard recommendation cards and added rating-range and rules tags plus an information control for justification.
- Team Points now combines player `finished` and `in_progress` match lists with board URL deduplication.

## 2.4.0 — 2026-08-03

- Added an optional responsive Public/Admin dashboard selected strictly with `?ui=v2`; missing `ui` and `?ui=v1` continue into the previous interface.
- Reused the existing branding, OAuth session, configured-club administrator verification, API client and current administrator tool routes.
- Added automatic public club indicators and five current match recommendations, with the Match Assistant retained for full recommendation analysis.
- Added 32 team-rank PNG assets under `assets/images/ranks/` for future use.

## 2.3.1 — 2026-08-03

- Integrated Team Points as a normal administrator-only website tool in the complete package and advanced all cache-busting/runtime metadata from 2.3.0 to 2.3.1.
- Replaced the single manual batch action with **Run update continuously**. The browser automatically chains bounded server invocations until completion, safe pause or failure while keeping every PHP request below the configured hosting time budget.
- Added final safe-pause acknowledgement when a stop request arrives between bounded invocations.
- Added partial member-name search to totals, monthly rows, individual event previews and CSV export.
- Added clickable sortable result columns with ascending/descending database ordering.
- Added persistent per-job process logs covering member synchronization, player-match discovery, board/game processing, retries, failures, waiting and completion.
- Added process overview, current task/item, queue breakdown, next retry, API-cache size, worker history and per-task discovery statistics.
- Advanced the Team Points MariaDB schema to version 2 with `p2k_tp_job_logs`; the installer is idempotent and can be rerun to upgrade an existing v2.3.0 installation.
- Updated the Python/SQLite simulator, PHP backend, tests, setup documentation and complete user manual for the v2.3.1 workflow.


## 2.3.0 — 2026-08-02

- Renamed recruitment-change details to **Lineup evolution** and added Previous/Next navigation through every stored recording of the same match, in both analyzer graphs and Match Data Management.
- Forces the configured club's modeled win probability to **0%** whenever its eligible lineup does not meet the match minimum-player requirement.
- Added **New challenge / Rematch opportunities** modes to Challenge recommendation. Rematch mode scans the configured club's available finished-match summaries chronologically from a selected historical match, extracts unique opponents, and evaluates them with the same challenge criteria.
- Added task type, task ID, unique run ID, start/end timestamps, stored match IDs, failed match IDs, and run details to scheduled/manual tracking logs. Existing entries without identifiers remain readable as legacy tracking records.
- Added Scheduled Tasks filtering by task type.
- Stops queued API work and synchronized background refresh when an embedded tool loses focus. Hidden tools are not preloaded.
- Blocks administration-only standalone pages unless the explicit `admin` flag is present or a currently OAuth-enabled logged-in user is verified against the configured club's administrator list.
- Ignores previously stored OAuth sessions for automatic administration access when the OAuth flag is not currently enabled.

## 2.2.8 — 2026-07-31

- Moved the Upcoming Matches analysis status and progress block directly below **Analyze** and above the **Matches / Players** subtabs, matching the Match Creation layout.
- The progress indicator remains visible when switching between Upcoming Matches subtabs during or after analysis.

## 2.2.8

- Displayed Scheduled Tasks timestamps as human-readable UTC dates and times.
- Automatically enables administration features for an authenticated user listed as an administrator by the configured club profile endpoint; the `admin` URL flag remains supported.
- Moved the Upcoming Matches **Analyze** button above the Matches/Players subtabs.
- Added failure-detail modals with exact match/API call, error category, HTTP status, message, and plain-text copy for analyzer failures.
- Added sortable Upcoming Players column headers and per-player registered-match drill-down.
- Split player timeout reporting into Classical and Chess960 timeout columns. Match-sheet timeout values are treated as percentage points (`1` = `1%`).

## 2.2.6 — 2026-07-31

- Added **Matches** and **Players** subtabs to Upcoming Matches.
- Added an aggregated Promote to King player list using only the already loaded match sheets, with separate Classical and Chess960 rating observations, timeout rate, registration counts, search, and sorting.
- Added an information mark and **View larger** action to the recruitment tracking graph.
- The enlarged tracking graph retains clickable archive intervals and player-change details.
- Highlighted only the newest Challenge Assistant recommendation batch in green; requesting more recommendations restores previous cards to the normal amber treatment.
- When Recruitment Assistant is embedded from the index, it recruits only for the club configured in `config/site-branding.js` and hides the team selector. Direct standalone use still permits team selection.
- Added configurable `clubSlug` and `clubUrl` values to the branding file.
- Made the main header logo link to the configured club's Chess.com page.

## 2.2.5 — 2026-07-31

- Added `config/site-branding.js` as the single editable source for the main title, subtitle, logo path, and logo alternative text.
- Restored **Promote to King** as the default main title.
- Added the default subtitle: “Play together. Improve together. Promote to King.”
- Applies configured branding to the visible header, browser title, metadata description, favicon, logo accessibility text, and navigation label.
- Retained matching Promote to King values in `index.html` as a no-script fallback.
- Replaced the red circular × on Match creation chart match lists with the same compact amber **Close** button treatment used by other project chart modals.
- Preserved the v2.1.2 site shell and all v2.2.4 behavior.

## 2.2.4 — 2026-07-31

- Renamed the overall website to **Chess Pursuit**.
- Updated the site description to: “A suite of tools for chess players and clubs, built around the pursuit of improvement, competition, and mastery.”
- Limited the non-administration index to the **Match Assistant** tab only; the complete tab set is restored when the `admin` parameter is enabled.
- Prevented hidden administration tools from being preloaded in public mode.
- Made every non-empty bar in both Match creation charts selectable by mouse or keyboard.
- Added a chart-contained overlay listing the exact matches represented by a selected registration or started-date bucket.
- Included match title, opponent, status, UTC start time, board count, and a direct Chess.com match link.
- Preserved the v2.1.2 site shell and existing match-tracking functionality.

## 2.2.3 — 2026-07-31

- Restored recruitment-change details as a compact overlay over the tracking graph rather than a panel below it.
- Kept the overlay positioned inside the graph container, avoiding viewport-centered or iframe-level placement.
- Increased table typography for desktop and handheld readability while reducing the maximum panel width from 700 px to 560 px.
- Tightened responsive column proportions and spacing so the required two-team layout fits narrow phone screens.
- Added **Copy text** to copy the displayed teams, signed player additions/removals, archived ratings, timeout rates, and totals as plain text.
- Preserved the original v2.1.2 site shell and `assets/css/site.css` unchanged.

## 2.2.2 — 2026-07-31

- Anchored recruitment-change details directly below their tracking graph instead of centering a full-screen overlay inside the analyzer iframe.
- Applied the same graph-local behavior to Match data management.
- Reduced dialog width, padding, table row height, headers, totals, and maximum player-list height for handheld displays.
- Preserved the required two-team columns while allowing long player names to truncate safely rather than widening the popup offscreen.
- Added automatic nearest scrolling when the graph-local panel opens outside the visible portion of the page.
- Kept the original v2.1.2 site shell and `assets/css/site.css` unchanged.

## 2.2.1 — 2026-07-31

- Restored **Record data now** inside Match data management with the v2.1.2 wording, button treatment, modal geometry, and per-match progress.
- Refreshes the visible Match Assistant or Scheduled tasks log every time Logs or its subtab is accessed.
- Added a dedicated Refresh button to each log subtab.
- Added a transparent migration from v2.1.2 `data/match-history` snapshots and the previous follow registry into `data/match-tracking`.
- Removes successfully converted legacy files and registry after verifying the unified copies; invalid files are quarantined.
- Reconstructs migrated archived matches as followed unless they were explicitly unfollowed.
- Allows an archived match to be followed again even when Chess.com now returns 404; follow state is retained and the missing fresh snapshot is reported as a warning.
- Keeps the original v2.1.2 `site.css` unchanged.

## 2.2.0 — 2026-07-31

### Fresh v2.1.2-based implementation

- Preserved the exact v2.1.2 site shell and `assets/css/site.css`, including the dark background and borderless transparent embedded frames.
- Fixed Scheduled Tasks logs so null or empty payloads safely produce an empty entries list.
- Added a manual-recording progress bar and split recording into summary discovery followed by per-match capture.
- Prefiltered registered match summaries by league acronym before requesting full Chess.com match data; explicitly followed active matches remain included.
- Grouped Match Assistant and Scheduled Tasks under separate subtabs of **Logs**.
- Renamed **Challenge List** to **Challenge Assistant** and placed **Challenge recommendation** first.
- Added Match data management search, status/follow filters, size/date/name sorting, add-and-capture, follow again, confirmed unfollow, per-match archive deletion, and confirmed finished-data cleanup.
- Added tracking-graph buttons and clickable archive points with compact two-column player additions/removals, profile links, archived ratings, timeout rates, and added/removed/balance totals.
- Implemented matching local Python and online PHP endpoints with a persistent follow registry.

## 2.1.2 — 2026-07-30

### Scheduled task logs

- Added one protected JSONL execution log per UTC day for authorized cron and manual match-history recording runs.
- Recorded UTC start/end timestamps, duration, trigger type, success/partial/error status, and recorded/skipped/failed match counts.
- Added fatal-error logging while excluding invalid cron-token attempts from execution history.
- Added the Administration **Scheduled tasks** tab with UTC date-range, errors-only, and scheduled/manual entry-type filters.
- Added matching Python-local and PHP-online read APIs at `/api/scheduled-task-logs/`.

## 2.1.1 — 2026-07-30

### Match data management

- Renamed the Administration tab from **Match data cleanup** to **Match data management**.
- Added **Record data now**, which invokes the same league-match snapshot routine as the scheduled cron without exposing the cron token to the browser.
- Added same-origin manual tracking support to both the local Python server and online PHP backend.
- Refreshes the tracked-match inventory after a manual recording and reports stored, skipped, and failed match counts.
- Stabilized the Administration dialog height and anchored it to the top of the viewport so tab changes no longer recenter it; any unavoidable growth extends downward.

## 2.1.0 — 2026-07-30

### League-match history and Administration

- Added protected Python and PHP HTTP GET cron endpoints that snapshot every registered Promote to King league match with a server-generated UTC timestamp.
- Added per-match immutable storage under `data/match-history/` and read-only client history aggregation.
- Added conditional registration-history charts below the rating-bracket chart, using existing P2K/opponent colors, minimum-player reference, P2K win probability, and hover/tap values.
- Kept the chart completely absent when no usable tracking data exists.
- Renamed the admin modal to Administration and split it into Logs, Diagnostics, and Match data cleanup tabs.
- Added tracked-match inventory, file counts, upcoming/started state, and confirmed deletion of all snapshots for one match.
- Added IONOS cron setup and token-rotation documentation.
- Updated Upcoming model version to `UA-45` and added match-history model `MH-1`.

## 2.0.12 — 2026-07-30

### Administration visibility and retained-tab presentation

- Added the `admin` URL flag for the Diagnostics button, Match Recruitment tab, and Challenge List tab.
- Kept administration-only controls hidden by default so they do not flash before JavaScript initializes.
- Moved Match Assistant usage logs above the runtime diagnostic cards.
- Linked logged usernames to their Chess.com profile pages in user totals and matching entries.
- Kept newly loading tool frames hidden until the embedded presentation override is installed, removing the standalone-border flash.
- Removed release query parameters from Ctrl-click and middle-click standalone tab navigation while retaining versioned internal frames and assets.

## 2.0.11 — 2026-07-30

- Added a packaged PHP implementation of the Challenge List default-storage and Match Assistant usage-log APIs for online Apache/PHP hosting.
- Kept the exact same extensionless API contract as the local Python launcher.
- Added endpoint directories so online hosting does not depend on frontend PHP filenames.
- Protected raw `data/` and `logs/` content with packaged Apache rules.
- Reworded server-unavailable errors to cover both incomplete online deployment and local static-only serving.
- Added online deployment instructions and PHP backend regression tests.

## 2.0.10 — 2026-07-30

### Online mixed-version asset fix

- Fixed the online Match Assistant failure `P2K_API_CLIENT.prioritizeMatchReferences is not a function` caused by a newer page controller being combined with an older cached API client.
- Moved league-first match/reference ordering into the transport-independent `assets/js/shared/match-priority.js` module.
- Kept backwards-compatible ordering methods on `P2K_API_CLIENT` while removing direct ordering dependencies from Match Assistant, Upcoming Matches, and Match Creation.
- Added release-version query tokens to every local JavaScript and stylesheet reference and to retained/standalone tool-page navigation.
- Added Apache cache headers that keep HTML release shells fresh while allowing safely versioned JavaScript and CSS to be cached.
- Added regression checks for independent match ordering, obsolete-client compatibility, and complete asset versioning.

## 2.0.9 — 2026-07-30

### Match Assistant usage logging

- Added transparent server-side logging for completed user-initiated Match Assistant analyses.
- Stored one JSON-lines file per UTC calendar day under `logs/match-assistant/`.
- Logged the server-generated UTC timestamp, canonical username, and number of matches found under the active filters.
- Excluded synchronized background refreshes, cancelled runs, and failed validations from usage logging.
- Added a Diagnostics log explorer with a default seven-day UTC aggregate, date and username filters, seven-day history expansion, daily totals, user totals, matching entries, and distinct-username counts.
- Protected log files from direct static access and restricted remote log exploration by default.

## 2.0.8 — 2026-07-30

### Challenge club-slug validation

- Relaxed browser and Python server validation so valid Chess.com club slugs may begin or end with hyphens.
- Continued to allow only lowercase ASCII letters, digits, and hyphens, with at least one letter or digit and a 128-character limit.
- Added regression coverage for `-persian-gulf-forever`, trailing hyphens, duplicate removal, and all-hyphen rejection.
- Updated the Challenge validation model identifier to `CL-4`.

## 2.0.7 — 2026-07-30

### Challenge List server default

- Upgraded `serve_local.py` into a dependency-free static and JSON application server.
- Added `GET` and `PUT /api/challenge-club-list` for one ordered shared default list.
- Added automatic loading into untouched Challenge Assistant inputs and active-tab save/load controls.
- Added ordered slug normalization, duplicate removal, revision conflict protection, atomic writes, and one-file backup.
- Restricted writes to same-origin loopback clients by default and blocked direct static access to the stored JSON.
- Kept all Challenge Assistant tools functional when the storage API is unavailable.
- Added end-to-end Python tests for read, save, conflict, validation, and protected storage behavior.

## 2.0.6 — 2026-07-30

### Match Recruitment Assistant runtime fix

- Renamed the index tab to **Match recruitment assistant**.
- Fixed the recruitment scan startup error caused by an obsolete `readRecruitmentFilters()` call.
- Restored use of the existing validated timeout and current-games filter reader.
- Added a regression check preventing the obsolete undefined function name from returning.

## 2.0.5 — 2026-07-30

### Challenge recommendation and visible copy dialogs

- Integrated the latest Challenge Assistant recommendation tab with ordered rotation, configurable P2K-opponent exclusion, recent average-board qualification, pause/stop, initial ten recommendations, and five-result continuation.
- Kept the feature on the repository's explicit shared API client and current structured error/runtime architecture.
- Positioned Upcoming Matches copy and chart dialogs inside the portion of a retained iframe that is actually visible in the parent viewport.
- Repositioned open dialogs during parent/child scrolling, resizing, zooming, and orientation changes.
- Updated friendly-match recruitment to stop only when P2K meets the minimum-player requirement, matches the opponent usable lineup count, and has at least the opponent projected win probability.
- Kept recruitment active whenever P2K is below the match minimum or has fewer eligible players than the opposing usable lineup.

## 2.0.4 — 2026-07-30

### Tab visibility regression fix

- Restored the intended single-tab display on `index.html`.
- Added explicit hidden-state CSS for preloaded tool frames and the loading indicator.
- Prevented author `display` declarations from overriding the HTML `hidden` attribute.
- Added a browser regression check covering tab switching, retained frames, and loading-message dismissal.

## 2.0.3 — 2026-07-30

### Concise hover guidance

- Added one shared nonvisual control-hint layer for concise native hover text on useful controls that previously lacked guidance.
- Covered site navigation, diagnostics, analyzer actions and filters, chart controls, recruitment settings, challenge-list actions, dynamically rendered tabs, searches, and expansion controls.
- Preserved every existing title and excluded information buttons and all content inside information popovers.
- Added no new visible icons, panels, spacing, or styling.

## 2.0.2 — 2026-07-29

### Information popovers

- Replaced the Match Assistant's fixed centered recommendation box with a viewport-aware popover anchored to the triggering information button.
- Replaced CSS pseudo-element tooltips in Upcoming Matches, detailed match analysis, and single-match analysis with the same shared accessible component.
- Added automatic above/below placement, horizontal viewport clamping, embedded-frame viewport awareness, and repositioning during scroll, resize, and orientation changes.
- Added reliable outside-tap and Escape dismissal, focus restoration, and one-open-popover-at-a-time behavior.
- Standardized all analytical information buttons and popovers on the Match Assistant's blue visual style while retaining the existing orange detailed-analysis action.

## 2.0.1 — 2026-07-29

### Diagnostics

- Added centrally defined analytical model versions to the Diagnostics panel and copied support snapshot.
- Added model identifiers for Match Assistant eligibility/recommendation, Upcoming lineup/recruitment, Match Creation scoring, in-progress projection, recruitment eligibility, and Challenge List validation.
- Added `docs/MODEL_VERSIONS.md` with the model-versioning rules and current source revisions.

## 2.0.0 — 2026-07-29

### Reliability and correctness

- Fixed the duplicated Match Assistant element ID that could bind search behavior to the wrong element.
- Reworked corrupted-cache recovery so malformed entries are removed before a network retry.
- Added ordinary CORS retry before JSONP when conditional revalidation fails.
- Hardened cross-tab request claims, completion races, lease renewal, cleanup, and shared-request cancellation.
- Added quota recovery and an in-memory LRU cache fallback.
- Added transient/permanent Challenge List classifications and retry of temporary failures.
- Added separate pending, failed, and settled states to Recruitment Assistant scans.
- Preserved partial results and retry-only-failed behavior throughout bulk analyzers.

### Networking and performance

- Kept all Chess.com requests on the explicit `P2K_API_CLIENT`; the package never replaces `window.fetch`.
- Added adaptive bounded priority-first processing, priority aging, global rate-limit pauses, jitter, and a transient-failure circuit pause.
- Restricted API requests and JSONP fallback to configured approved origins.
- Disabled scheduled IndexedDB pruning pending real usage calibration.
- Added endpoint-specific freshness, long-lived match retention, stale-if-error support, and emergency quota pruning that protects recent match records.
- Added cross-tab analysis completion coordination so Match Assistant, Upcoming Matches, and Match Creation can refresh shared data.

### Architecture and maintenance

- Externalized Match Assistant and Match Creation JavaScript and CSS without restoring any legacy loader.
- Consolidated Upcoming Matches and detailed-modal logic into one shared analysis core.
- Replaced HTML replay/document-write tab loading with retained same-origin iframes, preserving tab state when switching.
- Centralized routes, club identity, league acronyms, request settings, cache calibration values, and feature flags.
- Removed obsolete page-level JSONP helpers.
- Added a Content Security Policy, configurable analytics module, release metadata, local server launcher, diagnostics panel, and automated package checks.

### User-visible behavior

- Added the Diagnostics panel.
- Completed analyses now rename their main action to a refresh equivalent.
- Switching tabs no longer discards loaded data.
- Completing Match Assistant, Upcoming Matches, or Match Creation analysis requests synchronized refreshes of the other compatible retained tabs.

## 2.6.6 — Achievements, Live profiles and sequential games

- Added the complete achievement catalogue and first ten dedicated artwork files.
- Paginated achievement player cards and added shared-gateway Chess.com avatars.
- Added Live ranks and MCA metrics to achievements and unified profiles.
- Kept Team Points half-point precision in profile and achievement displays.
- Made unified profiles viewport-safe and parent-modal aware when opened from embedded achievement content.
- Treated a missing second game as normal while the first game of a one-concurrent-game match is still in progress.

## 2.10.6.23 — 2026-08-25

- Consolidated correlated rename lifecycle records into one authoritative name-change event.
- Added full known-tournament status listing to Tournament Management.
- Unified the Dashboard New matches · 24 h unknown/loading value to `—`.
- Added admin-only Member lookup with identity, lifecycle, rating, activity, achievement and MCA information.
