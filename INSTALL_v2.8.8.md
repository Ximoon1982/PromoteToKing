# Promote to King Standalone v2.8.8 — upgrade/install

1. Back up the deployed website, Core MariaDB and Analytics MariaDB.
2. Upload/extract the v2.8.8 standalone tree over v2.8.7. Preserve production `server/team-points/config/config.local.php`, `data/server-config.json` and generated data directories.
3. **Do not reset or re-seed either database.** Core upgrades in place from schema 5 to **6**; Analytics remains schema 5. The normal Team Points upgrader applies `server/team-points/sql/core-migration-v2.8.8.sql`.
4. The CRON cadence is unchanged. Existing v2.8.7 entries remain valid. To deliberately delete the complete current SSH-user crontab and reinstall the standard P2K jobs from the existing stored tokens, run from the site root:

   ```bash
   chmod +x reset-install-cron-v2.8.8.sh
   ./reset-install-cron-v2.8.8.sh
   ```

5. Hard-refresh once after deployment so the v2.8.8 cache-busted JS/CSS load.
6. Verify Hall of Fame opens on Achievements, Daily/Live rank cards fit on mobile, and both Daily and Live Dashboard cards expose the unified profile action.
7. Open a unified player profile, open/close multiple achievement details from it, and confirm the parent profile remains interactive and on-screen.
8. Open Administration → Club Intelligence and verify Team depth, ACAMR, Freshness, Anomalies & actions, Members, Opponents, Time travel, Forecast and Performance views.
9. Verify Opponents Insights Top Opponents begins displaying Chess.com club logos as the long-lived cache fills.
10. Test ACAMR with simulated OAuth (`?oauth=1`): it must activate only with the simulated-OAuth flag. A verified future real-OAuth session must activate ACAMR regardless of that flag.
11. Verify Scheduled Tasks continue normally even with no authenticated browser open; ACAMR remains supplementary.
