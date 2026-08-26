# Promote to King v2.9.11 — Migration

There is **no database schema migration** in v2.9.11.

- Core schema remains 12.
- Analytics schema remains 6.
- Achievement catalogue remains 162.
- Existing protected OAuth, Team Points, shared server configuration, data and logs are preserved by the incremental updater.
- The four external CRON schedules are unchanged; only the numbered dispatcher/installer references advance to v2.9.11.

The release changes authenticated transport control from burst/concurrency-led probing to paced calls-per-second convergence with endpoint-class latency baselines.
