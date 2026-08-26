# Fresh Points Reconstruction throughput audit — v2.9.22.1

## Root cause

v2.9.22 correctly routed fresh Chess.com requests through `P2K_API_CLIENT`, including the real-OAuth gateway. Throughput was nevertheless constrained above and below that transport:

1. reconstruction workers awaited frequent `reconstruction-ingest` requests before returning to the shared acquisition feeder;
2. Player archive fallback iterated fallback members serially;
3. monthly archive discovery could generate one staging wait per discovered match/game.

The transport could therefore advertise a learned rate around 20–30 requests/s while reconstruction itself advanced much more slowly.

## Hotfix contract

- Fetch and persistence pipelines are independent.
- Persistence buffer high-water: 1,600 rows.
- Persistence batch: 400 rows.
- Player archive member concurrency: 24.
- OAuth launch pacing/concurrency remains exclusively owned by the shared API client/server coordinator.
- Persistence is force-flushed before server work queries, durable checkpoints, phase transitions, review and apply.
- Existing staging/apply authority and queue-supersession semantics are unchanged.

## New diagnostics

Task Control exposes the difference between transport and reconstruction throughput: OAuth mode, gateway launch rate, measured fetch-completion rate, learned safe rate, gateway target/queue/active POSTs and reconstruction persistence backlog/batch counters.

## Deployment portability correction

The v2.9.22.1 updater performs no database migration and therefore does not require PHP CLI. Its incremental manifest and installed-payload checks are implemented with shell tooling and `sha256sum`, allowing deployment on IONOS shells where PHP is available to the web runtime but no CLI executable is exposed.
