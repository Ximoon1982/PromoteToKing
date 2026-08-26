# Promote to King v2.9.22.9

Focused reconciliation correctness/sorting and shared-host FastCGI headroom hotfix.

## Reconciliation
- Club differences are limited to missing matches, finished authoritative-result mismatches, or stored Club Points mismatches.
- Existing in-progress matches are not actionable merely because their live score changed.
- Club difference tables are server-side sortable by match, difference type, fresh finding, Core finding, and signed point delta.
- Club reconciliation reports full-set positive, negative, zero and net point impact and supports server-side impact filters: All / Adds points / Removes points / Zero-point changes.
- Negative corrections are first-class: sorting Point Δ ascending surfaces the largest point removals.
- Player reconciliation remains sortable by member/fresh/Core/point delta.

## Shared-host responsiveness
- OAuth/background gateway occupancy is capped at 2 same-origin PHP-FCGI POSTs while retaining the deep logical feeder and cURL-multi batching inside each POST.
- Team Points session/status/detail requests publish server-foreground pressure; new background gateway waves yield until the foreground request completes.
- Fresh reconstruction persistence and ACSR worker pulses remain background traffic.
- API-client runtime identity is bumped to 2.9.22.9 so older loaded shells cannot retain the pre-headroom scheduler.

## Compatibility
Core 16 and Analytics 7 are unchanged. No database migration, CRON change, scoring-formula change, achievement change, or queue redesign.
