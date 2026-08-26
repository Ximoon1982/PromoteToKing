# Promote to King v2.8.10 installation

1. Back up the deployed web tree, both MariaDB databases, and the complete `data/` runtime directory.
2. Upload/extract the v2.8.10 standalone package over the site while preserving production `server/team-points/config/config.local.php` and existing runtime data.
3. Do **not** reset/reseed Core or Analytics. Core upgrades additively to schema 7; Analytics stays schema 5.
4. Run the argument-less CRON repair/install helper. It recovers an existing shared token when possible and generates a protected random token automatically on a clean/no-recovery installation:
   ```bash
   chmod +x reset-install-cron-v2.8.10.sh cron-dispatch-v2.8.10.sh
   ./reset-install-cron-v2.8.10.sh
   ```
5. Hard-refresh the browser once so v2.8.10 cache-busted assets are loaded.
6. Verify Dashboard startup/admin recognition remains as in working v2.8.9.
7. In Scheduled Task Control, verify shell invocation diagnostics for Club, Tournament, Player and Match Tracking. If a task is old, the UI should now distinguish absent shell invocation from endpoint error.
8. In Tournament Management, run a manual refresh. `Missing X-Club-Tools-Request header` must no longer occur, stored tournament display should recover from retained backup/cache if the old primary was accidentally emptied, and status maintenance should report a current 10-minute status-check timestamp.
9. In Match Tracking, verify the management panel appears for the `match-tracking` task, existing followed matches recover when backup/history exists, and Record now works.
10. Verify Opponent Intelligence against an opponent with known wins/draws/losses after the Core/Analytics maintenance pass; losses must reflect score-derived outcomes.
11. Verify Dashboard member count/all-history Finished KPIs remain unchanged while the automatic Chess.com current second wave updates registration/in-progress counts.
