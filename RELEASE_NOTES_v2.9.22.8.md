# Promote to King v2.9.22.8

Focused Administration / Task Control page-load shedding hotfix.

## Fixed
- `reconstruction-status` snapshots are metadata-only and no longer execute reconciliation review/difference SQL.
- Fresh Reconstruction no longer requests server state merely because its JavaScript loaded; state is loaded only when the card is explicitly opened or used.
- Task Control initial/60-second bootstrap is status-only: selected task details, reconstruction synchronization, and unified logs are no longer automatically requested.
- Automatic Club/Player reconciliation polling is removed. `Refresh differences` remains explicit, while apply/finalize actions still refresh their affected data.
- Reconstruction initialization is idempotent.

## Compatibility
Core 16 and Analytics 7 are unchanged. No queue, CRON, OAuth, scoring, achievement, or acquisition-algorithm changes.
