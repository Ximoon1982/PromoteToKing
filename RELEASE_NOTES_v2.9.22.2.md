# Promote to King v2.9.22.2

Focused OAuth saturation / Fresh Points Reconstruction throughput hotfix on the frozen v2.9.22.1 baseline.

## Fixes

- Prevents a small OAuth gateway batch from shrinking the remembered transport capacity. Server telemetry now separates real `transport_capacity` from `batch_size`.
- Keeps OAuth `processPriority()` logical feeder depth independent from physical gateway capacity; Fresh Points Reconstruction explicitly feeds up to 256 logical requests.
- Resets the browser OAuth tuning namespace to v4 and starts each gateway POST with an 8-connection bootstrap target. The server-side OAuthRateCoordinator remains the sole CPS authority.
- Reconstruction traffic now uses the background class so all five non-reserved gateway POST lanes are available while one P0 lane remains reserved for interactive requests.
- Coalesces reconstruction staging writes for up to 1 second (or 400 rows) with the existing 1,600-row high-water safety limit, reducing tiny same-origin persistence POSTs.
- Adds Logical feeder and Transport capacity metrics to Fresh Points Reconstruction diagnostics.
- Normalizes current HTML asset cache markers to v2.9.22.2, preventing stale v2.9.22/v2.9.22.1 API clients or site configuration from surviving a hotfix reload.

## Compatibility

- Core schema: 15 (unchanged)
- Analytics schema: 7 (unchanged)
- No database migration or reset
- Existing v2.9.22 CRON contract remains unchanged: four operational jobs plus one weekly backup job
- Protected runtime configuration is not included or modified by the incremental updater
