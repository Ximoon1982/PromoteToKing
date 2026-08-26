# Promote to King v2.9.15 — ACSR telemetry corrective audit

## Baseline

v2.9.15 inherits the ACSR Pack, P0 Interactive Survival, OAuth transport behavior and canonical queue authority from the canonical v2.9.14 release.

## Authoritative pulse correction

Production telemetry showed that the 6-second browser authoritative pulse could enter the Club/Member worker but could not satisfy `Worker::hasOutboundRequestBudget()` for normal Chess.com API-bound canonical work. v2.9.15 therefore uses a 34-second worker pulse and a 45-second control request envelope, with the server deriving a viable minimum from the configured request timeout. The existing outbound safety guard remains intact.

## Archive backpressure

Automatic archive repair is fallback-only and ACAMR plus Continuous Refresh share one locked 12-per-600-second archive budget. A budget slot is consumed only when an archive request is actually emitted. Automatic previous-month guessing is removed.

## ISRE / UI safety

Continuous Refresh persistence, UI events and success telemetry are aggregated per cycle rather than per request; repeated empty-plan logs are throttled; high-frequency Task Control rendering uses null-safe DOM helpers.

## Member CRON margin

The Player endpoint is capped at 38 seconds and recomputes the remaining wall-clock budget before analytics/achievement, housekeeping and storage maintenance, retaining margin below the existing 55-second shell curl ceiling.
