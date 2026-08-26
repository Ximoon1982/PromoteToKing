# Migration notes — v2.9.11 to v2.9.12

There is **no database schema migration** in v2.9.12.

- Core schema remains 12.
- Analytics schema remains 6.
- Achievement catalogue remains 162.
- External four-task CRON cadence is unchanged.

The incremental updater verifies the existing schema state but does not invoke upgrade/migration code. Runtime changes are limited to shared OAuth rate coordination, feeder/queue behavior, cache behavior, diagnostics and related tests/deployment scripts.
