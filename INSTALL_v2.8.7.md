# Promote to King Standalone v2.8.7 — upgrade/install

1. Back up the deployed website, Core MariaDB and Analytics MariaDB.
2. Upload/extract the v2.8.7 standalone tree over v2.8.6. Preserve production `server/team-points/config/config.local.php`, `data/server-config.json` and generated data directories.
3. Do **not** reset or recreate either database. Core and Analytics remain schema 5; no SQL migration is required.
4. Existing v2.8.6 CRON entries remain valid. To deliberately delete the complete crontab of the current SSH user and reinstall the v2.8.7 P2K jobs using the tokens already stored in production configuration, run from the site root:

   ```bash
   chmod +x reset-install-cron-v2.8.7.sh
   ./reset-install-cron-v2.8.7.sh
   ```

5. Hard-refresh once after deployment so v2.8.7 cache-busted JS/CSS are loaded.
6. Verify normal Dashboard/Insights/Hall/Tournament behavior and one simulated OAuth login with `?oauth=1`.
7. With simulated login + OAuth flag, `window.P2K_ACAMR.status()` should report ACAMR active. Without the flag, simulated login must not activate ACAMR.
8. When real OAuth is later connected, a verified real-OAuth session must activate ACAMR regardless of the simulated-OAuth flag.
9. Verify Administration → Scheduled Tasks still shows normal Club/Player/Tournament/league CRON activity; ACAMR is supplementary and must not be required for queue progress.
