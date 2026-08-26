# Promote to King v2.9.5 installation / upgrade

For an existing v2.9.4 production installation, use the v2.9.4 → v2.9.5 incremental ZIP together with `update-v2.9.4-to-v2.9.5.sh` from the site root.

The updater backs up configuration, data, logs, crontab and every replaced file beneath `_backup` before applying the payload. It preserves `server/team-points/config/config.local.php`, `data/server-config.json`, mutable runtime data, and `config/oauth-test.php`.

After upgrade:
1. verify the site reports v2.9.5 and Core 10 / Analytics 6;
2. open Hall of Fame → Achievements and verify the catalogue reports 162 entries;
3. open Insights → Opponents and verify the single aggregate heatmap pair still loads;
4. optionally open `OAuthTest.php?oauth=2` to exercise the isolated OAuth POC;
5. verify Scheduled Tasks shows the normal four production CRON jobs.

No manual database reset or achievement reseed is required. Background achievement projection refresh will incorporate the new catalogue and progressively converge country/rating-dependent achievements as authoritative metadata becomes available.
