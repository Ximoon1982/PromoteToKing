# Promote to King v2.10.5.4

Focused UI, monitoring and Green observability release built on the validated v2.10.5.3 finite-cycle fairness baseline.

## Opponent Insights — Zoned Density Heatmap

- Replaces the pixelated Balance Analyzer heat rendering with the agreed Zoned Density Heatmap.
- Uses approximately half-size bins, one light 3x3 Gaussian smoothing pass, and six discrete density zones rather than a blurred continuous surface.
- Zones are Very low (deep blue), Low (cyan), Medium (green), High (yellow), Very high (orange), and Peak (red), plus None.
- Boards-per-match smoothing remains in logarithmic x space.
- Equality/zero reference lines, wheel zoom, drag pan, shift-drag box zoom, pinch zoom and tooltips remain available.

## Dashboard Match Assistant

- Priority calls and Matches starting within 7 days now open a dedicated embedded Match Assistant frame with the requested preset encoded in the iframe URL.
- The hidden recommendation engine can no longer replace the user-opened filtered assistant while it is hydrating.
- Assistant state persists its filter in navigation history and removes stale URL anchors when opening, eliminating hash-scroll/dynamic-load races.

## Match monitoring

- Automatically league-followed Cron tracking now stops 24 hours after the match start time.
- Auto-stop only changes the follow state. Existing snapshot/history files are preserved.
- Manual follows are not auto-stopped; manually following an auto-stopped match makes it manual tracking again.
- The tracker records `autoStoppedAt` and `autoStopReason=started-over-24h` for auditability.

## Scheduled Task Control / Green migration UI

- Adds last completed Green cycle duration and average duration over the latest 10 completed cycles.
- Replaces the binary `quick_matches 0/1` display with the same explicitly estimated live-work progress model used by the migration diagnostics.
- Clarifies that the Green compatibility adapter is installed while GAB/read parity may still be pending.
- Improves responsive/mobile layout of Green routing, GAB, GFFL and Accelerator controls.
- Existing v2.10.5.3 finite-cycle fairness, GAB priority, GFFL and migration gates are preserved.

## Safety / compatibility

- No Green Core reseed.
- No database reset or destructive data operation.
- No database schema change.
- No CRON definition/cadence change.
- Green worker remains 50 s soft / 55 s hard.
- Public reads remain Blue by default until explicit validated Green cutover.
- Blue remains available for rollback and is not retired by this release.
- `assets/images` is unchanged from v2.10.5.3.
