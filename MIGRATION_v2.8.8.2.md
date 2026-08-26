# Promote to King v2.8.8.2 migration

## From v2.8.8.1 or v2.8.8

No schema migration is required.

- Core schema: **6**.
- Analytics schema: **5**.
- Do **not** reset/re-seed either database.
- Preserve production configuration and generated data directories.

v2.8.8.2 changes the public read path only: public Core-state metadata uses a SELECT-only helper and the first Dashboard historical totals use the existing materialized Analytics summary.

## Directly from v2.8.7

The additive Core 5 → 6 migration introduced by v2.8.8 still applies for opponent-club profile/logo caching. It remains an automatic admin/CRON schema upgrade; no reset/re-seed is required.

## CRON

No cadence change from v2.8.5 onward. No new v2.8.8.2 entry is required.
