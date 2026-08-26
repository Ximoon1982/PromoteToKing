# Promote to King v2.9.1 — release notes

v2.9.1 consolidates the prepared post-v2.9.0 branch. It is an additive upgrade from v2.9.0 and does **not** require a database reset or re-seed.

## Administration and navigation

- Administration/Admin Tools deep links now retain the selected group, subtab and supported tool context through refresh and browser history.
- Dashboard Admin shortcuts open the intended configured administration view rather than a generic Admin landing page.
- Hall of Fame is ordered before Insights in the main tab row.
- Hall of Fame desktop search results are forced to the full content width.
- Database Match Profile opens the normal Chess.com match page rather than the API URL.
- Dashboard health monitoring uses the same traffic-light vocabulary as Storage & Capacity.
- Below-minimum match warnings on the Dashboard are limited to matches starting within the next seven days.

## Charts and Team / Opponent intelligence

- Daily Boards and Club Points descriptions explicitly explain that legend/plot names can be clicked to show or hide series.
- Maximized charts are positioned inside the current viewport, reflow after the maximized container is visible, lock background scrolling and restore the previous scroll position on close.
- Monthly Active Members treats the whole current calendar month as incomplete/future.
- Lineup Evolution and Win-probability History retain the first and last sample of a run of identical values while omitting redundant interior samples.
- Club Intelligence → Team Depth excludes unrated members from the rating-axis plot while retaining their count/coverage metrics. Active, Available and Overloaded status metrics/percentages and status distinctions are shown.
- Insights → Members supports multi-select Activity Status filtering (Active, Cooling, Inactive, Dormant, Unknown) before server-side pagination.
- Club Intelligence → Opponents places the stored opponent avatar/logo before the opponent name.
- Opponent Insights includes the Balance Analyzer with shared Friendly/League and Classical/Chess960 selectors, two density heatmaps, logarithmic match-size scaling, P2K strength equality reference, P2K-themed density colors and 2D wheel/pinch/pan/box zoom with adaptive re-binning.

## Achievements

- Earned achievements no longer show progress bars.
- Dashboard Achievement Challenge cards deep-link to the selected player's view of that specific achievement.
- Achievement catalogues include live achievement-name search.
- Achievement breadth thresholds are 1, 5, 10, 15 and 20 eligible achievement groups.
- 50 previously approved recovered achievement artworks are integrated. Raster achievement masters are normalized to 640×640 with 64×64 miniatures and 128×128 thumbs; unresolved/new-family art remains on explicit placeholders.
- `Kingmaker` is renamed **King Daily Rank** and uses the framed King Daily Rank artwork.
- Silver King Daily Rank uses the framed Silver King artwork.
- Live Pawn uses the framed Live Pawn rank artwork.

## Match Creation

- Clicking a chart bar whose contributing matches lack board detail requests only those matches, preserves cached detail, displays local progress, and refreshes the selected chart/detail state. It does not trigger an archive-wide scan.

## Traffic / Visitors diagnostics

- The current browser reports whether Traffic collection is active or suppressed by Do Not Track / Global Privacy Control.
- Club Intelligence exposes collector configuration/storage health, last event and recent event counts.
- Runtime Diagnostics includes Traffic collector state.
- An isolated synthetic self-test checks collector → storage → aggregation without adding a real visitor.
- DNT/GPC suppression remains respected; suppressed Traffic events are not persisted.

## ACAMR correction and diagnostics

- ACAMR now distinguishes a persistent browser-client ID (`localStorage`) from a browsing-session ID (`sessionStorage`) and authenticated-user identity. Raw IDs are sent only same-origin and only protected hashes are retained in telemetry.
- Claim accounting records every claim/member rather than treating a successful planning request as one member.
- Work is accounted by class: player matches, stats, archive and roster. Metrics distinguish tasks handed out, browser fetch success/failure, accepted ACAMR observations and authoritative work queued.
- ACAMR-only observation accounting is separated from normal browser observations.
- Club Intelligence cross-checks ACAMR activity against canonical freshness and warns for possible work-class starvation, repeated browser fetch failure and successful-fetch/observation-delivery stalls.
- Browser fetch success is explicitly **not** presented as canonical completion. Authoritative server verification remains required.
- Storage exceptions while creating browser diagnostic IDs fall back safely without blocking ACAMR.

## Data Reconciliation / CSV import

- New Admin Tools → Data Reconciliation drop point with Admin authorization, CSRF protection, private staging, SHA-256 input tracking and configurable retention.
- Known CSV families are recognized by schema/content rather than numbered filenames, including Finished Matches, Active Matches, DailyData, parsed members, parsed data, all members and findings/discovery lists.
- `Check only` validates schemas, duplicates/references, score/result/points invariants, timestamp consistency, cross-file coverage and DailyData historical checkpoints without mutating canonical data.
- DailyData is treated as a chronological non-void finished-match **prefix checkpoint**, not as a terminal current total; newer matches, including later matches on the same UTC day, are allowed after the resolved prefix.
- Conflicting score/result/point data never directly overwrites canonical match facts. It creates targeted authoritative `sync_match` verification.
- Positive member/board evidence can be seeded; absence from an almost-current CSV never demotes a member. Authoritative roster refresh resolves removals.
- Apply is explicitly confirmed with `APPLY <batch-id>`, bounded/resumable/idempotent, uses existing durable queues and keeps fresh/live work authoritative.

## Durable queue / Work Report

- Durable queue diagnostics separate total items, committed/done, true remaining backlog, pending, claimed/running, retry-waiting and failed. A zero pending count can no longer be interpreted as zero backlog.

## Compatibility / operations

- No destructive database reset or re-seed is required.
- The IONOS CRON watchdog remains 55 seconds.
- CRON scripts continue to require a real PHP CLI (`PHP_SAPI === "cli"`) and prefer versioned IONOS CLI binaries such as `/usr/bin/php8.5-cli`.
- The v2.9.0 historical Club Points revalidation tool remains compatible and keeps its original queue keys so an already-seeded revalidation is not duplicated.
