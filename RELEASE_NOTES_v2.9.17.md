# Promote to King v2.9.17

## Scope

v2.9.17 is built directly from the exact frozen internal v2.9.16 standalone SHA256 `6eb50088ae0601172c71ca99ef8d6781eca35278504ee6e75e837381a3ba337d`, using internal source/package history only. GitHub is not used.

## Authoritative convergence and throughput

- **Authoritative Convergence & Deadline Control Fix (ACDC Fix + audit expansion).** One absolute request deadline now propagates through CRON maintenance, Worker, ChessApi and the shared gateway. Lock/connect/request budgets shrink to the actual remaining wall-clock time instead of pre-reserving a worst-case request + gateway-lock allowance. Canonical verification never accepts stale-if-error data. Player and Club queue fairness cursors persist across invocations; Club work explicitly alternates match and board opportunities. Fast Fetch authoritative pulses enforce a bounded canonical item quota. PMAF fallback freshness is written only after every required archive month in the current fallback generation has actually succeeded; failures remain explicit and browser planning respects the PMAF primary-endpoint cooldown. Continuous Refresh now has explicit `client_refresh` observation provenance while retaining claim verification. Worker telemetry separates execution attempts from terminal queue completion.
- **Canonical Throughput & Amplification Reduction Pack (CTAR Pack).** Shared gateway pacing/state remains serialized only for launch reservation; the exclusive lock is released before the upstream HTTP transfer. Successful server-held OAuth gateway acquisitions seed a short-lived server-attested shared cache that canonical workers may reuse, while stale/error responses never earn verification. Per-board match finalization is replaced by local readiness checks and one authoritative finalization attempt per match/invocation. Club-index metadata/due checks use bulk reads, producer bursts use batched queue commits, duplicate member archive fan-out is coalesced, player continuation slices avoid building a combined match list, Analytics generation changes are coalesced for a short window, and empty Fast Fetch plans back off longer to reduce idle database churn.

## Insights, tournaments and chart usability

- **Insights → Team → Daily boards and current active boards** stops at the current UTC date; future registration/forecast dates are not drawn on that graph.
- The explanatory “Daily facts are materialized…” paragraph is removed.
- Tournament summary metrics no longer display **Excluded accounts**.
- Shared chart maximize keeps the enlarged dialog inside the visible dynamic viewport, carries the chart legend into the maximized view, moves the original live chart DOM rather than cloning it, and therefore retains existing wheel/drag/pinch/double-click zoom handlers while maximized.

## 1WL achievement artwork

All seven approved 1WL achievement masters are the approved 640×640 files and have corresponding 128×128 and 64×64 derivatives. This explicitly includes replacing the historical **1WL Competitor, Veteran and Legend** artwork with the new approved masters; First Point, Scorer, Specialist and Master use the same approved pack.

## Compatibility

- Core schema: **13** (unchanged)
- Analytics schema: **6** (unchanged)
- Achievement catalogue: **162** definitions (unchanged)
- External CRON cadence: **4 tasks, unchanged**
- OAuth tuning key/learning policy: unchanged
- No database reset or reseed
