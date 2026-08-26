# Promote to King v2.9.15

## Scope

v2.9.15 is a corrective release built directly on the canonical v2.9.14 release. It changes no database schema, achievement catalogue, external CRON cadence, OAuth tuning namespace, or queue architecture. Its functional scope is limited to the telemetry-driven corrections prepared after v2.9.14.

## Corrections

- **P0 ACSR authoritative pulse viability.** Continuous Refresh requests a 34-second Club/Member worker pulse through a 45-second admin request envelope. The control endpoint derives/enforces a viable minimum from the configured outbound request timeout instead of accepting the structurally impossible 6-second pulse. The worker outbound safety guard itself is unchanged.
- **Archive acquisition backpressure.** ACAMR and Continuous Refresh share one locked automatic archive fallback budget of 12 archive requests per 600 seconds. Archive work is fallback-only, a slot is consumed only when an archive request is actually emitted, and speculative previous-month planner acquisition is removed.
- **ISRE batching/render/log corrections.** Continuous Refresh batches persistence/UI-event/log telemetry at cycle level, bounds failure logging, throttles repetitive empty-plan logs to at most once per minute, and Task Control high-frequency rendering is null-safe.
- **Member CRON exit-28 margin.** The Player/Member endpoint is capped at 38 seconds and recomputes remaining wall-clock budget before each optional maintenance class, preserving margin under the existing 55-second dispatcher curl ceiling.

## Unchanged

- Core schema: **13**
- Analytics schema: **6**
- Achievement catalogue: **162**
- External CRON cadence: unchanged (4 tasks)
- Fresh real-OAuth startup/adaptive-learning design: unchanged from v2.9.14
- Canonical queue coalescing: unchanged from v2.9.14
