# v2.9.20 corrective implementation audit

## MORCF
`live-ranks-admin.php` now captures `$requestedRate` in the MCA `process_step` fetcher closure and passes it to `OAuthSession::batchForAuthorizedRequest`.

## MIAC modal / pagination
Club Intelligence has a shared 25-row paginator for `ci-table` tables, a 25-row Members page size, and parent-viewport-aware MIAC evidence modal positioning for embedded operation.

## DMBHF
`dashboard-match-board-hydration.js` owns current-match detail hydration. Registered/ongoing rows are deduplicated by detail URL, scheduled via the shared priority client as background traffic, merged in place, and totaled from authoritative `boards` only.

## ACDM
The server control planner returns canonical debt, urgency, lane and adaptive pulse suggestions. Continuous refresh activates drain mode only when canonical debt exists, chains at most six pulses, requires productivity to continue, adapts quota and delay, and yields to foreground pressure.

All changes reuse existing authoritative workers, queue coalescing, shared OAuth/cache transport and P0 Interactive Survival controls.
