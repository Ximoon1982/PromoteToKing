# Promote to King v2.8.7 architecture delta

v2.8.7 keeps the v2.8.6 Core 5 / Analytics 5 architecture and split Club/Player workers. The principal new subsystem is ACAMR.

## ACAMR trust boundary

The authenticated browser can discover useful Chess.com responses, but it is never authoritative. ACAMR observations may enqueue/prioritize existing `sync_match`, `sync_board`, `sync_player_stats`, `sync_player_profile` and roster work. Canonical membership, match facts, game results and point events are written only by server workers after their own Chess.com verification.

Monthly archive observations are optimized: for already-known P2K participations, ACAMR can identify the exact board that needs verification, allowing the server to verify the board instead of re-fetching the full player archive merely for discovery.

## ACAMR activation

Real OAuth sessions activate ACAMR unconditionally. Simulated sessions activate it only when the simulated-OAuth flag is enabled. A future real OAuth adapter must expose a verified OAuth mode/session through `window.P2K_AUTH`; it is intentionally not allowed to be overwritten by the simulated provider.

## Distributed scheduling

Only one ACAMR loop runs per browser profile through a localStorage leader lease. Server-side protected filesystem claims distribute current members across independent authenticated clients and suppress duplicate refreshes for a bounded period. The work is low priority and pauses when the page/tab is inactive.

## Scope boundary

ACAMR includes Club/Member Points support, relevant member match/board discovery, player archives, ratings and roster freshness. Tournaments and match-registration tracking are excluded. A player `/matches` response may contain a `registered` section, but observations tagged `acamr` explicitly ignore it.

## Retained post-v2.8.6 audit architecture

The Dashboard uses canonical Core historical KPIs, mixed-source cache keys include Core generation/freshness, Hall/Tournament reads use bounded/materialized indexes, and Hall/Insights controller code is lazy-loaded. No schema or CRON cadence change is introduced.
