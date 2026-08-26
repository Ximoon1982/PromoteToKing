# v2.8.3 → v2.8.4 migration

1. Back up the site and both MariaDB databases.
2. Upload/overwrite the complete v2.8.4 tree, preserving `server/team-points/config/config.local.php` and other production secrets/data.
3. **Do not reinitialize the databases.** Core stays schema 4; Analytics stays schema 5.
4. Replace the old Team Points CRON with two entries:
   - `cron-club.php` every 5 minutes.
   - `cron-player.php` every 30 minutes.
   Do not leave the old `cron.php` scheduled as well; it is a Club-lane compatibility alias.
5. Open Scheduled Tasks. Confirm separate **Club Points** and **Player Points** cards are present.
6. The first lane executions automatically adopt the new job types. The one-time v2.8.4 board recovery requeues eligible pre-fix failed boards; no phpMyAdmin edits are needed.
7. Open Team Insights / Opponent Insights and verify normal Analytics reads.
8. Run or wait for tournament refreshes so historical `finishAt` values can replace pending tournament-achievement dates.

If inactive members remain historically short after board recovery, use the Player lane reconciliation/explicit member-history repair rather than recreating the database.
