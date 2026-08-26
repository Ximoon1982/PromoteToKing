# P2K v2.9.12 transport, feeder, consumer and cache audit

## Scope

Audit covered the shared Chess.com API path and all known production consumers: Find Match, Upcoming Matches, Match Creation Analyzer, Challenge Assistant, Recruitment tools, Open Match analysis, Tournament Management, Task Control/Admin tools, dashboard/embedded panels, player profile/stats consumers and server-side OAuth maintenance bridges.

## Confirmed v2.9.11 bottlenecks

1. OAuth request timeout could expire while still waiting in the browser/gateway queue.
2. Serialized gateway waves created drain/refill bubbles on slow endpoints.
3. Completion-throughput telemetry mixed launch rate with response-tail latency.
4. Permanent 404/410 responses could be interpreted as transport pressure.
5. Rate authority was per browser document/frame rather than one live budget for the OAuth session.
6. The same 429 could be punished by multiple layers, creating deep recovery valleys.
7. IndexedDB persistence was awaited per response on the completion path.
8. Priority admission repeatedly rescanned the remaining queue, yielding O(N^2) feeder work.
9. Match Creation repeatedly rescanned stage populations and repainted progress per completion.
10. Old finished-match cache entries became stale after one day and generated background refresh traffic that could compete with foreground analysis.

## v2.9.12 corrections

- Server-shared launch coordinator keyed by a non-reversible hash of the server-held OAuth token.
- Hybrid PI/D pressure response plus hard 429 boundary and anti-windup/coalescing.
- Shared foreground hold; background work cannot reserve ahead of active foreground demand.
- Endpoint-class successful-latency baselines; 429 latency and permanent 4xx do not poison baselines.
- Six bounded gateway POST feeders, each subject to the same shared upstream launch clock.
- Network timeout begins at actual server upstream launch, not browser queue admission.
- Linear priority feeder after one stable sort; bulk cache warm-up.
- Batched IndexedDB writes and bulk reads.
- Finished-match cache entries remain reusable for the long retention window unless explicitly forced network-only.
- Match Creation uses constant-time pending counters and throttled visual progress rendering.

## Hard 50 requests/second upstream simulation

The current `OAuthRateCoordinator` was exercised across eight concurrent PHP requester processes against a real local HTTP service enforcing a rolling one-second cap of 50 arrivals.

Final frozen run:

- Attempts: 512
- Upstream arrivals: 512
- Accepted: 508
- HTTP 429: 4
- Deliberate HTTP 404: 32
- Peak rolling one-second arrivals: 52
- Backlash episodes: 2
- Settled rate: 47.6727 req/s
- Learned unsafe boundary: 51.8181 req/s
- Slowest worker elapsed: 17.926 s

The 404 responses did not create transport pressure. The controller converged below the cap after discovering the boundary and did not enter a late-run collapse.

## 700-match browser workload

Chromium exercised the real shared client and Match Creation page path with 700 detail requests. Gateway responses were deliberately slower than a 1-second browser request timeout to prove queue/gateway waiting no longer consumes that timeout.

Observed:

- 700 Match Creation details completed
- 33 gateway batches
- 6 maximum active gateway POSTs
- 0 direct Chess.com browser bypasses
- Settled mocked server-shared rate: 46.5 req/s
- 0 page errors

Cache phase:

- 200 finished-match keys requested
- 150 old finished-match cache hits
- 50 network misses
- 0 stale-background refresh competition for those finished matches

## Release invariants

Core 12 / Analytics 6; no schema migration. Simulated OAuth and logged-out paths remain serial. User OAuth tokens remain server-side and are not available to CRON/CLI.
