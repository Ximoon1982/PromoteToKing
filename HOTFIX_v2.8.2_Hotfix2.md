# v2.8.2 Hotfix 2 — Analytics refresh lock timeout

## Symptom

Team Insights and Opponent Insights could fail with:

`SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction`

This appeared after the v2.8.2 Analytics rebuild started doing achievement reconstruction inside the same transaction as Team/Opponent projections. Concurrent web requests could start the same rebuild at the same time and contend on the projection tables.

## Fix

- Team/Opponent Analytics refresh is now single-writer using a non-blocking filesystem lock.
- A concurrent request does not wait on InnoDB locks; it serves the last committed Analytics snapshot while the active refresh completes.
- The lock is double-checked after acquisition to avoid duplicate rebuilds.
- The general Insights source watermark is Core-only again, matching the v2.8.0 projection model.
- Live-rank and tournament changes no longer force Team/Opponent projection rebuilds.
- Achievement persistence has its own source watermark, refresh marker and lock.
- Achievement reconstruction is no longer executed inside the Team/Opponent Analytics transaction.
- The Hotfix 1 `p2k_an_match_facts` 25-column / 25-placeholder correction is retained.

## Database

No schema migration, initializer, seed reload or data repair is required. Core schema 4 and Analytics schema 4 remain unchanged.
