# Promote to King deep audit — v2.8.5 through v2.8.10

## Executive result

v2.8.10 retains the production-confirmed v2.8.9 startup/auth fix and repairs the most important semantic and operational gaps found by comparing the exact release trees and the requested behavior since v2.8.5. The audit deliberately distinguishes presence of code from verified behavior.

### Corrected in v2.8.10

- Canonical score-derived W/D/L and historical result repair; Opponent Intelligence zero-loss bug addressed at the source.
- Dashboard Core member count and all-history Finished KPIs protected from Chess.com second-wave overwrites.
- Redundant Dashboard club-members/profile calls removed without changing startup ordering.
- Tournament/Match Tracking protected mutation headers fixed centrally.
- Match Tracking task-key/panel mismatch fixed.
- Tournament archive and Match Tracking registry conservative recovery added.
- Release package no longer ships destructive empty runtime files or production shared-secret file.
- CRON installer/dispatcher redesigned around runtime token reads, secretless crontab and two-layer invocation diagnostics.
- Tournament cadence metadata corrected consistently to 10 minutes.
- Club Intelligence freshness thresholds aligned to 5m Club / 30m roster / 10m Tournament operational contracts.
- Finished-board verification separated from active backlog.
- Activity/contribution/opponent/snapshot/personal-home/ACAMR/performance telemetry enriched.
- ACAMR label and Administration tool card corrected.

## v2.8.5 foundational contracts

Status: **retained**.

- Separate Club and Player workers; Club discovery cannot be starved by Player history.
- 5-minute Club discovery / ~30-minute roster freshness.
- Browser observations remain non-authoritative hints; canonical facts require server verification.
- Demand-driven avatar/profile persistence; Achievement visible batch only.
- Core generation freshness and materialized public reads.
- Finished authoritative 0–0 stored as void and excluded analytically.
- Public GET paths do not perform DDL/migrations.

## v2.8.6 progressive UX

Status: **retained**.

- Database-first Dashboard with automatic post-paint current/Live enrichment.
- Progressive Team/Member/Match/Opponent Insights.
- Achievement first batch 12 and incremental avatar/medal/catalog enrichment.
- Daily/Live rank paging, unified Hall search, progressive Profile.
- Server-paged Tournament browsing and click-loaded player history.

## v2.8.7 audit / ACAMR

Status after v2.8.10: **safe portions retained; dangerous startup coupling remains removed**.

Retained: Core/Profile freshness overlay, efficient club resolution, paused-Player roster pass, shared Members/Team projections, bounded Hall queries, Tournament BrowseIndex, Hall tournament index, ACAMR with original trust/scope boundaries, Hall/Insights lazy modules isolated from startup.

Not restored: the v2.8.7 low-priority Dashboard startup scheduler. It is unnecessary for correctness and previously shared too much startup state. The working v2.8.9/v2.8.6 startup path is intentionally kept.

Restored safely in v2.8.10 outside startup: Core member source of truth, protection of all-history Finished totals, and removal of redundant normal Dashboard members/profile requests.

## v2.8.8 explicit UI/fix requests

- Human Chess.com achievement links: **implemented for stored match/tournament sources**.
- Exact historical MCA/arena trigger URL: **historical limitation**; aggregate MCA imports do not preserve the source event URL.
- Unified player Profile from Daily/Live and cross-feature surfaces: **implemented**.
- Mobile Hall rank containment: **implemented**.
- Nested Profile→Achievement modal restoration: **implemented**.
- Hall defaults to Achievements: **implemented**.
- Opponent logos with persistent long cache: **implemented**.
- Team Insights first-day→today→+6-month Low/Medium/High progression above YoY: **implemented**.
- Profile rank artwork clipping correction: **implemented**.
- Immediate configured/local admin recognition + iframe/lazy retry: **implemented and browser-tested**.

## Intelligence feature audit

### Team depth
Implemented with Daily/Classical and Chess960 100-rating bands, member/active/available/overloaded counts.

### ACAMR effectiveness
Improved in v2.8.10: active sessions (30m), distinct sessions, distinct claimed members, plans, claims, accepted observations, authoritative queue work, tasks, conflicts and yield. A “time saved” estimate is intentionally absent because there is no valid counterfactual CRON-only baseline.

### Freshness / coverage
Implemented with member/rating/avatar coverage, finished-board verification, separate active backlog, oldest unresolved finished board, oldest rating, queue age, Core→Analytics lag, Club/roster/Tournament freshness and operational thresholds.

### Anomaly detector / Admin action queue
Implemented for stale Club/roster/Tournament, failed board/job work, rating aging, finished-board coverage/age, Analytics lag and Club Points snapshot regression. The queue links to the correct current task keys.

### Member activity / availability / contribution
Implemented with overall + Standard + Chess960 activity, load/availability, Club Points share, points/game/board, league points, Standard/960 points, transparent rating-strength adjustment and six-month consistency.

### Recruitment confidence
Implemented from measurable current inputs: rating freshness, opponent rated-lineup coverage, recommended-player availability and projected board coverage. Historical conversion probability is **not** fabricated because historical recommendation→join outcomes were never labelled/stored. A future model should begin storing recommendation/outcome observations before fitting probability.

### Opponent Intelligence
v2.8.10 repairs W/D/L canonically and adds typical board count, opponent average rating, league/friendly mix, 90-day frequency, score margin and current registration/in-progress overlap. Historical registration acceleration/late-registration behavior cannot be reconstructed for matches that were not snapshot-tracked; future tracked snapshots can support it.

### Historical snapshots / time travel
Daily aggregate snapshots include members, Club Points, W/D/L, finished matches/boards, overall/Standard/960 activity, rating and finished-board coverage; previous/~7d/~30d deltas are shown. Detailed historical per-member lineups/ranks from before snapshot capture are not inferred.

### Explainable forecasts
Implemented: recent 90 days in three blocks, capped trend bend, Low/Medium/High scenarios and visible drivers; same forecast engine is reused by Team Insights projections.

### Cross-feature player modal / personalized home / challenges
Unified Profile is retained. Personalized home now includes overall/Standard/960 activity, recent 7-day points/games/W-D-L vs previous week, current registered/live match counts, availability/load, contribution/league points, points/board, win rate, consistency and nearest challenge. Historical rank movement/medal delta is not reported unless an authoritative historical observation exists.

### Endpoint performance telemetry
Latency/error/call counts, memory, response sizes and 304 rate are measured. Direct PDO query time is not separately instrumented and is explicitly identified as a limitation rather than estimated.

## Tournament / Match Tracking / CRON audit

The v2.8.9 package could overwrite `data/server-config.json` with a placeholder and replace live Tournament/Match Tracking state with empty files. Existing crontab lines would still contain the prior token, causing protected Tournament/Match Tracking endpoints to fail authentication. The unified Task Control helper also omitted required `X-Club-Tools-Request` headers, and its Match Tracking panel used a mismatched task key.

v2.8.10 removes mutable state from the release package, adds backup/cache/history recovery, fixes request headers and task-key mapping, and replaces embedded-secret cron URLs with a runtime-token dispatcher plus shell-level heartbeat diagnostics.

## Redundancy / maintainability findings

- Historical compatibility alias `match-monitoring` remains only to keep old URLs working; canonical registry key is `match-tracking`.
- Legacy `team-points` task remains a hidden compatibility alias; normal scheduling uses separate Club/Player keys.
- Legacy `?token=` CRON authentication remains accepted for upgrade compatibility, while new jobs use the protected header.
- Historical release/install/CRON documents and old installer scripts remain intentionally for traceability; they are not active runtime dependencies.
- Hall/Insights remain lazy but startup-owned dependencies are DOM/main-controller owned, preventing the v2.8.7 undefined-symbol failure class.

## Cross-chat request reconciliation (v2.8.5 → v2.8.10)

This matrix checks the current code against the user-requested behavior, not merely against intermediate release notes.

| Request / retained contract | v2.8.10 audit result |
|---|---|
| Club discovery remains frequent even during backlog; Club/Player workers stay separate | **Complete** — 5-minute Club and 30-minute Player/roster lanes remain separate and independently observable. |
| Authoritative finished 0–0 remains stored but excluded from every analytic surface | **Complete** — Core 7 migration preserves/repairs void state and canonical views/Analytics exclude it. |
| Achievement avatars only resolve for visible 12-player batch; no bulk crawler; stale/persistent cache is acceptable | **Complete** — demand-driven Hall/Profile behavior retained. |
| Dashboard visible DB values first; secondary current/Live data automatic, never click-gated | **Complete** — production-proven v2.8.9/v2.8.6 startup graph retained. |
| Dashboard current members and all-history Finished metrics remain canonical/local | **Complete after v2.8.10 repair** — Chess.com members/profile no longer overwrite member count and bounded finished list cannot overwrite historical KPIs. |
| Hall/Profile: Achievements default, unified profile from Daily/Live, mobile containment, nested Profile→Achievement reliability | **Complete** — retained and browser/startup-tested. |
| Achievement event links use normal Chess.com URLs | **Complete for stored match/tournament provenance; partial historically for MCA/arena** — old aggregate MCA data did not retain exact triggering event URL. |
| Team Insights: first-day→today→+6 months projection above YoY, same Low/Medium/High model | **Complete** — shared forecast engine retained. |
| Team depth / activity / availability / contribution / freshness / anomaly / action queue / forecasts / snapshots / personalized home | **Substantially complete and expanded** — v2.8.10 adds missing freshness, Std/960, points/board, league, consistency, week-over-week and operational semantics. Historical member-level state before snapshot collection cannot be reconstructed. |
| Opponent Intelligence W/D/L including losses | **Complete after v2.8.10 repair** — score-derived canonical outcome, historical migration and semantic win/draw/loss/void fixtures. |
| Opponent Intelligence broader behavior | **Improved but historically bounded** — boards, rating, league/friendly, 90-day frequency, score margin and current overlap are present; registration acceleration/late-registration requires tracked historical lineups. |
| Opponent logos cached persistently/long-lived | **Complete** — Core-backed opponent profile/icon cache retained. |
| Recruitment: choose Standard/960 rating category; stored current members; remove already registered, unrated, below lowest opponent, opponent-club members; strongest-first significant advantage; progress | **Complete/retained** — current implementation and UI contract explicitly preserve these filters and top-player semantics. |
| Recruitment confidence | **Complete for measurable current inputs; historical probability intentionally not fabricated** — recommendation→registration outcomes were never historically labelled. |
| ACAMR real OAuth always, simulated only with flag; Club/Member Points included; tournaments and registration tracking excluded; server authoritative | **Complete** — re-enabled and browser-tested independently of startup. |
| ACAMR effectiveness/adaptive allocation | **Improved** — active/distinct sessions, claims/members, observations, queued work/conflicts/yield and adaptive work priority. Counterfactual time-saved estimate intentionally omitted without evidence. |
| Tournament discovery/status/podium stored server-side, paged display, tied medalists, fair-play/abuse exclusions | **Complete/retained** — status now actually checked every 10-minute Tournament invocation; discovery/podium staged extras; recovery/cache coherence fixed. |
| Tournament manual refresh from Admin works | **Complete after v2.8.10 repair** — required `X-Club-Tools-Request` action header is sent and browser-tested. |
| Match Tracking follow/unfollow/record/history/snapshots/display; existing snapshots retained on unfollow; destructive deletion separate | **Complete/retained** — Task Control key/header/state recovery repaired without replacing tracking semantics. |
| Scheduled tasks visible and diagnosable; CRON schedules unchanged | **Complete and hardened** — shell heartbeat + endpoint status distinguish daemon absence from HTTP/auth failure; installer is atomic and secretless. |
| In-place release must not destroy production Tournament/Match Tracking/config state | **Complete after v2.8.10 repair** — mutable live files removed from package; backup/history recovery added. |
| Configured/local admin must not wait on remote Chess.com verification | **Complete** — Dashboard, embedded Admin guard and outer site router are aligned. |
| Public reads must not perform schema/DDL or unnecessary writes | **Retained** — migrations remain worker/admin maintenance paths; public materialized reads stay read-only. |
| No unnecessary DB reset/reseed | **Complete** — Core 6→7 is additive; Analytics remains 5. |

### Exact package delta

Relative to the finalized v2.8.5 tree, v2.8.10 contains **668 project files vs 582**, with **91 added, 79 modified and 5 removed** (175 changed paths). The five removals are deliberate: the three mutable production files `data/server-config.json`, `data/tournaments/archive.json`, `data/match-tracking/index.json`, plus two stray packaging test files (`testwrite`, `testwrite2`).

## Remaining honest limitations / next data-collection opportunities

1. Preserve an actual arena/event URL in future MCA import formats so future arena-triggered achievements can link to the exact triggering event.
2. Store recruitment recommendation snapshots and eventual registration outcomes if a calibrated historical recruitment probability is desired.
3. If detailed member-level time travel is desired, define a compact/gzipped daily member snapshot retention policy before collecting it; do not retroactively invent old ranks/activity.
4. If database-time attribution is needed per endpoint, add explicit PDO/query timing instrumentation rather than inferring it from total request latency.
5. Registration-acceleration/late-registration opponent behavior becomes reliable only for matches with sufficient tracked lineup snapshots; expose sample size when added.

## Final post-fix operational / redundancy audit

- **Tournament status cadence:** fixed. The previous staged worker rotated discovery/status/podium, effectively checking unfinished tournament status only every third 10-minute invocation. Status maintenance is now performed every Tournament invocation; discovery/podium remain staged extras.
- **Tournament freshness parsing:** fixed. `lastStatusRefresh` is structured metadata, not necessarily a raw timestamp. Intelligence now reads maintenance/status timestamps explicitly and exposes both ages.
- **Tournament recovery cache coherence:** fixed. BrowseIndex signatures include primary/backup recovery-candidate metadata, so a recovered archive cannot remain hidden behind an ETag derived only from an accidentally empty primary.
- **Outer-site admin recognition:** fixed for consistency. The site router now accepts configured/local admin authorization before remote Chess.com profile verification, matching the Dashboard and embedded Admin guard.
- **Personalized-home redundancy:** fixed. Concurrent authenticated renders coalesce the same member-intelligence request; the backend resolves a single requested member with a keyed query rather than rebuilding the full ~4,000-member intelligence table. Bulk rows are request-cached for aggregate intelligence views.
- **CRON clean-install recovery:** hardened. If neither protected config nor a recoverable legacy token exists, the argument-less installer generates a protected 256-bit shared token rather than stopping for manual editing.
- **Browser coverage:** expanded beyond startup. The execution gate now renders Tournament Management stored data/logs and successfully performs status refresh with the required action header; it also renders recovered Match Tracking data, the correct management panel, and shell/HTTP diagnostics.

### Retained earlier behavior checked against the request history

- Recruitment still selects the proper Daily/Classical vs Chess960 rating category, excludes already registered/unrated/below-opponent-floor/opponent-club members, keeps strongest-first semantics, current availability/load/confidence context and progress reporting.
- Match Tracking still supports follow/unfollow, record-now, separate destructive stored-data deletion, retained history/snapshots, filtering/sorting and accelerated near-start capture cadence; v2.8.10 repairs the Task Control key/header/state/CRON paths around those behaviors rather than replacing them.
- Tournament podium handling still supports tied medalists and fair-play/abuse exclusions. Full browser repair/podium workflows retain account-status validation; the lightweight scheduled/manual status maintenance remains intentionally bounded and does not perform an exhaustive profile crawl on every 10-minute invocation.

## Validation philosophy change

The largest failures between v2.8.7 and v2.8.9 passed syntax/structural tests. v2.8.10 therefore adds semantic gates: execute score outcomes, recover real temporary filesystem state, install a fake crontab and verify secretless dispatcher lines/token recovery, and execute Dashboard/admin/ACAMR/request-header flows in Chromium. Future correctness tests should prefer known input→observable output over checking for the presence of a particular SQL alias or function name.
