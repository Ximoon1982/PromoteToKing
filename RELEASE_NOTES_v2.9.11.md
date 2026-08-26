# Promote to King v2.9.11 — Release Notes

## Adaptive OAuth rate convergence

- Replaces burst-oriented authenticated concurrency growth with a paced calls-per-second controller.
- Starts at 8 calls/second and learns a stable safe operating rate from successful throughput, latency, transient errors, HTTP 429 and Retry-After feedback.
- Tracks the last proven-safe rate and the lowest observed unsafe/backlash boundary, then converges below that boundary rather than repeatedly overshooting it.
- Requires multiple clean samples before probing upward and retains a safety margin after backlash.
- Server-side cURL-multi launches are paced at the learned CPS target; concurrency is retained as latency headroom rather than used as a proxy for request rate.

## Match Creation Analyzer

- Removes the remaining effective bottleneck caused by comparing slow match-detail responses with unrelated fast club-index latency.
- Maintains independent latency baselines for club indexes, match details, rosters, club profiles, player profiles, stats, player-match indexes and archives.
- When slow endpoints need more in-flight capacity to sustain a known-safe rate, the connection cap grows without falsely reducing the safe CPS target.

## Diagnostics and shared maintenance

- Speed Fetch now reports the paced rate target, learned safe rate, backlash boundary and in-flight cap.
- Live Ranks and opponent maintenance Bearer bridges use the same learned rate budget.
- Logged-out and simulated OAuth (`oauth=1`) behavior remains serial.
- User OAuth tokens remain server-side and are not used by CRON.

## Compatibility

- Base release: v2.9.10.
- Core schema remains 12.
- Analytics schema remains 6.
- Achievement catalogue remains 162.
- No database migration and no CRON cadence change.
