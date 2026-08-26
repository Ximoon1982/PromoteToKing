# Promote to King v2.8.8 architecture delta

v2.8.8 builds on the final v2.8.7 ACAMR architecture. Core becomes schema 6 solely to persist opponent-club icon/profile cache metadata; Analytics remains schema 5. Existing Club/Player/Tournament/league CRON cadence is unchanged.

## Club Intelligence projection

A shared `ClubIntelligenceService` derives member activity, availability/load, contribution, team depth, freshness coverage, anomaly/action state, opponent intelligence, historical snapshots, achievement challenges and explainable Club Points forecasts from canonical Core/Analytics facts. It is a derived read layer, not a new source of truth.

Compact daily historical snapshots live in protected runtime storage. The existing Club worker opportunistically captures the daily snapshot and runs an internally hourly-gated anomaly scan when execution budget remains. No new CRON job is required.

## ACAMR adaptive allocation and telemetry

ACAMR keeps the v2.8.7 trust boundary and scope: Club/Member Points support and member data are included; tournaments and match-registration monitoring are excluded. Candidate work is now scored by rating/profile staleness, recent member activity and incomplete/in-progress point work. Protected filesystem claims still distribute work across authenticated sessions and prevent duplicate refreshes.

Protected best-effort telemetry records ACAMR plan/claim/observation yield and Team Points public endpoint latency/status/memory. Telemetry never becomes canonical chess data and is used only for operational diagnostics.

## Unified player intelligence

The existing unified player profile is the canonical cross-feature player surface. Hall, Achievements, Insights, embedded Recruitment, Tournaments and Club Intelligence can route into it. It progressively adds activity, availability/load, contribution and nearest achievement challenges while retaining Team Points, Daily/Live rank, tournament medals and progression.

## Opponent club icon cache

`p2k_tp_opponents` stores Chess.com club `icon` metadata with a long refresh TTL. Opponents Insights renders cached icons immediately and refreshes missing/stale icons through the server. Existing opponent maintenance also opportunistically refreshes the same cache.

## Modal and Hall behavior

Nested dashboard modals preserve their actual DOM nodes in a stack rather than reconstructing `innerHTML`, preserving event listeners and scroll/focus state across Profile → Achievement → Profile transitions. Hall of Fame defaults to Achievements, while explicit Daily/Live navigation continues to open the requested rank view.
