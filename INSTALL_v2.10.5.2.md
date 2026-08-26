# Install Promote to King v2.10.5.2

Install v2.10.5.2 over v2.10.5.1 using the released cumulative corrective/updater from the PromoteToKing document root.

The updater verifies its payload, backs up overwritten/new files, applies the additive Green schema upgrade, preserves protected local configuration, and rolls source changes back on failure. It does not reseed Green Core, reset either Green database, or replace the existing five-entry Green CRON schedule.

## After installation

1. Hard-refresh the site and Administration pages.
2. Open **Administration → Scheduled Task Control → Green control**.
3. Confirm public reads are still **BLUE** and migration phase is the expected current phase.
4. Set/confirm `shadow_writing` while Blue remains the rollback source.
5. Start/resume **GAB**.
6. Start **Green Accelerator** while authenticated with real OAuth. P0 interactive traffic remains first; GAB receives first background priority.
7. Let GAB reach `ready`. Use its lane bars/errors plus GFFL/cycle telemetry to resolve any blocking item.
8. Run **Validate Green**. Current-match readiness is checked against the configured GFFL SLO (default 1,200 s).
9. When validation and read parity are ready, set `green_validated`.
10. Advance to `green_reads_both_writing`. All routed public Team Points displays now read Green while Blue remains maintained for rollback.
11. Exercise Dashboard, player profiles, achievements, Hall of Fame, matches/boards/statistics, opponent/team/member insights, league seasons, recent matches and Live Ranks/MCA.
12. If stable, advance to `green_primary`. Green becomes the normal read/write/background authority and legacy Blue browser ACAMR is disabled. Blue remains frozen/available for rollback; do not delete it yet.

The legacy TeamPointsMigration page is not required for these controls; the normal operational surface is Scheduled Task Control.
