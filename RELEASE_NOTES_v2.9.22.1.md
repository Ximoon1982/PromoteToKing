<!--
SOURCE RECOVERY NOTICE
This documentation file was reconstructed on 2026-08-18 from authoritative
v2.9.22.1 freeze, audit, updater and checksum records after the original byte
stream became unavailable. It is not claimed to be byte-identical to the
historical RELEASE_NOTES_v2.9.22.1.md.
-->

# Promote to King v2.9.22.1

Focused Fresh Points Reconstruction throughput hotfix on the exact frozen
v2.9.22 baseline.

## Throughput correction

- Chess.com acquisition is decoupled from staging persistence so reconstruction
  workers can return to the shared acquisition feeder without waiting on every
  persistence operation.
- Staging persistence is batched at 400 rows with a 1,600-row high-water
  backpressure limit.
- Player archive fallback processes up to 24 fallback members concurrently.
- Archive discoveries are batched per monthly response instead of producing one
  staging wait per discovered match/game.
- Persistence is force-flushed before durable checkpoints, server work queries,
  phase transitions, review and apply.

OAuth request launch pacing and concurrency remain owned by the shared API
client/server rate coordinator; this hotfix changes the reconstruction pipeline
around that transport, not the transport authority itself.

## Diagnostics

Task Control exposes reconstruction/OAuth throughput observability including
OAuth mode, gateway launch rate, measured fetch-completion rate, learned safe
rate, gateway target/queue/active POSTs, and reconstruction persistence
backlog/batch counters.

## Compatibility

- VERSION: 2.9.22.1
- Core schema: 15 unchanged
- Analytics schema: 7 unchanged
- No database migration, reset or reseed
- Existing v2.9.22 operational and weekly CRON contract retained unchanged
- Existing staged reconstruction runs remain compatible
- Reconstruction staging/apply authority and queue-supersession semantics are
  unchanged
