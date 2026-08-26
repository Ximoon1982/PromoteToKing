# Promote to King v2.9.12 — transport, feeder and cache correction

v2.9.12 is a focused corrective release built from the verified v2.9.11 package. Core schema remains 12 and Analytics schema remains 6. The external four-task CRON cadence is unchanged.

## Shared authenticated transport

- Moves real-OAuth launch-rate authority to a server-shared coordinator keyed by an opaque hash of the server-held OAuth token. All frames, analyzers and authenticated maintenance bridges using that token therefore share one upstream launch clock.
- Uses a hybrid PI/D pressure controller with a hard 429/Retry-After boundary and anti-windup behavior. A rate-limit event is coalesced rather than punished independently by PHP, each frame and each retrying request.
- Learns successful endpoint-class latency baselines separately for match details, club indexes, rosters, club profiles, player profiles, stats, player matches and archives.
- Permanent application 4xx responses such as 404/410 are not treated as congestion and do not create a rate boundary.
- Up to six bounded OAuth gateway POSTs keep slow endpoints fed while the shared server clock, not the number of posts, controls aggregate Chess.com launch rate.
- OAuth request network timeout begins when the server actually launches the upstream transfer; browser queue/gateway wait no longer consumes the request timeout.
- Foreground and background traffic remain distinct through the gateway. Background stale refresh work cannot outrank active foreground analysis.

## Match Creation and large-run feeder fixes

- Replaces repeated whole-queue best-item scans with one stable priority sort and linear admission.
- Match Creation stage completion uses constant-time pending counters instead of repeatedly rescanning the full plan after every completion.
- Purely visual progress painting is throttled while every settled match is still recorded immediately.
- Endpoint-homogeneous gateway batches prevent slow historical match details from distorting a fast club-index latency baseline.

## Cache corrections

- Confirmed finished-match details remain fresh for the configured long retention window instead of becoming daily stale-refresh traffic.
- Large priority batches bulk-warm cache keys with one IndexedDB read transaction.
- Cache persistence batches writes so high authenticated throughput does not create one critical-path IndexedDB transaction per response.
- Cache hits bypass the network rate budget; explicit network-only/reconciliation callers can still force verification.

## Site-wide audit

The shared-client/real-OAuth contract was audited across all known production API surfaces, including Find Match, Upcoming Matches, Match Creation, Challenge Assistant, Recruitment tools, Open Match analysis, Tournament Management, Task/Admin tools and dashboard/embedded variants.

## 50 requests/second cap simulation

A local fake Chess.com service enforced a hard rolling one-second limit of 50 arrivals. Eight concurrent requester families issued 512 mixed requests through the exact shared PHP rate coordinator. Final run:

- 512/512 HTTP attempts reached the fake upstream.
- 508 were admitted; 4 received 429.
- 32 deliberate permanent 404 responses did not lower the transport rate.
- Peak rolling one-second arrivals: 52.
- Two coalesced backlash episodes.
- Settled shared rate: 47.6727 requests/second.
- Learned unsafe boundary: 51.8181 requests/second.

A separate Chromium gate processed 700 Match Creation detail requests with six active gateway posts and no late-run collapse. Its cache phase served 150 old finished-match details locally and made only 50 network requests, with no stale-refresh competition.

## Security and compatibility

- Bearer access tokens remain server-side only.
- Logged-out and simulated `oauth=1` operation remain serial.
- CRON/CLI does not inherit a user's OAuth token.
- Core schema 12 / Analytics schema 6; no database migration.
- Existing protected configuration files remain protected by the incremental updater.
