# Promote to King v2.9.22.5

Focused Task Control / diagnostics recovery hotfix built from the exact frozen v2.9.22.4 standalone.

## Fixes

- Scheduled Task Control bootstrap is now lightweight. The four server-backed task cards are returned before queue-heavy work reports are calculated.
- Selected server task work details are loaded lazily through `action=task-detail` with a dedicated 60-second detail budget.
- Club Points, Member Points, Match monitoring and Tournaments now have a visible unavailable fallback instead of disappearing when the server status request is slow.
- Fresh Reconstruction staging status is no longer calculated synchronously before the server task grid renders.
- `intelligence.php?scope=traffic` no longer opens Core or Analytics; traffic diagnostics are filesystem-backed and independent of database load.
- Runtime Diagnostics keeps the 8-second budget for lightweight endpoints but gives DB-backed Insights Health 20 seconds.
- Every HTML/HTM asset cache marker is normalized to exactly `2.9.22.5`; the accidental `2.9.22.4.4` release marker is removed.

## Compatibility

- Core schema 15 unchanged.
- Analytics schema 7 unchanged.
- Existing v2.9.22 CRON dispatcher/weekly-backup contract unchanged.
- Fresh Points Reconstruction scoring/acquisition logic unchanged from v2.9.22.4.
- OAuth/ACDM transport unchanged.
- No database migration or reseed.
