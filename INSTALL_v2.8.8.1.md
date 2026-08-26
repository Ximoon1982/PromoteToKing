# Promote to King Standalone v2.8.8.1 — hotfix upgrade/install

1. Back up the deployed website and both MariaDB databases.
2. Upload/extract the v2.8.8.1 standalone tree over v2.8.8, preserving production `server/team-points/config/config.local.php`, `data/server-config.json` and generated data directories.
3. **Do not reset or re-seed either database.** Core remains schema 6 and Analytics remains schema 5.
4. Existing CRON entries remain valid. Reinstallation is optional. If desired, the argument-less helper deliberately replaces the complete crontab of the current SSH user with the standard four P2K jobs:

   ```bash
   chmod +x reset-install-cron-v2.8.8.1.sh
   ./reset-install-cron-v2.8.8.1.sh
   ```

5. Hard-refresh once after deployment so the `2.8.8.1` cache-busted JS/CSS is used.
6. Verify Dashboard content becomes usable even if the first Team Points database response is delayed.
7. Log in as a configured administrator and confirm Administration becomes available immediately, then open several Administration tools to confirm embedded pages load.
8. Open a unified Profile, open/close multiple Achievements, and verify subsequent Achievements still load and the Profile remains interactive.
9. Verify Daily/Live rank images in Profile are fully visible at desktop and mobile widths.
10. In Opponent Intelligence, verify an opponent with known P2K losses shows non-zero losses after the next normal Analytics rebuild.
11. In Team Insights, verify **Club Points score progression** appears immediately above the year-over-year chart and extends from the first stored day to today + six months with Low/Medium/High projections.
