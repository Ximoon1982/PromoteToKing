# Promote to King Standalone v2.8.8.2 — install / hotfix upgrade

1. Back up the deployed website and both MariaDB databases.
2. Upload/extract the v2.8.8.2 standalone tree over the current installation, preserving production `server/team-points/config/config.local.php`, `data/server-config.json`, generated runtime data, tournament archives and match-tracking data.
3. **Do not reset or re-seed either database.** Core remains schema 6 and Analytics remains schema 5 when upgrading from v2.8.8/v2.8.8.1.
4. Existing CRON entries remain valid. Reinstalling them is optional. The argument-less helper still deliberately replaces the complete current SSH-user crontab with the standard four P2K schedules:

   ```bash
   chmod +x reset-install-cron-v2.8.8.2.sh
   ./reset-install-cron-v2.8.8.2.sh
   ```

5. Hard-refresh once after deployment so the `2.8.8.2` cache-busted assets are used.
6. Open the Dashboard and confirm that secondary/current content begins loading without waiting for the first Team Points database payload.
7. Log in as a configured administrator and verify Administration is immediately available and its embedded tools open normally.
8. Verify the v2.8.8.1 retained fixes: nested Profile/Achievement modals, complete rank artwork, non-zero Opponent Intelligence losses where appropriate, and the Team Insights six-month Club Points progression.

The hotfix intentionally requires no new scheduler entry or manual Analytics rebuild. Existing worker logic performs any due materialization work normally.
