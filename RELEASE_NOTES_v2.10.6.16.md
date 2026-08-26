# Promote to King v2.10.6.16

v2.10.6.16 is a cumulative Green cycle-boundary and worker-runtime corrective from the canonical v2.10.6.15 source tree.

## Correctives

- Prevent the finite-cycle tail pass from crossing a completed cycle boundary. Work for the next Quick cycle can no longer run before that cycle is formally started/timed.
- Self-heal active Quick cycles already beyond `quick_boards` with no GQAC cohort by rewinding only that active cycle to `quick_boards`, creating its cohort, and resuming the normal sequence. No data reset or reseed is performed.
- Checkpoint terminal 404/410 profile and stats observations on periodic refreshes, not only when the previous checkpoint is NULL.
- Record real Quick Profiles and Quick Stats phase progress using current-member completed/due counts instead of binary `0 / 1` telemetry.
- Preserve detailed phase totals when a phase completes.
- Remove the synchronous analytics rebuild from `completeCycle()`, release the global `p2k_green_worker` lock before Green/compatibility analytics maintenance, and serialize analytics under its own lock.
- Restore full Green/compatibility analytics materialization to a 300-second minimum cadence (reverting the v2.10.6.11 30-second pressure); live per-object Green compatibility projection remains immediate.
- Treat post-lock analytics rebuild failures as maintenance telemetry rather than failing an already-successful finite Green worker.
- Add timing telemetry for finite cycle, finite tail, GAB, GFFL, current maintenance, Green analytics and compatibility analytics, plus core-lock runtime vs total request runtime.
- Clarify that historical cycle-duration averages before v2.10.6.16 may contain boundary-timing distortion and that Task Control displays UTC.

## Compatibility

- Public Dashboard, Hall of Fame and Insights DOM/layout remain regression-locked unchanged.
- No database schema change, database reset, reseed, CRON schedule change, Blue/Green routing change, scoring change or artwork change.
