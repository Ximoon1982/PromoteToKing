# Migration notes — v2.9.12 to v2.9.13

v2.9.13 performs an additive Core database migration.

- Core schema: 12 → 13.
- Analytics schema remains 6.
- Achievement catalogue remains 162.
- External four-task CRON cadence is unchanged.

Core 13 adds canonical outstanding-work identity, priority/generation/coalescing metadata and an active-only canonical uniqueness constraint to `p2k_tp_job_items`. The former raw `(job_id,item_type,item_key)` uniqueness constraint is removed because caller-specific keys no longer define work equivalence.

During upgrade, outstanding legacy queue rows are canonicalized. Equivalent pending/retry rows are coalesced and marked skipped while terminal history remains untouched. Running duplicates already executing are not forcibly killed. The updater verifies that no active legacy row with an empty canonical identity remains before reporting success.

The migration is idempotent. Regular housekeeping contains a bounded safety net for residual legacy active rows if an upgrade was interrupted.
