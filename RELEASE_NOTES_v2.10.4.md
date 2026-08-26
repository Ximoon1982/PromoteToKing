# Promote to King v2.10.4

v2.10.4 is a cumulative corrective and feature release built on v2.10.3. It keeps the public Team Points source on Blue (engine baseline v2.9.22.10) while consolidating post-v2.10.3 Green repair work and hardening authentication/UI privilege boundaries.

## Administrator identity and display-only `?name=`

- Team Points administrator-session establishment is bound to the server-side `P2KOAUTH` identity. The browser no longer supplies a trusted username when opening the administrator session.
- Legacy state-changing maintenance endpoints now require the secured Team Points administrator session/CSRF in addition to their existing same-origin/custom-request-header checks.
- `?admin=1` is no longer an administrator/UI admission bypass.
- `?name=<player>` remains display-only: player-facing content and visible privileges are projected as that player would see them, while OAuth, bearer transport, CSRF, server authorization and audit identity continue to use the real authenticated account.
- The display identity propagates through ui-v1/ui-v2 integrated frames and the Team Points migration interface.

## Achievement integrity and UX

- Achievement Condition Integrity Fix (ACIF) aligns measurable progress with the corresponding unlock condition and prevents a measurable `current >= target` condition from remaining unearned after rebuild.
- Quantifiable unearned achievements can display progress even at `0 / target`; earned achievements never display a progress bar.
- Seniority progress uses the same calendar-month/year thresholds as earning rather than fixed day approximations.
- The Achievements toolbar adds **See mine** between **Achievement catalogue** and **Most earned**.
- The seven approved recovered Matches / Team Points artworks are the release targets for First Match, First Step, Rising Star, Clutch Player, Great Strategist, Match Veteran and Match Legend, with standard 64px/128px derivatives.

## MCA source event dates

- Stored MCA source CSV rows link directly to their Chess.com live arena page using the filename slug/arena ID.
- Administrators can enter or clear an actual event date while retaining the original upload date.
- Actual dates are hard anchors. Between known anchors, missing dates are interpolated by numeric arena-ID distance; outside the anchored range, upload date is the fallback.
- Interpolated/upload-fallback dates are explicitly rendered as **approximative date**.
- MCA achievement rebuilds use the historical arena crossing date rather than the latest aggregate-row update time. Known dates produce exact MCA achievement dates; interpolated/fallback dates retain approximate precision provenance.
- Editing a known event date invalidates the achievement source watermark so later rebuilds converge automatically.

## Green integrity and scheduling

- Green Integrity Convergence Lane (GICL) re-arms bounded canonical debt when finished matches still have incomplete boards/player events. It is idempotent and does not create duplicate queue rows.
- A match transition to finished forces one final refresh of still-incomplete boards.
- Green player totals/leaderboards materialize current club members only.
- Green Staggered CRON Feeder (GSCF) installs one bounded Green-only feeder at minutes `2` and `32` each hour. Its worker slice targets 18 seconds and has a 24-second hard ceiling.
- GSCF does not change Blue CRON. The existing `p2k_green_worker` DB lease, Green claims and shared pacing authority remain authoritative; the feeder adds resumable opportunities without introducing a competing concurrency controller.
- The dedicated GSCF installer is idempotent and preserves unrelated/Blue CRON entries.
- Green Quick-cycle Accounting & Convergence (GQAC) snapshots `quick_boards` into one finite cohort per Green quick cycle. Work discovered after the snapshot is deferred to the next cycle instead of enlarging the current finish line.
- GQAC terminal board observations retire the current-cycle item once. If a newer authoritative hint arrived while the request was in flight, the board remains `needs_refresh=1` for the next cycle. Transient HTTP failures retain bounded retry/backoff within the finite cohort.
- GQAC exposes total/completed/pending/percent, claim attempts, unique/repeated claimed IDs, requeued-for-next and net completion. Worker and browser accelerator consume that same planner state.

## Database and compatibility

- Blue/Core schema remains 16.
- Analytics advances additively from schema 7 to schema 8 for MCA event-date provenance (`actual_event_date`, `effective_event_date`, precision and update timestamp).
- Green adds two idempotent GQAC accounting tables (`p2k_g_quick_board_cycles`, `p2k_g_quick_board_cycle_items`). Existing Green databases create them automatically on the first quick-board accounting pass; no reset/reseed is required.
- No database reset or reseed is required.
- Public Team Points source remains **BLUE — hardwired**; Green remains migration/reconstruction state.
- v2.10.4 updater accepts v2.10.3 installations with release-controlled post-v2.10.3 hotfixes already applied and converges them to one numbered source state.


## Matches / Team Points artwork

v2.10.4 intentionally retains the seven existing SVG placeholders for First Match, First Step, Rising Star, Clutch Player, Great Strategist, Match Veteran and Match Legend. The exact recovered Aug-6 source filenames and SHA-256 values remain recorded in `ARTWORK_PROVENANCE_v2.10.4.json` for a later artwork-only integration.
