# Promote to King v2.9.10

v2.9.10 fixes real-OAuth persistence/propagation, makes interface observations universally useful to server-side discovery/freshness, guarantees hourly authoritative club roster and match-index refresh under CRON, and restores Challenge Assistant analysis context.

## Real OAuth everywhere
- A valid server-side real OAuth session is detected even on clean URLs without `oauth=2`.
- All shared-client pages, lazy panels, embedded frames and admin guards wait for the same OAuth readiness decision.
- Explicit `oauth=1` remains simulated and serial.
- `P2KOAUTH` is a Secure/HttpOnly/SameSite=Lax opaque persistent session cookie retained slightly beyond the access-token lifetime; the Bearer token never enters browser JavaScript.
- Existing session cookies are refreshed on access so a previously browser-session-only login becomes persistent.

## Universal opportunistic ingest
- Successful interface fetches of relevant Chess.com data are forwarded through one bounded same-origin observation pipeline regardless of originating page/tool.
- Covered data includes club profile/member count, club roster, club match index (registered, in progress and finished), individual matches, player profiles, player stats, player match indexes and game archives.
- New match IDs seen by the interface are registered immediately with `first_discovered_at`, fixing delayed “New matches · 24 h” updates.
- Browser observations write observed/discovery state only. Canonical membership, match status/results/boards/points and other authoritative facts still require server verification.
- Cached/stale responses do not earn new observation freshness; only actual network refreshes do.
- Observation handoff is widened to 48 observations per batch with bounded server-side rate protection so high-throughput OAuth fetches do not outrun ingest.

## CRON freshness floor
- Core schema advances 11 → 12; Analytics remains 6.
- Roster and club-index now have separate observed and authoritative-verified clocks.
- While normal CRON is running, authoritative roster and match-index work is deadline-protected so each is fetched at least once per hour.
- Deadline work is promoted before the 60-minute boundary, cannot be starved by historical backlog, and cannot permanently fail. 429/transport/server failures retain capped Retry-After/exponential backoff and remain overdue until success.
- Browser observations do not satisfy the authoritative CRON deadline.

## Challenge Assistant
- Restores live context showing the team or teams currently being analyzed while preserving authenticated parallel analysis.

## Preserved contracts
- Core schema 12 / Analytics schema 6; achievement catalogue remains 162.
- Existing four-task external CRON cadence is unchanged.
- Logged-out and simulated OAuth traffic remain serial.
- CRON/CLI does not borrow or persist a user's OAuth Bearer token.
- Protected deployment configuration is excluded from release payloads and preserved by the incremental updater.
